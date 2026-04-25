<?php
/**
 * Plugin Name: LT Media Cleaner
 * Version: 0.1.0
 * Description: Clean, optimize, WebP encode and offload 13 years of media history.
 * Author: Lalutotale
 */

defined( 'ABSPATH' ) || exit;

define( 'LT_MC_VERSION', '0.1.0' );
define( 'LT_MC_DIR_PATH', plugin_dir_path( __FILE__ ) );

function lt_mc_check_dependencies() {
    if ( ! function_exists( 'as_enqueue_async_action' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="error"><p>LT Media Cleaner requiert Action Scheduler (ex: via WooCommerce) pour fonctionner.</p></div>';
        });
        return false;
    }
    return true;
}

add_action( 'plugins_loaded', function() {
    if ( ! lt_mc_check_dependencies() ) {
        return;
    }

    require_once LT_MC_DIR_PATH . 'includes/class-lt-media-cleaner-cli.php';
    require_once LT_MC_DIR_PATH . 'includes/class-lt-media-cleaner-backup.php';
    require_once LT_MC_DIR_PATH . 'includes/class-lt-media-cleaner-processor.php';
    require_once LT_MC_DIR_PATH . 'includes/class-lt-media-cleaner-s3.php';
    require_once LT_MC_DIR_PATH . 'includes/class-lt-media-cleaner-audit.php';

    if ( defined( 'WP_CLI' ) && WP_CLI ) {
        LT_Media_Cleaner_CLI::init();
    }
    LT_Media_Cleaner_Backup::init();
    LT_Media_Cleaner_Processor::init();
    LT_Media_Cleaner_S3::init();
    LT_Media_Cleaner_Audit::init();
} );
