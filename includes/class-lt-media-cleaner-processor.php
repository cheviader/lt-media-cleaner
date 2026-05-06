<?php
defined( 'ABSPATH' ) || exit;

class LT_Media_Cleaner_Processor {

    public static function init() {
        add_action( 'lt_mc_process_image', [ __CLASS__, 'process_single' ] );
    }

    public static function process_single( $inventory_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_inventory_edito';
        
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d AND status IN ('local_verified', 'sideloaded')", $inventory_id ) );
        if ( ! $row ) return;

        $post_id = $row->post_id;
        $image_url = $row->image_url;
        $clean_url = strtok( $image_url, '?' );

        $attachment_id = attachment_url_to_postid( $clean_url );
        if ( ! $attachment_id ) {
            $base_filename = preg_replace('/(?:-\d+x\d+)?(?:-scaled)?(?:-rotated)?(\.[^.]+)$/i', '$1', basename( $clean_url ) );
            $attachment_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s", '%' . $base_filename ) );
        }

        if ( ! $attachment_id ) {
            $wpdb->update( $table, [ 'status' => 'error_not_found' ], [ 'id' => $inventory_id ] );
            return;
        }

        self::process_attachment( $attachment_id );

        $s3_url = wp_get_attachment_url( $attachment_id );
        if ( strpos( $s3_url, wp_upload_dir()['baseurl'] ) !== false ) {
            $wpdb->update( $table, [ 'status' => 'error_s3' ], [ 'id' => $inventory_id ] );
            return;
        }

        $post = get_post( $post_id );
        if ( $post ) {
            $new_url_with_params = $s3_url;
            $query_string = parse_url( $image_url, PHP_URL_QUERY );
            if ( $query_string ) {
                $new_url_with_params .= '?' . $query_string;
            }

            $filename_no_ext = pathinfo( parse_url( $image_url, PHP_URL_PATH ), PATHINFO_FILENAME );
            $filename_no_ext = preg_replace('/(?:-scaled|-rotated)$/i', '', $filename_no_ext);
            $filename_quoted = preg_quote( $filename_no_ext, '~' );
            
            $post_content = LT_Media_Cleaner_S3::apply_regex_replacements( $post->post_content, $attachment_id, $filename_quoted, $new_url_with_params );
            
            if ( $post_content !== $post->post_content ) {
                $table_backups = $wpdb->prefix . 'lt_inventory_backups';
                $wpdb->insert( $table_backups, [
                    'inventory_id' => $inventory_id,
                    'post_id'      => $post_id,
                    'old_content'  => $post->post_content
                ], [ '%d', '%d', '%s' ] );

                $wpdb->update( $wpdb->posts, [ 'post_content' => $post_content ], [ 'ID' => $post_id ] );
                clean_post_cache( $post_id );
            }
        }

        $wpdb->update( $table, [ 'status' => 'processed', 'image_url' => $s3_url ], [ 'id' => $inventory_id ] );
    }

    private static function process_attachment( $attachment_id ) {
        $log_file = WP_CONTENT_DIR . '/uploads/lt-media-cleaner-process.log';

        if ( get_post_meta( $attachment_id, '_lt_mc_s3_offloaded', true ) ) {
            return;
        }

        $file_path = get_attached_file( $attachment_id );
        if ( ! $file_path || ! file_exists( $file_path ) ) {
            return;
        }

        $upload_dir     = wp_upload_dir();
        $upload_basedir = $upload_dir['basedir'];

        $mime_type = get_post_mime_type( $attachment_id );
        $webp_path = $file_path;

        if ( $mime_type !== 'image/webp' ) {
            $old_metadata      = wp_get_attachment_metadata( $attachment_id );
            $old_attached_file = get_post_meta( $attachment_id, '_wp_attached_file', true );

            $editor = wp_get_image_editor( $file_path );
            if ( is_wp_error( $editor ) ) {
                error_log( sprintf( "[%s] ERREUR EDITOR ID %d: %s\n", date( 'Y-m-d H:i:s' ), $attachment_id, $editor->get_error_message() ), 3, $log_file );
                return;
            }

            $size = $editor->get_size();
            if ( $size['width'] > 800 ) {
                $editor->resize( 800, null, false );
            }

            $path_parts = pathinfo( $file_path );
            $webp_path  = $path_parts['dirname'] . '/' . $path_parts['filename'] . '.webp';

            $editor->set_quality( 80 );
            $saved = $editor->save( $webp_path, 'image/webp' );

            if ( is_wp_error( $saved ) ) {
                error_log( sprintf( "[%s] ERREUR WEBP ID %d: %s\n", date( 'Y-m-d H:i:s' ), $attachment_id, $saved->get_error_message() ), 3, $log_file );
                return;
            }

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

            global $wpdb;
            $wpdb->update( $wpdb->posts, [ 'post_mime_type' => 'image/webp' ], [ 'ID' => $attachment_id ] );

            $new_attached_file = preg_replace( '/\.[^.]+$/', '.webp', $old_attached_file );
            update_post_meta( $attachment_id, '_wp_attached_file', $new_attached_file );

            if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
                require_once ABSPATH . 'wp-admin/includes/image.php';
            }
            add_filter( 'advmo_should_offload_attachment', '__return_false' );
            $new_metadata = wp_generate_attachment_metadata( $attachment_id, $webp_path );
            wp_update_attachment_metadata( $attachment_id, $new_metadata );
            remove_filter( 'advmo_should_offload_attachment', '__return_false' );
        }

        try {
            LT_Media_Cleaner_S3::offload_to_s3( $attachment_id );
        } catch ( Exception $e ) {
            error_log( sprintf( "[%s] ERREUR S3 ID %d: %s\n", date( 'Y-m-d H:i:s' ), $attachment_id, $e->getMessage() ), 3, $log_file );
            return;
        }

        $s3_url = wp_get_attachment_url( $attachment_id );
        if ( strpos( $s3_url, $upload_dir['baseurl'] ) !== false ) {
            error_log( sprintf( "[%s] ERREUR S3 ID %d: URL toujours locale après offload\n", date( 'Y-m-d H:i:s' ), $attachment_id ), 3, $log_file );
            return;
        }

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

        update_post_meta( $attachment_id, '_lt_mc_s3_offloaded', true );
    }
}
