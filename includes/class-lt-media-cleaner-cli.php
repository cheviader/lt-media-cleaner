<?php
defined( 'ABSPATH' ) || exit;

class LT_Media_Cleaner_CLI {
    public static function init() {
        WP_CLI::add_command( 'media-cleaner process', [ __CLASS__, 'process' ] );
    }

    public static function process( $args, $assoc_args ) {
        if ( empty( $assoc_args['period'] ) ) {
            WP_CLI::error( "Le paramètre --period est requis (YYYY ou YYYY-MM)." );
        }

        $period = $assoc_args['period'];

        if ( preg_match( '/^(\d{4})$/', $period, $matches ) ) {
            $year = $matches[1];
            for ( $month = 1; $month <= 12; $month++ ) {
                $month_str = str_pad( $month, 2, '0', STR_PAD_LEFT );
                as_enqueue_async_action( 'lt_mc_process_month', [ 'year' => $year, 'month' => $month_str ], 'lt_media_cleaner' );
            }
            WP_CLI::success( "Les 12 mois de l'année $year ont été planifiés." );
        } elseif ( preg_match( '/^(\d{4})-(\d{2})$/', $period, $matches ) ) {
            $year = $matches[1];
            $month = $matches[2];
            as_enqueue_async_action( 'lt_mc_process_month', [ 'year' => $year, 'month' => $month ], 'lt_media_cleaner' );
            WP_CLI::success( "Le mois $year-$month a été planifié." );
        } else {
            WP_CLI::error( "Format de --period invalide. Utilisez YYYY ou YYYY-MM." );
        }
    }
}
