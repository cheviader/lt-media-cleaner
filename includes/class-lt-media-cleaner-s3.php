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
        
        $filename_quoted = preg_quote( basename( $filename_no_ext ), '~' );
        $like_id = '%wp-image-' . $id . '%';
        $like_name = '%' . $wpdb->esc_like( basename( $filename_no_ext ) ) . '%';

        // 1. wp_posts (post_content)
        $posts = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID, post_content FROM {$wpdb->posts} 
             WHERE post_content LIKE %s OR post_content LIKE %s",
            $like_id, $like_name
        ) );
        
        foreach ( $posts as $p ) {
            $content = self::apply_regex_replacements( $p->post_content, $id, $filename_quoted, $new_url );
            if ( $content !== $p->post_content ) {
                $wpdb->update( $wpdb->posts, [ 'post_content' => $content ], [ 'ID' => $p->ID ] );
            }
        }

        // 2. wp_postmeta (meta_value)
        $metas = $wpdb->get_results( $wpdb->prepare(
            "SELECT meta_id, meta_value FROM {$wpdb->postmeta} 
             WHERE meta_value LIKE %s OR meta_value LIKE %s",
            $like_id, $like_name
        ) );
        
        foreach ( $metas as $m ) {
            $unserialized = maybe_unserialize( $m->meta_value );
            $updated_data = self::recursive_url_replace( $unserialized, $id, $filename_quoted, $new_url );
            if ( $updated_data !== $unserialized ) {
                $serialized = maybe_serialize( $updated_data );
                $wpdb->update( $wpdb->postmeta, [ 'meta_value' => $serialized ], [ 'meta_id' => $m->meta_id ] );
            }
        }

        // 3. wp_options (option_value)
        $options = $wpdb->get_results( $wpdb->prepare(
            "SELECT option_id, option_name, option_value FROM {$wpdb->options} 
             WHERE option_value LIKE %s OR option_value LIKE %s",
            $like_id, $like_name
        ) );
        
        foreach ( $options as $opt ) {
            if ( strpos( $opt->option_name, '_transient_' ) !== false ) {
                continue;
            }
            $unserialized = maybe_unserialize( $opt->option_value );
            $updated_data = self::recursive_url_replace( $unserialized, $id, $filename_quoted, $new_url );
            if ( $updated_data !== $unserialized ) {
                $serialized = maybe_serialize( $updated_data );
                $wpdb->update( $wpdb->options, [ 'option_value' => $serialized ], [ 'option_id' => $opt->option_id ] );
            }
        }
    }

    public static function apply_regex_replacements( $string, $id, $filename_quoted, $new_url ) {
        if ( ! is_string( $string ) || empty( $string ) ) {
            return $string;
        }

        // 1. Pattern : toute URL HTTP/HTTPS se terminant par le nom du fichier + taille optionnelle + extension
        $pattern_url = '~https?://[^\s"\'<>\\\\]+/' . $filename_quoted . '(?:-\d+x\d+)?\.(jpg|jpeg|png|gif|webp)~i';
        $string = preg_replace( $pattern_url, $new_url, $string );
        
        // 2. Pattern : URLs échappées en JSON (Gutenberg)
        $new_url_esc = str_replace( '/', '\/', $new_url );
        $pattern_esc = '~https?:\\\\/\\\\/[^\s"\'<>\\\\]+\\\/' . $filename_quoted . '(?:-\d+x\d+)?\.(jpg|jpeg|png|gif|webp)~i';
        $string = preg_replace( $pattern_esc, $new_url_esc, $string );

        // 3. Correction des chemins relatifs stricts (commençant par /wp-content/)
        $pattern_rel = '~/wp-content/[^\s"\'<>\\\\]+/' . $filename_quoted . '(?:-\d+x\d+)?\.(jpg|jpeg|png|gif|webp)~i';
        $string = preg_replace( $pattern_rel, $new_url, $string );
        
        // 4. Correction Gutenberg wp:image (JSON "url")
        $string = preg_replace(
            '/(<!\-\-\s*wp:image\s*.*?)"url"\s*:\s*"[^"]+".*?(-->)/ism',
            '$1"url":"' . $new_url . '"$2',
            $string
        );
        
        // 5. Suppression de l'attribut srcset (devenu invalide)
        $string = preg_replace(
            '/(<img[^>]+wp-image-' . $id . '[^>]+)srcset="[^"]*"/i',
            '$1',
            $string
        );

        return $string;
    }

    public static function recursive_url_replace( $data, $id, $filename_quoted, $new_url ) {
        if ( is_string( $data ) ) {
            return self::apply_regex_replacements( $data, $id, $filename_quoted, $new_url );
        } elseif ( is_array( $data ) ) {
            $new_data = [];
            foreach ( $data as $key => $value ) {
                $new_data[ $key ] = self::recursive_url_replace( $value, $id, $filename_quoted, $new_url );
            }
            return $new_data;
        } elseif ( is_object( $data ) ) {
            $new_data = clone $data;
            foreach ( get_object_vars( $data ) as $key => $value ) {
                $new_data->$key = self::recursive_url_replace( $value, $id, $filename_quoted, $new_url );
            }
            return $new_data;
        }
        return $data;
    }
}
