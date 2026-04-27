<?php
defined( 'ABSPATH' ) || exit;

class LT_Media_Cleaner_Audit {
    public static function init() {
        add_action( 'lt_mc_verify_s3_url', [ __CLASS__, 'verify_s3_url' ], 10, 2 );
        add_action( 'lt_mc_audit_month_posts', [ __CLASS__, 'audit_month_posts' ], 10, 2 );
        add_action( 'admin_notices', [ __CLASS__, 'display_front_end_errors' ] );
    }

    public static function display_front_end_errors() {
        if ( get_option( 'lt_mc_front_end_errors' ) ) {
            echo '<div class="notice notice-error is-dismissible"><p><strong>LT Media Cleaner :</strong> Des images locales/cassées ont été détectées sur le front-end lors du dernier audit. <a href="'. esc_url( content_url( 'uploads/lt-media-cleaner-audit.log' ) ) .'" target="_blank">Consultez les logs</a>.</p></div>';
        }
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
                $file_info = pathinfo( $local_base_dir . $relative_path );
                $dir = $file_info['dirname'];
                $filename_no_ext = $file_info['filename'];
                
                // Nettoyage agressif : Recherche de tous les fichiers (originaux, webp, miniatures exotiques)
                $pattern = $dir . '/' . $filename_no_ext . '*.*';
                $files_to_delete = glob( $pattern );
                
                if ( $files_to_delete ) {
                    foreach ( $files_to_delete as $file ) {
                        if ( is_file( $file ) ) {
                            unlink( $file );
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

            // 4. Audit Front-End (HTML)
            $front_posts = $wpdb->get_results( $wpdb->prepare(
                "SELECT ID FROM $wpdb->posts WHERE post_status = 'publish' AND post_content LIKE %s LIMIT 100",
                '%' . $wpdb->esc_like( $year . '/' . $month ) . '%'
            ) );
            
            if ( ! empty( $front_posts ) ) {
                $log_file = WP_CONTENT_DIR . '/uploads/lt-media-cleaner-audit.log';
                foreach ( $front_posts as $p ) {
                    $permalink = get_permalink( $p->ID );
                    $response = wp_remote_get( $permalink );
                    
                    if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
                        $html = wp_remote_retrieve_body( $response );
                        // Recherche des attributs src pointant vers le dossier local
                        if ( preg_match( '/<img[^>]+src=["\']([^"\']*wp-content\/uploads\/[^"\']+)["\']/i', $html, $matches ) ) {
                            $log_message = sprintf( "[%s] ALERTE FRONT-END : Image locale (%s) trouvée sur %s\n", date('Y-m-d H:i:s'), $matches[1], $permalink );
                            error_log( $log_message, 3, $log_file );
                            update_option( 'lt_mc_front_end_errors', true );
                        }
                    }
                }
            }
        }
    }
}
