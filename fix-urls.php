<?php
/**
 * Script de restauration des URLs d'images
 */
global $wpdb;

$posts = $wpdb->get_results("SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type='post'");
$c = 0;

foreach ( $posts as $p ) {
    $content = preg_replace_callback(
        '/https?:\/\/lt-media-cleaner\.instawp\.xyz([^"\'\s<>]+?\.(?:jpg|jpeg|png|gif|webp))/i',
        function( $m ) { 
            return 'https://lalutotale.files.wordpress.com' . $m[1]; 
        },
        $p->post_content
    );
    
    if ( $content !== $p->post_content ) {
        $wpdb->update(
            $wpdb->posts, 
            [ 'post_content' => $content ], 
            [ 'ID' => $p->ID ]
        );
        $c++;
    }
}

echo "Terminé. $c articles réparés.\n";
