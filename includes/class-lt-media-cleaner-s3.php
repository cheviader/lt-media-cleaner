<?php
defined( 'ABSPATH' ) || exit;

class LT_Media_Cleaner_S3 {

    public static function init() {
        // Appelé directement par le Processor — pas de hook
    }

    /**
     * Upload un attachment vers S3 via Advanced Media Offloader.
     */
    public static function offload_to_s3( $attachment_id ) {
        if ( ! function_exists( 'advmo' ) ) {
            throw new Exception( "LT_MC: Plugin Advanced Media Offloader inactif." );
        }

        $advmo          = advmo();
        $cloud_provider = $advmo->container->get( 'cloud_provider' );

        if ( ! $cloud_provider ) {
            throw new Exception( "LT_MC: Cloud provider ADVMO non trouvé." );
        }

        $uploader = new \Advanced_Media_Offloader\Services\CloudAttachmentUploader( $cloud_provider );
        add_filter( 'advmo_local_deletion_rule', '__return_zero' );
        $uploader->uploadAttachment( $attachment_id );
    }

    /**
     * Remplacer toutes les URLs locales d'un attachment dans la BDD.
     */
    public static function replace_content_urls( $id, $filename_no_ext, $new_url ) {
        global $wpdb;

        $filename_quoted = preg_quote( $filename_no_ext, '~' );
        $like_id         = '%wp-image-' . $id . '%';
        $like_name       = '%' . $wpdb->esc_like( $filename_no_ext ) . '%';

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

    /**
     * Regex de remplacement d'URLs dans une chaîne.
     */
    public static function apply_regex_replacements( $string, $id, $filename_quoted, $new_url ) {
        if ( ! is_string( $string ) || empty( $string ) ) {
            return $string;
        }

        // 1. URLs HTTP/HTTPS complètes
        $pattern_url = '~https?://[^\s"\'<>\\\\]+/' . $filename_quoted . '(?:-\d+x\d+)?\.(jpg|jpeg|png|gif|webp)~i';
        $string = preg_replace( $pattern_url, $new_url, $string );

        // 2. URLs échappées JSON (Gutenberg)
        $new_url_esc = str_replace( '/', '\/', $new_url );
        $pattern_esc = '~https?:\\\\/\\\\/[^\s"\'<>\\\\]+\\\\/' . $filename_quoted . '(?:-\d+x\d+)?\.(jpg|jpeg|png|gif|webp)~i';
        $string = preg_replace( $pattern_esc, $new_url_esc, $string );

        // 3. Chemins relatifs (/wp-content/)
        $pattern_rel = '~/wp-content/[^\s"\'<>\\\\]+/' . $filename_quoted . '(?:-\d+x\d+)?\.(jpg|jpeg|png|gif|webp)~i';
        $string = preg_replace( $pattern_rel, $new_url, $string );

        // 4. Suppression srcset (devenu invalide)
        $string = preg_replace(
            '/(<img[^>]+wp-image-' . $id . '[^>]+)srcset="[^"]*"/i',
            '$1',
            $string
        );

        return $string;
    }

    /**
     * Remplacement récursif dans les structures sérialisées.
     */
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
