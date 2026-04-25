<?php
defined( 'ABSPATH' ) || exit;

class LT_Media_Cleaner_S3 {
    public static function init() {
        add_action( 'lt_mc_image_ready_for_s3', [ __CLASS__, 'offload_image' ], 10, 2 );
    }

    public static function offload_image( $attachment_id, $new_filepath ) {
        // 1. Déclencher l'offload via Advanced Media Offloader
        // Si Advanced Media Offloader ne le fait pas automatiquement via wp_update_attachment_metadata ou update_attached_file
        // Nous l'appellerons ici lors du test sur Staging si nécessaire.
        
        // 2. Remplacer physiquement les URLs dans les contenus
        global $wpdb;
        $upload_dir = wp_upload_dir();
        $s3_url = wp_get_attachment_url( $attachment_id );
        
        // Sécurité : ne remplacer que si on a bien une URL différente (S3) et non l'URL locale classique
        if ( strpos( $s3_url, $upload_dir['baseurl'] ) === false ) {
            
            $path_info = pathinfo( $new_filepath );
            $base_name = $path_info['filename'];
            $attached_file = get_post_meta( $attachment_id, '_wp_attached_file', true );
            
            // Reconstruire l'URL locale de base
            $local_url_base = $upload_dir['baseurl'] . '/' . dirname( $attached_file ) . '/' . $base_name;
            $local_url_base = str_replace('/./', '/', $local_url_base); // Au cas où dirname est '.'
            
            // On remplace les vieilles URLs JPG et PNG dans post_content
            $wpdb->query( $wpdb->prepare( 
                "UPDATE $wpdb->posts SET post_content = REPLACE(post_content, %s, %s)",
                $local_url_base . '.jpg', $s3_url
            ) );
            
            $wpdb->query( $wpdb->prepare( 
                "UPDATE $wpdb->posts SET post_content = REPLACE(post_content, %s, %s)",
                $local_url_base . '.png', $s3_url
            ) );
            
            // Et potentiellement WebP (si on l'a déjà convertie mais qu'elle était locale avant l'offload)
            $wpdb->query( $wpdb->prepare( 
                "UPDATE $wpdb->posts SET post_content = REPLACE(post_content, %s, %s)",
                $local_url_base . '.webp', $s3_url
            ) );
        }

        // 3. Planifier l'audit pour cette image précise
        as_enqueue_async_action('lt_mc_verify_s3_url', [ 'attachment_id' => $attachment_id, 's3_url' => $s3_url ], 'lt_media_cleaner_images');
    }
}
