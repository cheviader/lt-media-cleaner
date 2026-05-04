<?php
defined( 'ABSPATH' ) || exit;

class LT_Media_Cleaner_Backup {
    public static function init() {
        add_action( 'lt_mc_process_month', [ __CLASS__, 'process_month' ], 10, 2 );
    }

    public static function process_month( $year, $month ) {
        set_time_limit(0);
        wp_raise_memory_limit('image');

        $uploads = wp_get_upload_dir();
        $base_dir = $uploads['basedir'];
        
        $month_dir = $base_dir . '/' . $year . '/' . $month;
        if ( ! is_dir( $month_dir ) ) {
            error_log( "LT_MC: Le dossier $month_dir n'existe pas. Passage au mois suivant." );
            return;
        }

        $backup_dir = $base_dir . '/lt-media-backups';
        if ( ! file_exists( $backup_dir ) ) {
            wp_mkdir_p( $backup_dir );
            file_put_contents($backup_dir . '/.htaccess', "deny from all\n");
            file_put_contents($backup_dir . '/index.html', "");
        }

        $zip_path = $backup_dir . '/' . $year . '-' . $month . '.zip';
        
        // Création du ZIP
        if ( class_exists('ZipArchive') ) {
            $zip = new ZipArchive();
            if ( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) === true ) {
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator( $month_dir ),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );

                foreach ( $files as $name => $file ) {
                    if ( ! $file->isDir() ) {
                        $file_path = $file->getRealPath();
                        $relative_path = substr( $file_path, strlen( $month_dir ) + 1 );
                        $zip->addFile( $file_path, $relative_path );
                    }
                }
                $zip->close();
                error_log("LT_MC: Backup ZIP créé avec succès pour $year-$month.");
            } else {
                error_log("LT_MC: Erreur lors de la création du ZIP pour $year-$month.");
                return; // Stopper la cascade
            }
        } else {
            error_log("LT_MC: L'extension ZipArchive est manquante sur ce serveur.");
            return;
        }

        // Planifier les traitements d'image
        global $wpdb;
        $attachments = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%%' AND YEAR(post_date) = %s AND MONTH(post_date) = %s",
            $year, $month
        ) );

        if ( ! empty( $attachments ) ) {
            foreach ( $attachments as $attachment_id ) {
                as_enqueue_async_action( 'lt_mc_process_image', [ 'attachment_id' => $attachment_id ], 'lt_media_cleaner_images' );
            }
        }

        // Planifier l'audit global du mois
        as_enqueue_async_action( 'lt_mc_audit_month_posts', [ 'year' => $year, 'month' => $month ], 'lt_media_cleaner_images' );
    }
}
