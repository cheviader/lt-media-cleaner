<?php
defined( 'ABSPATH' ) || exit;

class LT_Media_Cleaner_Backup {
    const BATCH_SIZE = 10;

    public static function init() {
        add_action( 'lt_mc_process_month', [ __CLASS__, 'process_month' ], 10, 2 );
        add_action( 'lt_mc_phase_import', [ __CLASS__, 'phase_import' ], 10, 2 );
        add_action( 'lt_mc_phase_process_batch', [ __CLASS__, 'phase_process_batch' ], 10, 3 );
        add_action( 'lt_mc_phase_audit', [ __CLASS__, 'phase_audit' ], 10, 2 );
    }

    /**
     * Phase 1 : Backup ZIP des fichiers locaux existants, puis planifier l'import.
     */
    public static function process_month( $year, $month ) {
        set_time_limit( 0 );
        wp_raise_memory_limit( 'image' );

        $uploads   = wp_get_upload_dir();
        $base_dir  = $uploads['basedir'];
        $month_dir = $base_dir . '/' . $year . '/' . $month;

        // Créer le répertoire de backups
        $backup_dir = $base_dir . '/lt-media-backups';
        if ( ! file_exists( $backup_dir ) ) {
            wp_mkdir_p( $backup_dir );
            file_put_contents( $backup_dir . '/.htaccess', "deny from all\n" );
            file_put_contents( $backup_dir . '/index.html', '' );
        }

        $zip_path = $backup_dir . '/' . $year . '-' . $month . '.zip';

        if ( is_dir( $month_dir ) && class_exists( 'ZipArchive' ) ) {
            $zip = new ZipArchive();
            if ( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) === true ) {
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator( $month_dir ),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ( $files as $file ) {
                    if ( ! $file->isDir() ) {
                        $file_path     = $file->getRealPath();
                        $relative_path = substr( $file_path, strlen( $month_dir ) + 1 );
                        $zip->addFile( $file_path, $relative_path );
                    }
                }
                $zip->close();
                error_log( "LT_MC: [Phase 1] Backup ZIP créé pour $year-$month." );
            } else {
                error_log( "LT_MC: [Phase 1] Erreur création ZIP pour $year-$month." );
            }
        } else {
            error_log( "LT_MC: [Phase 1] Aucun dossier local pour $year-$month, ZIP ignoré." );
        }

        // → Phase 2
        as_enqueue_async_action( 'lt_mc_phase_import', [ 'year' => $year, 'month' => $month ], 'lt_media_cleaner' );
    }

    /**
     * Phase 2 : Import synchrone des images WP.com pour chaque article du mois.
     */
    public static function phase_import( $year, $month ) {
        set_time_limit( 0 );
        wp_raise_memory_limit( 'image' );

        global $wpdb;
        $post_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM $wpdb->posts
             WHERE post_type IN ('post', 'page')
             AND post_status = 'publish'
             AND YEAR(post_date) = %s
             AND MONTH(post_date) = %s",
            $year, $month
        ) );

        $count = 0;
        if ( ! empty( $post_ids ) ) {
            foreach ( $post_ids as $post_id ) {
                LT_Media_Cleaner_Importer::import_external_images( (int) $post_id );
                $count++;
            }
        }

        error_log( "LT_MC: [Phase 2] Import terminé pour $year-$month. $count articles traités." );

        // → Phase 3
        as_enqueue_async_action( 'lt_mc_phase_process_batch', [ 'year' => $year, 'month' => $month, 'offset' => 0 ], 'lt_media_cleaner' );
    }

    /**
     * Phase 3 : Traitement par batch (WebP, S3, nettoyage) — chaîné.
     */
    public static function phase_process_batch( $year, $month, $offset ) {
        set_time_limit( 0 );
        wp_raise_memory_limit( 'image' );

        global $wpdb;
        $attachment_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM $wpdb->posts
             WHERE post_type = 'attachment'
             AND post_mime_type LIKE 'image/%%'
             AND YEAR(post_date) = %s
             AND MONTH(post_date) = %s
             ORDER BY ID ASC
             LIMIT %d OFFSET %d",
            $year, $month, self::BATCH_SIZE, $offset
        ) );

        if ( empty( $attachment_ids ) ) {
            error_log( "LT_MC: [Phase 3] Tous les attachments traités pour $year-$month." );
            // → Phase 4
            as_enqueue_async_action( 'lt_mc_phase_audit', [ 'year' => $year, 'month' => $month ], 'lt_media_cleaner' );
            return;
        }

        foreach ( $attachment_ids as $attachment_id ) {
            try {
                LT_Media_Cleaner_Processor::process_single_image( (int) $attachment_id );
            } catch ( Exception $e ) {
                error_log( "LT_MC: [Phase 3] Exception ID $attachment_id: " . $e->getMessage() );
            }
        }

        // Batch suivant
        as_enqueue_async_action(
            'lt_mc_phase_process_batch',
            [ 'year' => $year, 'month' => $month, 'offset' => $offset + self::BATCH_SIZE ],
            'lt_media_cleaner'
        );
    }

    /**
     * Phase 4 : Audit final.
     */
    public static function phase_audit( $year, $month ) {
        set_time_limit( 0 );
        LT_Media_Cleaner_Audit::audit_month( $year, $month );
        error_log( "LT_MC: [Phase 4] Pipeline terminé pour $year-$month." );
    }
}
