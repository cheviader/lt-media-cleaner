<?php
defined( 'ABSPATH' ) || exit;

class LT_Media_Cleaner_S3 {
    public static function init() {
        add_action( 'lt_mc_image_ready_for_s3', [ __CLASS__, 'offload_image' ], 10, 2 );
    }

    public static function offload_image( $attachment_id, $new_filepath ) {
        $s3_ok = false;

        // 1. Déclencher l'offload via Advanced Media Offloader
        if ( function_exists( 'advmo' ) ) {
            $advmo = advmo();
            $cloud_provider = $advmo->container->get('cloud_provider');
            if ( $cloud_provider ) {
                $uploader = new \Advanced_Media_Offloader\Services\CloudAttachmentUploader( $cloud_provider );
                add_filter( 'advmo_local_deletion_rule', '__return_zero' );
                $uploader->uploadAttachment( $attachment_id );
                $s3_ok = true;
            } else {
                throw new Exception( "LT_MC: Cloud provider ADVMO non trouvé." );
            }
        } else {
            throw new Exception( "LT_MC: Plugin Advanced Media Offloader inactif." );
        }

        // 2. Remplacer physiquement les URLs dans les contenus
        global $wpdb;
        $upload_dir = wp_upload_dir();
        $s3_url = wp_get_attachment_url( $attachment_id );

        // On s'assure que l'URL retournée par WP n'est plus locale
        if ( $s3_ok && strpos( $s3_url, $upload_dir['baseurl'] ) === false ) {
            $path_info = pathinfo( $new_filepath );
            $base_name = $path_info['filename'];
            
            self::replace_content_urls( $attachment_id, $base_name, $s3_url );
        }

        // 3. Planifier l'audit pour cette image précise
        as_enqueue_async_action('lt_mc_verify_s3_url', [ 'attachment_id' => $attachment_id, 's3_url' => $s3_url ], 'lt_media_cleaner_images');
    }

    private static function replace_content_urls( $id, $filename_no_ext, $new_url ) {
        global $wpdb;
        
        $posts = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID, post_content FROM {$wpdb->posts} 
             WHERE post_content LIKE %s OR post_content LIKE %s",
            '%wp-image-' . $id . '%',
            '%' . $wpdb->esc_like( basename( $filename_no_ext ) ) . '%'
        ) );
        
        $filename_quoted = preg_quote( basename( $filename_no_ext ), '~' );
        
        foreach ( $posts as $p ) {
            $content = $p->post_content;
            
            // 1. Pattern : toute URL HTTP/HTTPS se terminant par le nom du fichier + taille optionnelle + extension
            $pattern_url = '~https?://[^\s"\'<>\\\\]+/' . $filename_quoted . '(?:-\d+x\d+)?\.(jpg|jpeg|png|gif|webp)~i';
            $content = preg_replace( $pattern_url, $new_url, $content );
            
            // 2. Pattern : URLs échappées en JSON (Gutenberg)
            $new_url_esc = str_replace( '/', '\/', $new_url );
            $pattern_esc = '~https?:\\\\/\\\\/[^\s"\'<>\\\\]+\\\/' . $filename_quoted . '(?:-\d+x\d+)?\.(jpg|jpeg|png|gif|webp)~i';
            $content = preg_replace( $pattern_esc, $new_url_esc, $content );

            // 3. Correction des chemins relatifs stricts (commençant par /wp-content/)
            $pattern_rel = '~/wp-content/[^\s"\'<>\\\\]+/' . $filename_quoted . '(?:-\d+x\d+)?\.(jpg|jpeg|png|gif|webp)~i';
            $content = preg_replace( $pattern_rel, $new_url, $content );
            
            // 4. Correction Gutenberg wp:image (JSON "url")
            $content = preg_replace(
                '/(<!\-\-\s*wp:image\s*.*?)"url"\s*:\s*"[^"]+".*?(-->)/ism',
                '$1"url":"' . $new_url . '"$2',
                $content
            );
            
            // 5. Suppression de l'attribut srcset (devenu invalide)
            $content = preg_replace(
                '/(<img[^>]+wp-image-' . $id . '[^>]+)srcset="[^"]*"/i',
                '$1',
                $content
            );
            
            if ( $content !== $p->post_content ) {
                $wpdb->update( $wpdb->posts, 
                    [ 'post_content' => $content ], 
                    [ 'ID' => $p->ID ]
                );
            }
        }
    }
}
