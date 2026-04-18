<?php
// if uninstall.php is not called by WordPress, die
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    die;
}

// Cleanup db
delete_option("martinslinknetwork_version");
delete_option("martinslinknetwork_key");

// Cleanup cache files
$upload_dir = wp_upload_dir();
$base_path  = $upload_dir['basedir'] . '/';

$files_to_delete = [
    'martinsLinkNetworkCache.txt',
    'martinsLinkNetworkLog.txt'
];

foreach ( $files_to_delete as $filename ) {
    $file_path = $base_path . $filename;
    
    if ( file_exists( $file_path ) ) {
        unlink( $file_path );
    }
}