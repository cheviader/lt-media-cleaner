<?php
defined( 'ABSPATH' ) || exit;

class LT_Media_Cleaner_Crosscheck {
    public static function init() {
        add_action( 'lt_mc_crosscheck_edito_single', [ __CLASS__, 'crosscheck_single' ] );
    }

    public static function crosscheck_single( $inventory_id ) {
        global $wpdb;
        $edito_table = $wpdb->prefix . 'lt_inventory_edito';
        $files_table = $wpdb->prefix . 'lt_inventory_files';
        
        // 1. Récupérer l'entrée Edito
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $edito_table WHERE id = %d AND status = 'pending'", $inventory_id ) );
        if ( ! $row ) return;

        // 2. Extraire le chemin relatif de l'URL
        $parsed_url = wp_parse_url( $row->image_url );
        $path = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '';
        $relative_path = preg_replace( '/^.*?\/wp-content\/uploads/i', '', $path );
        if ( $relative_path === $path ) {
            $relative_path = $path;
        }

        // 3. Chercher ce chemin dans la table des fichiers (via un LIKE sur la fin de chaîne)
        // Le chemin relatif commence par '/', ex: /2016/02/image.jpg
        $like_pattern = '%' . $wpdb->esc_like( $relative_path );
        
        $file_row = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM $files_table WHERE file_path LIKE %s LIMIT 1", $like_pattern ) );

        // 4. Mettre à jour les statuts croisés
        if ( $file_row ) {
            // Le fichier existe localement
            $wpdb->update( $edito_table, [ 'status' => 'local_verified' ], [ 'id' => $inventory_id ] );
            $wpdb->update( $files_table, [ 'status' => 'used' ], [ 'id' => $file_row->id ] );
        } else {
            // Le fichier est introuvable localement (donc distant ou manquant)
            $wpdb->update( $edito_table, [ 'status' => 'missing_local' ], [ 'id' => $inventory_id ] );
        }
    }
}
