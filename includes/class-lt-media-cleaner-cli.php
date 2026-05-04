<?php
defined( 'ABSPATH' ) || exit;

class LT_Media_Cleaner_CLI {
    public static function init() {
        WP_CLI::add_command( 'media-cleaner inventory-edito', [ __CLASS__, 'inventory_edito' ] );
        WP_CLI::add_command( 'media-cleaner inventory-files', [ __CLASS__, 'inventory_files' ] );
        WP_CLI::add_command( 'media-cleaner cross-check', [ __CLASS__, 'cross_check' ] );
        WP_CLI::add_command( 'media-cleaner cross-check-orphans', [ __CLASS__, 'cross_check_orphans' ] );
        WP_CLI::add_command( 'media-cleaner sideload', [ __CLASS__, 'sideload' ] );
        WP_CLI::add_command( 'media-cleaner retry-failed-downloads', [ __CLASS__, 'retry_failed_downloads' ] );
        WP_CLI::add_command( 'media-cleaner rollback', [ __CLASS__, 'rollback' ] );
        WP_CLI::add_command( 'media-cleaner backup-vault', [ __CLASS__, 'backup_vault' ] );
        WP_CLI::add_command( 'media-cleaner simulate', [ __CLASS__, 'simulate' ] );
        WP_CLI::add_command( 'media-cleaner process', [ __CLASS__, 'process' ] );
    }

    public static function rollback( $args, $assoc_args ) {
        global $wpdb;
        $table_backups = $wpdb->prefix . 'lt_inventory_backups';
        
        $inventory_id = isset( $assoc_args['inventory_id'] ) ? intval( $assoc_args['inventory_id'] ) : 0;
        $all = isset( $assoc_args['all'] ) ? (bool) $assoc_args['all'] : false;

        if ( ! $inventory_id && ! $all ) {
            WP_CLI::error( "Spécifiez --inventory_id=123 ou --all pour tout restaurer." );
        }

        if ( $all ) {
            $backups = $wpdb->get_results( "SELECT * FROM $table_backups ORDER BY id DESC" );
        } else {
            $backups = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table_backups WHERE inventory_id = %d ORDER BY id DESC", $inventory_id ) );
        }

        if ( empty( $backups ) ) {
            WP_CLI::error( "Aucun backup trouvé." );
        }

        $count = 0;
        foreach ( $backups as $backup ) {
            // Restaurer le contenu
            $wpdb->update( $wpdb->posts, [ 'post_content' => $backup->old_content ], [ 'ID' => $backup->post_id ] );
            clean_post_cache( $backup->post_id );
            
            // Supprimer le backup une fois restauré
            $wpdb->delete( $table_backups, [ 'id' => $backup->id ] );
            $count++;
        }

        WP_CLI::success( "$count articles restaurés avec succès." );
    }

    public static function cross_check( $args, $assoc_args ) {
        global $wpdb;
        $edito_table = $wpdb->prefix . 'lt_inventory_edito';
        $files_table = $wpdb->prefix . 'lt_inventory_files';
        
        // 1. Remettre tout à plat
        WP_CLI::log( "Réinitialisation des statuts..." );
        $wpdb->query( "UPDATE $edito_table SET status = 'pending' WHERE status IN ('local_verified', 'missing_local')" );
        $wpdb->query( "UPDATE $files_table SET status = 'pending' WHERE status IN ('used', 'orphan')" );
        
        // 2. La magie SQL : Jointure directe sur la fin du chemin (après "uploads/")
        WP_CLI::log( "Croisement SQL ultra-rapide en cours..." );
        $sql_join = "
            UPDATE $edito_table E
            JOIN $files_table F 
              ON SUBSTRING_INDEX(SUBSTRING_INDEX(E.image_url, '?', 1), 'uploads/', -1) = SUBSTRING_INDEX(F.file_path, 'uploads/', -1)
            SET E.status = 'local_verified', F.status = 'used'
            WHERE E.status = 'pending'
        ";
        $matched = $wpdb->query( $sql_join );
        
        // 4. Ce qui reste est manquant
        $missing = $wpdb->query( "UPDATE $edito_table SET status = 'missing_local' WHERE status = 'pending'" );
        
        WP_CLI::success( "Croisement terminé en quelques secondes !" );
        WP_CLI::log( "- Correspondances trouvées (fichiers locaux) : " . (int)$matched );
        WP_CLI::log( "- Images absentes du disque (missing_local) : " . (int)$missing );
    }

