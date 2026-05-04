<?php
defined( 'ABSPATH' ) || exit;

class LT_Media_Cleaner_Importer {
    public static function init() {
        add_action( 'lt_mc_sideload_image', [ __CLASS__, 'sideload_single' ] );
    }

    public static function sideload_single( $inventory_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_inventory_edito';
        
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d AND status = 'pending'", $inventory_id ) );
        if ( ! $row ) return;

        $post_id = $row->post_id;
        $full_url = $row->image_url;
        $clean_url = strtok( $full_url, '?' );

        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $post = get_post( $post_id );
        if ( ! $post ) {
            $wpdb->update( $table, [ 'status' => 'error_post_not_found' ], [ 'id' => $inventory_id ] );
            return;
        }

        $upload_dir = wp_upload_dir();
        $parsed_url = wp_parse_url( $clean_url );
        $path = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '';
        // Extraction du chemin relatif (ex: /2016/02/image.jpg)
        $relative_path = preg_replace( '/^.*?\/wp-content\/uploads/i', '', $path );
        if ( $relative_path === $path ) {
            // S'il n'y a pas wp-content/uploads dans l'URL
            $relative_path = $path; 
        }
        $local_file_path = $upload_dir['basedir'] . $relative_path;

        if ( file_exists( $local_file_path ) ) {
            // L'image est déjà sur le serveur local !
            $file = [
                'file' => $local_file_path,
                'url'  => $upload_dir['baseurl'] . $relative_path,
                'type' => wp_check_filetype( $local_file_path )['type']
            ];
            $tmp = null;
        } else {
            // L'image est absente localement, on la télécharge
            $tmp = download_url( $clean_url );
            
            // Fallbacks si 404 sur wordpress.com
            if ( is_wp_error( $tmp ) && strpos( $clean_url, 'wordpress.com' ) !== false ) {
                $fallback_url_1 = str_replace( '/wp-content/uploads/', '/', $clean_url );
                $tmp = download_url( $fallback_url_1 );
                
                if ( is_wp_error( $tmp ) ) {
                    $fallback_url_2 = str_replace( '.files.wordpress.com', '.com', $clean_url );
                    $tmp = download_url( $fallback_url_2 );
                }
            }

            if ( is_wp_error( $tmp ) ) {
                $wpdb->update( $table, [ 'status' => 'error_download' ], [ 'id' => $inventory_id ] );
                return;
            }

            $file_array = [
                'name'     => basename( $path ),
                'tmp_name' => $tmp
            ];

            $upload_dir_filter = function( $dirs ) use ( $post ) {
                $time = strtotime( $post->post_date );
                $subdir = '/' . date( 'Y', $time ) . '/' . date( 'm', $time );
                $dirs['subdir'] = $subdir;
                $dirs['path']   = $dirs['basedir'] . $subdir;
                $dirs['url']    = $dirs['baseurl'] . $subdir;
                return $dirs;
            };

            add_filter( 'upload_dir', $upload_dir_filter );
            $file = wp_handle_sideload( $file_array, [ 'test_form' => false ] );
            remove_filter( 'upload_dir', $upload_dir_filter );

            if ( isset( $file['error'] ) ) {
                @unlink( $tmp );
                $wpdb->update( $table, [ 'status' => 'error_sideload' ], [ 'id' => $inventory_id ] );
                return;
            }
        }

        $attachment = [
            'post_mime_type' => $file['type'],
            'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $file['file'] ) ),
            'post_content'   => '',
            'post_status'    => 'inherit',
            'post_date'      => $post->post_date,
            'post_date_gmt'  => $post->post_date_gmt
        ];

        // 1. Bypass propre de l'Offloader S3 pour ne pas envoyer les images natives (JPG/PNG) non optimisées
        add_filter( 'advmo_should_offload_attachment', '__return_false' );

        $attachment_id = wp_insert_attachment( $attachment, $file['file'], $post_id );
        
        if ( ! is_wp_error( $attachment_id ) && $attachment_id > 0 ) {
            // Génération des miniatures WordPress
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $attach_data = wp_generate_attachment_metadata( $attachment_id, $file['file'] );
            wp_update_attachment_metadata( $attachment_id, $attach_data );

            // On retire le filtre pour ne pas impacter le reste de la prod
            remove_filter( 'advmo_should_offload_attachment', '__return_false' );

            update_post_meta( $attachment_id, '_wp_attached_file', _wp_relative_upload_path( $file['file'] ) );
            
            $new_local_url = wp_get_attachment_url( $attachment_id );
            
            if ( isset( $row->is_featured ) && $row->is_featured == 1 ) {
                // C'est une image à la une, on met juste à jour la meta
                set_post_thumbnail( $post_id, $attachment_id );
            } else {
                // 2. Sauvegarde pour le Rollback avant modification
                $table_backups = $wpdb->prefix . 'lt_inventory_backups';
                $wpdb->insert( $table_backups, [
                    'inventory_id' => $inventory_id,
                    'post_id'      => $post_id,
                    'old_content'  => $post->post_content
                ], [ '%d', '%d', '%s' ] );

                // Remplacement intelligent dans le contenu :
                // On utilise la base de l'URL pour ne pas être bloqué par les paramètres "&amp;" encodés en base de données.
                $base_full_url = strtok( $full_url, '?' );
                $base_new_url  = strtok( $new_local_url, '?' );
                
                $post_content = str_replace( $base_full_url, $base_new_url, $post->post_content );
                
                // Au cas où l'URL exacte ou échappée serait présente
                $post_content = str_replace( $full_url, $new_local_url, $post_content );
                $post_content = str_replace( esc_url( $full_url ), $new_local_url, $post_content );
                
                $wpdb->update( $wpdb->posts, [ 'post_content' => $post_content ], [ 'ID' => $post_id ] );
            }
            
            clean_post_cache( $post_id );
            
            $wpdb->update( $table, [ 'status' => 'sideloaded', 'image_url' => $new_local_url ], [ 'id' => $inventory_id ] );
        } else {
            $wpdb->update( $table, [ 'status' => 'error_attachment' ], [ 'id' => $inventory_id ] );
        }
    }
}
