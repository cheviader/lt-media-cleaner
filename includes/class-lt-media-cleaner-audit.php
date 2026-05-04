<?php
defined( 'ABSPATH' ) || exit;

class LT_Media_Cleaner_Audit {

    public static function init() {
        // Appelé directement par l'orchestrateur — pas de hook
    }

    /**
     * Extrait les images d'un contenu HTML en utilisant DOMDocument avec fallback Regex.
     * 
     * @param string $html_content
     * @return array Tableau contenant les URL et balises HTML complètes.
     */
    public static function extract_images( $html_content ) {
        $images = [];
        if ( empty( trim( $html_content ) ) ) {
            return $images;
        }

        libxml_use_internal_errors( true );
        $dom = new DOMDocument();
        
        // Ajout meta charset pour que DOMDocument lise correctement l'UTF-8 sans deprecation (PHP 8.2+)
        $html_wrapped = '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>' . $html_content . '</body></html>';
        
        // LoadHTML est parfois capricieux sur des fragments.
        $loaded = @$dom->loadHTML( $html_wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
        
        if ( $loaded ) {
            $img_tags = $dom->getElementsByTagName( 'img' );
            foreach ( $img_tags as $img ) {
                $src = $img->getAttribute( 'src' );
                if ( ! empty( $src ) ) {
                    // On recrée la balise brute pour info
                    $html_tag_raw = $dom->saveHTML( $img );
                    $images[] = [
                        'url'          => $src,
                        'html_tag_raw' => $html_tag_raw
                    ];
                }
            }
        } else {
            // Fallback Regex
            if ( preg_match_all( '/<img[^>]+src=[\'"]([^\'"]+)[\'"][^>]*>/i', $html_content, $matches, PREG_SET_ORDER ) ) {
                foreach ( $matches as $match ) {
                    $images[] = [
                        'url'          => $match[1],
                        'html_tag_raw' => $match[0]
                    ];
                }
            }
        }
        
        libxml_clear_errors();
        
        return $images;
    }

    /**
     * Audit final d'un mois : vérification S3 + scan URLs locales résiduelles.
     */
    public static function audit_month( $year, $month ) {
        global $wpdb;
        $upload_dir = wp_upload_dir();
        $log_file   = WP_CONTENT_DIR . '/uploads/lt-media-cleaner-audit-' . $year . '-' . $month . '.log';

        // Purger l'ancien log
        if ( file_exists( $log_file ) ) {
            @unlink( $log_file );
        }

        $has_errors = false;

        // 1. Vérifier que chaque attachment a une URL S3 valide (HTTP 200)
        $attachment_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM $wpdb->posts
             WHERE post_type = 'attachment'
             AND post_mime_type LIKE 'image/%%'
             AND YEAR(post_date) = %s
             AND MONTH(post_date) = %s",
            $year, $month
        ) );

        $s3_ok   = 0;
        $s3_fail = 0;

        foreach ( $attachment_ids as $att_id ) {
            $s3_url = wp_get_attachment_url( $att_id );

            // Vérifier que l'URL n'est pas locale
            if ( strpos( $s3_url, $upload_dir['baseurl'] ) !== false ) {
                $has_errors = true;
                $s3_fail++;
                error_log( sprintf( "[%s] ERREUR : ID %d URL toujours locale: %s\n", date( 'Y-m-d H:i:s' ), $att_id, $s3_url ), 3, $log_file );
                continue;
            }

            // Vérifier que l'URL S3 répond en 200
            $response = wp_remote_head( $s3_url, [ 'timeout' => 10 ] );
            $code     = wp_remote_retrieve_response_code( $response );

            if ( $code === 200 ) {
                $s3_ok++;
            } else {
                $has_errors = true;
                $s3_fail++;
                error_log( sprintf( "[%s] ERREUR : ID %d HTTP %d → %s\n", date( 'Y-m-d H:i:s' ), $att_id, $code, $s3_url ), 3, $log_file );
            }
        }

        // 2. Vérifier qu'aucune URL locale du mois ne subsiste dans la BDD
        $base_url     = $upload_dir['baseurl'] . '/' . $year . '/' . $month;
        $like_pattern = '%' . $wpdb->esc_like( $base_url ) . '%';

        $orphan_posts = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM $wpdb->posts WHERE post_status = 'publish' AND post_content LIKE %s",
            $like_pattern
        ) );

        if ( ! empty( $orphan_posts ) ) {
            $has_errors = true;
            $ids_str = implode( ', ', $orphan_posts );
            error_log( sprintf( "[%s] ERREUR : URLs locales résiduelles dans posts (IDs: %s)\n", date( 'Y-m-d H:i:s' ), $ids_str ), 3, $log_file );
        }

        $orphan_meta = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_value LIKE %s",
            $like_pattern
        ) );

        if ( $orphan_meta > 0 ) {
            $has_errors = true;
            error_log( sprintf( "[%s] ERREUR : %d entrées postmeta avec URLs locales\n", date( 'Y-m-d H:i:s' ), $orphan_meta ), 3, $log_file );
        }

        // 3. Résumé
        $total = count( $attachment_ids );
        if ( ! $has_errors ) {
            error_log( sprintf(
                "[%s] SUCCESS : Audit %s-%s terminé. %d/%d images S3 validées. Aucune URL locale résiduelle.\n",
                date( 'Y-m-d H:i:s' ), $year, $month, $s3_ok, $total
            ), 3, $log_file );
        } else {
            error_log( sprintf(
                "[%s] ATTENTION : Audit %s-%s terminé avec erreurs. S3 OK: %d, S3 FAIL: %d, Posts orphelins: %d\n",
                date( 'Y-m-d H:i:s' ), $year, $month, $s3_ok, $s3_fail, count( $orphan_posts )
            ), 3, $log_file );
        }
    }
}
