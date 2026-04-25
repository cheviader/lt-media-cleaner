<?php
defined( 'ABSPATH' ) || exit;

class LT_Media_Cleaner_Processor {
    public static function init() {
        add_action( 'lt_mc_process_image', [ __CLASS__, 'process_image' ], 10, 1 );
    }

    public static function process_image( $attachment_id ) {
        $file_path = get_attached_file( $attachment_id );
        if ( ! $file_path || ! file_exists( $file_path ) ) {
            return;
        }

        $mime_type = get_post_mime_type( $attachment_id );
        if ( $mime_type === 'image/webp' ) {
            do_action( 'lt_mc_image_ready_for_s3', $attachment_id, $file_path );
            return;
        }

        // Supprimer les miniatures locales
        $meta = wp_get_attachment_metadata( $attachment_id );
        if ( ! empty( $meta['sizes'] ) ) {
            $upload_dir = wp_upload_dir();
            $base_url = $upload_dir['basedir'] . '/' . dirname( $meta['file'] ) . '/';
            foreach ( $meta['sizes'] as $size => $size_info ) {
                $thumb_path = $base_url . $size_info['file'];
                if ( file_exists( $thumb_path ) ) {
                    unlink( $thumb_path );
                }
            }
        }
        
        $editor = wp_get_image_editor( $file_path );
        if ( is_wp_error( $editor ) ) {
            error_log( 'LT_MC: Erreur image_editor pour ID ' . $attachment_id . ' : ' . $editor->get_error_message() );
            return;
        }

        $size = $editor->get_size();
        if ( $size['width'] > 800 ) {
            $editor->resize( 800, null, false );
        }

        $path_parts = pathinfo( $file_path );
        $new_filename = $path_parts['filename'] . '.webp';
        $new_filepath = $path_parts['dirname'] . '/' . $new_filename;

        $editor->set_quality( 80 );
        $saved = $editor->save( $new_filepath, 'image/webp' );

        if ( is_wp_error( $saved ) ) {
            error_log( 'LT_MC: Erreur sauvegarde WebP pour ID ' . $attachment_id . ' : ' . $saved->get_error_message() );
            return;
        }

        global $wpdb;
        $wpdb->update( 
            $wpdb->posts, 
            [ 'post_mime_type' => 'image/webp' ], 
            [ 'ID' => $attachment_id ] 
        );

        $old_attached_file = get_post_meta( $attachment_id, '_wp_attached_file', true );
        $new_attached_file = preg_replace('/\.[^.]+$/', '.webp', $old_attached_file);

        $new_meta = [
            'width'  => $saved['width'],
            'height' => $saved['height'],
            'file'   => $new_attached_file,
            'sizes'  => []
        ];
        
        update_post_meta( $attachment_id, '_wp_attachment_metadata', $new_meta );
        update_attached_file( $attachment_id, $new_filepath );

        if ( $file_path !== $new_filepath && file_exists( $file_path ) ) {
            unlink( $file_path );
        }

        do_action( 'lt_mc_image_ready_for_s3', $attachment_id, $new_filepath );
    }
}