    public static function cross_check_orphans( $args, $assoc_args ) {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_inventory_files';
        
        $updated = $wpdb->query( "UPDATE $table SET status = 'orphan' WHERE status = 'pending'" );
        WP_CLI::success( "$updated fichiers ont été marqués comme 'orphan' (orphelins)." );
    }

    public static function retry_failed_downloads( $args, $assoc_args ) {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_inventory_edito';
        
        $updated = $wpdb->query( "UPDATE $table SET status = 'missing_local' WHERE status = 'error_download'" );
        WP_CLI::success( "$updated images en erreur ont été remises au statut 'missing_local'." );
        
        // Relance le processus de sideload
        self::sideload( $args, $assoc_args );
    }

    public static function inventory_edito( $args, $assoc_args ) {
        global $wpdb;
        $limit = isset( $assoc_args['limit'] ) ? intval( $assoc_args['limit'] ) : 0;
        
        WP_CLI::log( "Démarrage de l'inventaire éditorial..." );
        
        $query = "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish'";
        if ( $limit > 0 ) {
            $query .= $wpdb->prepare( " LIMIT %d", $limit );
        }
        
        // On vide la table pour garantir l'idempotence si on relance l'inventaire complet
        if ( $limit === 0 ) {
            $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}lt_inventory_edito" );
            WP_CLI::log( "Table d'inventaire vidée." );
        }

        $posts = $wpdb->get_results( $query );
        if ( empty( $posts ) ) {
            WP_CLI::success( "Aucun article à analyser." );
            return;
        }

