<?php
defined( 'ABSPATH' ) || exit;

class LT_Media_Cleaner_Audit {
    public static function init() {
        add_action( 'lt_mc_verify_s3_url', [ __CLASS__, 'verify_s3_url' ], 10, 2 );
        add_action( 'lt_mc_audit_month_posts', [ __CLASS__, 'audit_month_posts' ], 10, 2 );
    }

    public static function verify_s3_url( $attachment_id, $s3_url ) {
        if ( empty( $s3_url ) ) {
            throw new Exception("LT_MC: L'URL S3 est vide pour l'image ID $attachment_id.");
        }

        $response = wp_remote_head( $s3_url );
        $code = wp_remote_retrieve_response_code( $response );

        if ( $code === 200 ) {
            error_log( "LT_MC: [SUCCESS] S3 URL valide pour ID $attachment_id -> $s3_url" );

            // Nettoyage local du WebP et de ses miniatures maintenant que S3 est validé
            // Contournement des filtres du plugin S3 en récupérant le chemin brut
            $relative_path = get_post_meta( $attachment_id, '_wp_attached_file', true );
            
            if ( $relative_path ) {
                $local_base_dir = WP_CONTENT_DIR . '/uploads/';
                $local_file_path = $local_base_dir . $relative_path;

                if ( file_exists( $local_file_path ) ) {
                    unlink( $local_file_path );
                }
                
                // Contournement des filtres pour les métadonnées
                $meta = get_post_meta( $attachment_id, '_wp_attachment_metadata', true );
                if ( ! empty( $meta['sizes'] ) && ! empty( $meta['file'] ) ) {
                    $local_folder = $local_base_dir . rtrim( dirname( $meta['file'] ), '/' ) . '/';
                    foreach ( $meta['sizes'] as $size => $size_info ) {
                        $thumb_path = $local_folder . $size_info['file'];
                        if ( file_exists( $thumb_path ) ) {
                            unlink( $thumb_path );
                        }
                    }
                }
            }
        } else {
            throw new Exception("LT_MC: [ERROR] Échec QA : HTTP $code pour $s3_url (ID: $attachment_id)");
        }
    }

    public static function audit_month_posts( $year, $month ) {
        global $wpdb;
        $upload_dir = wp_upload_dir();
        $base_url = $upload_dir['baseurl'] . '/' . $year . '/' . $month;
        $like_pattern = '%' . $wpdb->esc_like( $base_url ) . '%';

        $errors = [];

        // 1. Audit wp_posts
        $posts = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID FROM $wpdb->posts WHERE post_status = 'publish' AND post_content LIKE %s",
            $like_pattern
        ) );
        if ( ! empty( $posts ) ) {
            $orphan_ids = implode(', ', array_map(function($p) { return $p->ID; }, $posts));
            $errors[] = "posts (IDs: $orphan_ids)";
        }

        // 2. Audit wp_postmeta
        $postmetas = $wpdb->get_results( $wpdb->prepare(
            "SELECT post_id, meta_key FROM $wpdb->postmeta WHERE meta_value LIKE %s",
            $like_pattern
        ) );
        if ( ! empty( $postmetas ) ) {
            $meta_details = array_map(function($m) { return $m->post_id . ':' . $m->meta_key; }, $postmetas);
            $meta_str = implode(', ', array_unique($meta_details));
            $errors[] = "postmeta (Post:Key -> $meta_str)";
        }

        // 3. Audit wp_options
        $options = $wpdb->get_results( $wpdb->prepare(
            "SELECT option_name FROM $wpdb->options WHERE option_value LIKE %s AND option_name NOT LIKE %s",
            $like_pattern,
            '%_transient_%'
        ) );
        if ( ! empty( $options ) ) {
            $option_names = implode(', ', array_map(function($o) { return $o->option_name; }, $options));
            $errors[] = "options ($option_names)";
        }

        if ( ! empty( $errors ) ) {
            $error_message = "LT_MC: [ERROR] URLs locales orphelines détectées dans : " . implode( " | ", $errors );
            throw new Exception( $error_message );
        } else {
            error_log( "LT_MC: [SUCCESS] Audit exhaustif terminé pour $year-$month : Aucune URL locale résiduelle trouvée." );
        }
    }
}
