<?php
defined( 'ABSPATH' ) || exit;

class LT_Media_Cleaner_Importer {
    public static function init() {
        add_action( 'lt_mc_import_external_images_for_post', [ __CLASS__, 'import_external_images' ], 10, 1 );
    }

    public static function import_external_images( $post_id ) {
        set_time_limit(0);
        
        $post = get_post( $post_id );
        if ( ! $post ) return;

        // Chercher toutes les images .files.wordpress.com dans src ou href
        if ( ! preg_match_all( '/["\'](https?:\/\/[^"\']+\.files\.wordpress\.com\/[^"\']+\.(?:jpg|jpeg|png|gif|webp)[^"\']*)["\']/i', $post->post_content, $matches ) ) {
            return;
        }

        // On élimine les doublons au cas où une image est dans un href et un src
        $urls_to_import = array_unique( $matches[1] );

        // Trier les URLs par longueur décroissante pour éviter qu'un str_replace sur une URL courte
        // ne corrompe une URL longue (ex: ?w=714)
        usort( $urls_to_import, function($a, $b) {
            return strlen($b) - strlen($a);
        } );

        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $content_updated = false;

        // Filtre pour forcer l'upload_dir à correspondre à la date de l'article
        $upload_dir_filter = function( $dirs ) use ( $post ) {
            $time = strtotime( $post->post_date );
            $subdir = '/' . date( 'Y', $time ) . '/' . date( 'm', $time );
            $dirs['subdir'] = $subdir;
            $dirs['path']   = $dirs['basedir'] . $subdir;
            $dirs['url']    = $dirs['baseurl'] . $subdir;
            return $dirs;
        };

        add_filter( 'upload_dir', $upload_dir_filter );

        foreach ( $urls_to_import as $full_url ) {
            // L'URL de téléchargement ne doit pas avoir de paramètres (ex: ?w=300)
            $clean_url = strtok( $full_url, '?' );

            // Si le fichier a déjà été remplacé dans une passe précédente
            if ( strpos( $post->post_content, $full_url ) === false ) continue;

            // Vérifier si cette image wp.com a DÉJÀ été importée dans le passé
            global $wpdb;
            $existing_attachment_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_lt_mc_original_url' AND meta_value = %s LIMIT 1",
                $clean_url
            ) );

            if ( $existing_attachment_id ) {
                $attachment_id = $existing_attachment_id;
                $new_local_url = wp_get_attachment_url( $attachment_id );
                
                if ( $new_local_url ) {
                    // Conserver les paramètres originaux de l'URL dans le src, mais remplacer la base
                    // Toutefois, pour S3, on préfère souvent nettoyer le ?w= à l'avenir.
                    $new_url_with_params = $new_local_url;
                    $query_string = parse_url( $full_url, PHP_URL_QUERY );
                    if ( $query_string ) {
                        $new_url_with_params .= '?' . $query_string;
                    }

                    $post->post_content = str_replace( $full_url, $new_url_with_params, $post->post_content );
                    $content_updated = true;
                    error_log( "LT_MC Importer: Réutilisation ID $attachment_id pour $clean_url -> $new_url_with_params" );
                }
                continue;
            }

            // Télécharger manuellement pour éviter la génération automatique des miniatures JPG et l'upload ADVMO
            $tmp = download_url( $clean_url );
            if ( is_wp_error( $tmp ) ) {
                error_log( "LT_MC Importer: Erreur download $clean_url : " . $tmp->get_error_message() );
                continue;
            }

            $file_array = [
                'name'     => basename( wp_parse_url( $clean_url, PHP_URL_PATH ) ),
                'tmp_name' => $tmp
            ];

            $file = wp_handle_sideload( $file_array, [ 'test_form' => false ] );

            if ( isset( $file['error'] ) ) {
                @unlink( $tmp );
                error_log( "LT_MC Importer: Erreur sideload $clean_url : " . $file['error'] );
                continue;
            }

            $attachment = [
                'post_mime_type' => $file['type'],
                'post_title'     => preg_replace( '/\.[^.]+$/', '', basename( $file['file'] ) ),
                'post_content'   => '',
                'post_status'    => 'inherit',
                'post_date'      => $post->post_date,
                'post_date_gmt'  => $post->post_date_gmt
            ];

            $attachment_id = wp_insert_attachment( $attachment, $file['file'], $post_id );

            if ( ! is_wp_error( $attachment_id ) && $attachment_id > 0 ) {
                // Sauvegarder l'URL d'origine pour empêcher les futurs doublons
                update_post_meta( $attachment_id, '_lt_mc_original_url', $clean_url );

                // On met quand même à jour _wp_attached_file (requis par WP)
                update_post_meta( $attachment_id, '_wp_attached_file', _wp_relative_upload_path( $file['file'] ) );
                
                $new_local_url = wp_get_attachment_url( $attachment_id );
                if ( $new_local_url ) {
                    // 1. Ajouter l'image originale fraîchement téléchargée au ZIP de backup
                    $file_path = get_attached_file( $attachment_id );
                    $year = date( 'Y', strtotime( $post->post_date ) );
                    $month = date( 'm', strtotime( $post->post_date ) );
                    $uploads = wp_get_upload_dir();
                    $zip_path = $uploads['basedir'] . '/lt-media-backups/' . $year . '-' . $month . '.zip';
                    
                    if ( file_exists( $zip_path ) && class_exists('ZipArchive') ) {
                        $zip = new ZipArchive();
                        if ( $zip->open( $zip_path ) === true ) {
                            $month_dir = $uploads['basedir'] . '/' . $year . '/' . $month;
                            $relative_path = substr( $file_path, strlen( $month_dir ) + 1 );
                            $zip->addFile( $file_path, $relative_path );
                            $zip->close();
                            error_log( "LT_MC Importer: Fichier $relative_path ajouté au backup ZIP." );
                        }
                    }

                    // 2. Remplacement strict de l'URL wp.com par l'URL locale toute fraîche
                    // On conserve le ?w= si présent dans l'URL d'origine
                    $new_url_with_params = $new_local_url;
                    $query_string = parse_url( $full_url, PHP_URL_QUERY );
                    if ( $query_string ) {
                        $new_url_with_params .= '?' . $query_string;
                    }

                    $post->post_content = str_replace( $full_url, $new_url_with_params, $post->post_content );
                    $content_updated = true;

                    // Le traitement WebP/S3 sera planifié par l'orchestrateur après la fin de tous les imports

                    error_log( "LT_MC Importer: Succès $clean_url -> $new_local_url" );
                }
            } else {
                error_log( "LT_MC Importer: Echec $clean_url : " . $attachment_id->get_error_message() );
            }
        }

        remove_filter( 'upload_dir', $upload_dir_filter );

        // Sauvegarde silencieuse en BDD
        if ( $content_updated ) {
            global $wpdb;
            $wpdb->update( $wpdb->posts, [ 'post_content' => $post->post_content ], [ 'ID' => $post_id ] );
            clean_post_cache( $post_id );
        }
    }
}