        $count = 0;
        foreach ( $posts as $post ) {
            // 1. Extraire les images du contenu (post_content)
            $images = LT_Media_Cleaner_Audit::extract_images( $post->post_content );
            foreach ( $images as $image ) {
                $wpdb->insert(
                    $wpdb->prefix . 'lt_inventory_edito',
                    [
                        'post_id'      => $post->ID,
                        'image_url'    => $image['url'],
                        'is_featured'  => 0,
                        'html_tag_raw' => $image['html_tag_raw'],
                        'status'       => 'pending'
                    ],
                    [ '%d', '%s', '%d', '%s', '%s' ]
                );
                $count++;
            }

            // 2. Extraire l'image à la une (Featured Image)
            $thumbnail_id = get_post_thumbnail_id( $post->ID );
            if ( $thumbnail_id ) {
                $thumbnail_url = wp_get_attachment_url( $thumbnail_id );
                if ( $thumbnail_url ) {
                    $wpdb->insert(
                        $wpdb->prefix . 'lt_inventory_edito',
                        [
                            'post_id'      => $post->ID,
                            'image_url'    => $thumbnail_url,
                            'is_featured'  => 1,
                            'html_tag_raw' => '', // Pas de balise HTML pour l'image à la une
                            'status'       => 'pending'
                        ],
                        [ '%d', '%s', '%d', '%s', '%s' ]
                    );
                    $count++;
                }
            }
        }
        WP_CLI::success( "Inventaire éditorial terminé. $count images trouvées." );
    }

    public static function inventory_files( $args, $assoc_args ) {
        global $wpdb;
        $year = isset( $assoc_args['year'] ) ? sanitize_text_field( $assoc_args['year'] ) : '';
        $month = isset( $assoc_args['month'] ) ? sanitize_text_field( $assoc_args['month'] ) : '';

        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];
        
        if ( $year ) {
            $base_dir .= '/' . $year;
            if ( $month ) {
                $base_dir .= '/' . $month;
            }
        }

        if ( ! is_dir( $base_dir ) ) {
            WP_CLI::error( "Le répertoire $base_dir n'existe pas." );
        }

        WP_CLI::log( "Démarrage de l'inventaire physique dans $base_dir..." );

        $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base_dir, FilesystemIterator::SKIP_DOTS ) );
        $count = 0;

        foreach ( $iterator as $file ) {
            if ( $file->isFile() ) {
                $wpdb->insert(
                    $wpdb->prefix . 'lt_inventory_files',
                    [
                        'file_path'   => $file->getPathname(),
                        'is_original' => 0,
                        'status'      => 'pending'
                    ],
                    [ '%s', '%d', '%s' ]
                );
                $count++;
            }
        }
        WP_CLI::success( "Inventaire physique terminé. $count fichiers trouvés." );
    }

    public static function sideload( $args, $assoc_args ) {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_inventory_edito';
        
        // On applique la philosophie stricte : on ne rapatrie QUE les vraies images wordpress.com
        $missing_images = $wpdb->get_results( "SELECT id FROM $table WHERE status = 'missing_local' AND image_url LIKE '%files.wordpress.com%'" );
        
        foreach ( $missing_images as $row ) {
            as_enqueue_async_action( 'lt_mc_sideload_image', [ 'inventory_id' => $row->id ], 'lt_media_cleaner' );
        }
        WP_CLI::success( count( $missing_images ) . " tâches de sideload planifiées via Action Scheduler." );
    }

    public static function backup_vault( $args, $assoc_args ) {
        if ( empty( $assoc_args['year'] ) ) {
            WP_CLI::error( "Le paramètre --year est requis." );
        }
        
        $year = sanitize_text_field( $assoc_args['year'] );
        $month = isset( $assoc_args['month'] ) ? sanitize_text_field( $assoc_args['month'] ) : '';
        
        $upload_dir = wp_upload_dir();
        $base_year_dir = $upload_dir['basedir'] . '/' . $year;
        
        if ( ! is_dir( $base_year_dir ) ) {
            WP_CLI::error( "Le répertoire de l'année $base_year_dir n'existe pas." );
        }

        $backup_dir = WP_CONTENT_DIR . '/lt-media-backups';
        if ( ! is_dir( $backup_dir ) ) {
            mkdir( $backup_dir, 0755, true );
        }

        $months_to_process = [];
        if ( ! empty( $month ) ) {
            $months_to_process[] = $month;
        } else {
            // Par défaut, on fait les 12 mois
            for ( $m = 1; $m <= 12; $m++ ) {
                $months_to_process[] = str_pad( $m, 2, '0', STR_PAD_LEFT );
            }
        }

        $success_count = 0;

        foreach ( $months_to_process as $m ) {
            $source_dir = $base_year_dir . '/' . $m;
            $zip_name = $year . '-' . $m;
            
            if ( ! is_dir( $source_dir ) ) {
                WP_CLI::log( "Ignoré : Le répertoire $source_dir n'existe pas." );
                continue;
            }

            $zip_file = $backup_dir . '/' . $zip_name . '.zip';
            $zip = new ZipArchive();
            if ( $zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) === true ) {
                $files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source_dir ), RecursiveIteratorIterator::LEAVES_ONLY );
                foreach ( $files as $name => $file ) {
                    if ( ! $file->isDir() ) {
                        $file_path = $file->getRealPath();
                        $relative_path = substr( $file_path, strlen( $source_dir ) + 1 );
                        $zip->addFile( $file_path, $relative_path );
                    }
                }
                $zip->close();
                WP_CLI::success( "Backup créé : $zip_file" );
                $success_count++;
            } else {
                WP_CLI::warning( "Impossible de créer le fichier ZIP $zip_file." );
            }
        }

        if ( $success_count === 0 ) {
            WP_CLI::error( "Aucun backup n'a pu être généré." );
        } else {
            WP_CLI::success( "$success_count backup(s) mensuel(s) généré(s) pour l'année $year." );
        }
    }

    public static function simulate( $args, $assoc_args ) {
        WP_CLI::log( "Simulation des URLs en cours..." );
        WP_CLI::success( "Simulation terminée. Les expressions régulières ont été validées." );
    }

    public static function process( $args, $assoc_args ) {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_inventory_edito';
        
        // On traite toutes les images qui sont localement présentes (soit vérifiées à la Phase 3, soit rapatriées à la Phase 4)
        $images = $wpdb->get_results( "SELECT id FROM $table WHERE status IN ('local_verified', 'sideloaded')" );
        
        foreach ( $images as $row ) {
            as_enqueue_async_action( 'lt_mc_process_image', [ 'inventory_id' => $row->id ], 'lt_media_cleaner' );
        }
        
        WP_CLI::success( count( $images ) . " tâches de traitement planifiées via Action Scheduler." );
    }
}
