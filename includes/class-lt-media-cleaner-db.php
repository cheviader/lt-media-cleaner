<?php
defined( 'ABSPATH' ) || exit;

class LT_Media_Cleaner_DB {

    public static function install() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        
        $table_edito = $wpdb->prefix . 'lt_inventory_edito';
        $table_files = $wpdb->prefix . 'lt_inventory_files';

        $sql_edito = "CREATE TABLE $table_edito (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            image_url text NOT NULL,
            is_featured tinyint(1) NOT NULL DEFAULT 0,
            html_tag_raw text NOT NULL,
            status varchar(50) NOT NULL DEFAULT 'pending',
            PRIMARY KEY  (id),
            KEY post_id (post_id)
        ) $charset_collate;";

        $sql_files = "CREATE TABLE $table_files (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            file_path text NOT NULL,
            is_original tinyint(1) NOT NULL DEFAULT 0,
            status varchar(50) NOT NULL DEFAULT 'pending',
            PRIMARY KEY  (id),
            KEY file_path (file_path(191))
        ) $charset_collate;";

        $table_backups = $wpdb->prefix . 'lt_inventory_backups';
        $sql_backups = "CREATE TABLE $table_backups (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            inventory_id bigint(20) NOT NULL,
            post_id bigint(20) NOT NULL,
            old_content longtext NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY inventory_id (inventory_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_edito );
        dbDelta( $sql_files );
        dbDelta( $sql_backups );
    }
}
