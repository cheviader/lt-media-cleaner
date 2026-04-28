<?php
defined( 'ABSPATH' ) || exit;

class LT_Media_Cleaner_Processor {

    public static function init() {
        // Appelé directement par l'orchestrateur — pas de hook
    }

    /**
     * Pipeline complet pour une image :
     * 1. Idempotence → 2. Fichier → 3. WebP → 4. Ménage 1/2 →
     * 5. S3 → 6. URLs BDD → 7. Ménage 2/2 → 8. Marquage
     */
    public static function process_single_image( $attachment_id ) {
        $log_file = WP_CONTENT_DIR . '/uploads/lt-media-cleaner-process.log';

        // 1. Idempotence
        if ( get_post_meta( $attachment_id, '_lt_mc_s3_offloaded', true ) ) {
            return;
        }

        // 2. Récupérer le fichier (local ou sideload)
        $file_path = get_attached_file( $attachment_id );
        $tmp_file  = null;

        if ( ! $file_path || ! file_exists( $file_path ) ) {
            $url = wp_get_attachment_url( $attachment_id );
            if ( $url && preg_match( '/^https?:\/\//i', $url ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                $tmp_file = download_url( $url );
                if ( is_wp_error( $tmp_file ) ) {
                    error_log( sprintf( "[%s] ERREUR SIDELOAD ID %d: %s\n", date( 'Y-m-d H:i:s' ), $attachment_id, $tmp_file->get_error_message() ), 3, $log_file );
                    return;
                }
                $file_path = $tmp_file;
            } else {
                return; // Pas de fichier, pas d'URL
            }
        }

        $upload_dir     = wp_upload_dir();
        $upload_basedir = $upload_dir['basedir'];

        // 3. Conversion WebP (si pas déjà webp)
        $mime_type = get_post_mime_type( $attachment_id );
        $webp_path = $file_path;

        if ( $mime_type !== 'image/webp' ) {
            // Sauvegarder les anciennes métadonnées AVANT modification
            $old_metadata      = wp_get_attachment_metadata( $attachment_id );
            $old_attached_file = get_post_meta( $attachment_id, '_wp_attached_file', true );

            $editor = wp_get_image_editor( $file_path );
            if ( is_wp_error( $editor ) ) {
                error_log( sprintf( "[%s] ERREUR EDITOR ID %d: %s\n", date( 'Y-m-d H:i:s' ), $attachment_id, $editor->get_error_message() ), 3, $log_file );
                if ( $tmp_file && file_exists( $tmp_file ) ) {
                    @unlink( $tmp_file );
                }
                return;
            }

            $size = $editor->get_size();
            if ( $size['width'] > 800 ) {
                $editor->resize( 800, null, false );
            }

            // Déterminer le chemin de sortie WebP dans le dossier uploads
            if ( $tmp_file ) {
                // Sideloading : sauvegarder dans le dossier uploads, pas dans /tmp
                $target_dir = $upload_basedir . '/' . dirname( $old_attached_file );
                if ( ! is_dir( $target_dir ) ) {
                    wp_mkdir_p( $target_dir );
                }
                $webp_path = $target_dir . '/' . pathinfo( $old_attached_file, PATHINFO_FILENAME ) . '.webp';
            } else {
                $path_parts = pathinfo( $file_path );
                $webp_path  = $path_parts['dirname'] . '/' . $path_parts['filename'] . '.webp';
            }

            $editor->set_quality( 80 );
            $saved = $editor->save( $webp_path, 'image/webp' );

            if ( is_wp_error( $saved ) ) {
                error_log( sprintf( "[%s] ERREUR WEBP ID %d: %s\n", date( 'Y-m-d H:i:s' ), $attachment_id, $saved->get_error_message() ), 3, $log_file );
                if ( $tmp_file && file_exists( $tmp_file ) ) {
                    @unlink( $tmp_file );
                }
                return;
            }

            // Nettoyage fichier temporaire sideloading
            if ( $tmp_file && file_exists( $tmp_file ) ) {
                @unlink( $tmp_file );
            }

            // 4. Ménage 1/2 : supprimer l'original + anciennes miniatures
            if ( $old_attached_file ) {
                $old_main = $upload_basedir . '/' . $old_attached_file;
                if ( file_exists( $old_main ) ) {
                    @unlink( $old_main );
                }
            }
            if ( ! empty( $old_metadata['sizes'] ) && $old_attached_file ) {
                $old_dir = dirname( $upload_basedir . '/' . $old_attached_file );
                foreach ( $old_metadata['sizes'] as $size_data ) {
                    $thumb = $old_dir . '/' . $size_data['file'];
                    if ( file_exists( $thumb ) ) {
                        @unlink( $thumb );
                    }
                }
            }

            // Mettre à jour les métadonnées WP
            global $wpdb;
            $wpdb->update( $wpdb->posts, [ 'post_mime_type' => 'image/webp' ], [ 'ID' => $attachment_id ] );

            $new_attached_file = preg_replace( '/\.[^.]+$/', '.webp', $old_attached_file );
            update_post_meta( $attachment_id, '_wp_attached_file', $new_attached_file );

            if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
            }
            $new_metadata = wp_generate_attachment_metadata( $attachment_id, $webp_path );
            wp_update_attachment_metadata( $attachment_id, $new_metadata );
        }

        // 5. Offload S3
        try {
            LT_Media_Cleaner_S3::offload_to_s3( $attachment_id );
        } catch ( Exception $e ) {
            error_log( sprintf( "[%s] ERREUR S3 ID %d: %s\n", date( 'Y-m-d H:i:s' ), $attachment_id, $e->getMessage() ), 3, $log_file );
            return;
        }

        // 6. Vérifier que l'URL est bien S3
        $s3_url = wp_get_attachment_url( $attachment_id );
        if ( strpos( $s3_url, $upload_dir['baseurl'] ) !== false ) {
            error_log( sprintf( "[%s] ERREUR S3 ID %d: URL toujours locale après offload\n", date( 'Y-m-d H:i:s' ), $attachment_id ), 3, $log_file );
            return;
        }

        // 7. Remplacement URLs dans BDD (local → S3)
        $base_name = pathinfo( $webp_path, PATHINFO_FILENAME );
        LT_Media_Cleaner_S3::replace_content_urls( $attachment_id, $base_name, $s3_url );

        // 8. Ménage 2/2 : supprimer WebP local + miniatures WebP
        $attached_file = get_post_meta( $attachment_id, '_wp_attached_file', true );
        if ( $attached_file ) {
            $main_file = $upload_basedir . '/' . $attached_file;
            if ( file_exists( $main_file ) ) {
                @unlink( $main_file );
            }

            $current_metadata = wp_get_attachment_metadata( $attachment_id );
            if ( ! empty( $current_metadata['sizes'] ) ) {
                $webp_dir = dirname( $main_file );
                foreach ( $current_metadata['sizes'] as $size_data ) {
                    $thumb_file = $webp_dir . '/' . $size_data['file'];
                    if ( file_exists( $thumb_file ) ) {
                        @unlink( $thumb_file );
                    }
                }
            }
        }

        // 9. Marquer comme traité
        update_post_meta( $attachment_id, '_lt_mc_s3_offloaded', true );

        error_log( sprintf( "[%s] SUCCESS ID %d → %s\n", date( 'Y-m-d H:i:s' ), $attachment_id, $s3_url ), 3, $log_file );
    }
}
