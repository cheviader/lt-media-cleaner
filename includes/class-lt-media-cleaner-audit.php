<?php
defined( 'ABSPATH' ) || exit;

class LT_Media_Cleaner_Audit {
    public static function init() {
        add_action( 'lt_mc_verify_s3_url', [ __CLASS__, 'verify_s3_url' ], 10, 2 );
        add_action( 'lt_mc_audit_month_posts', [ __CLASS__, 'audit_month_posts' ], 10, 2 );
    }

    public static function verify_s3_url( $attachment_id, $s3_url ) {
        if ( empty( $s3_url ) ) return;

        $response = wp_remote_head( $s3_url );
        $code = wp_remote_retrieve_response_code( $response );

        if ( $code === 200 ) {
            error_log( "LT_MC: [SUCCESS] S3 URL valide pour ID $attachment_id -> $s3_url" );
        } else {
            error_log( "LT_MC: [ERROR] S3 URL brisée pour ID $attachment_id -> $s3_url (Code: $code)" );
        }
    }

    public static function audit_month_posts( $year, $month ) {
        global $wpdb;
        $upload_dir = wp_upload_dir();
        $base_url = $upload_dir['baseurl'] . '/' . $year . '/' . $month;

        $posts = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID, post_title, post_content FROM $wpdb->posts WHERE post_status = 'publish' AND post_content LIKE %s",
            '%' . $wpdb->esc_like( $base_url ) . '%'
        ) );

        if ( ! empty( $posts ) ) {
            foreach ( $posts as $p ) {
                error_log( "LT_MC: [WARNING] URL locale orpheline détectée dans le post ID {$p->ID} ({$p->post_title})" );
            }
        } else {
            error_log( "LT_MC: [SUCCESS] Audit terminé pour $year-$month : Aucune URL locale résiduelle trouvée dans les posts publiés." );
        }
    }
}
