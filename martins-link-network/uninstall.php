<?php
// if uninstall.php is not called by WordPress, die
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    die;
}

class martinsLinkNetworkUninstall {

    public function __construct() {
        $this->cleanupDatabase();
        $this->cleanupFiles();
        $this->purgeExternalCaches();
    }

    /**
     * Removes database entries
     */
    private function cleanupDatabase() {
        delete_option("martinslinknetwork_version");
        delete_option("martinslinknetwork_key");
    }

    /**
     * Removes cache and log files
     */
    private function cleanupFiles() {
        $upload_dir = wp_upload_dir();
        $base_path  = trailingslashit( $upload_dir['basedir'] );

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
    }
    
    /**
     * Purge caching plugins
     */
    private function purgeExternalCaches() {
        // WP Rocket
        if ( function_exists( 'rocket_clean_domain' ) ) {
            rocket_clean_domain();
        }

        // W3 Total Cache
        if ( function_exists( 'w3tc_flush_all' ) ) {
            w3tc_flush_all();
        }

        // LiteSpeed Cache
        if ( has_action( 'litespeed_purger_purge_all' ) || defined( 'LSCWP_V' ) ) {
            do_action( 'litespeed_purger_purge_all' );
        }

        // WP Super Cache
        if ( function_exists( 'wp_cache_clear_cache' ) ) {
            wp_cache_clear_cache();
        }
        
        // FlyingPress (Bruger Class-based purge)
        if ( class_exists( '\FlyingPress\Purge' ) && method_exists( '\FlyingPress\Purge', 'purge_everything' ) ) {
            \FlyingPress\Purge::purge_everything();
        }

        // NitroPack (Bruger global funktion)
        if ( function_exists( 'nitropack_sdk_purge' ) ) {
            // Vi udfører en komplet purge (null, null betyder alt)
            nitropack_sdk_purge(null, null, 'Uninstalled LexonRank');
        }
    }
}

// Start oprydningen
new martinsLinkNetworkUninstall();