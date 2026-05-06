<?php
/**
 * Plugin Name:       LexonRank: Free Link Building - Genuine SEO BackLinks
 * Plugin URI:        https://lexonrank.com
 * Description:       Easy SEO backlink plugin for WordPress, SEO backlinks for blogs, SEO backlinks for WooCommerce. Boost your Ecommerce business sales with easy automatic link building.
 * Version:           1.2.50
 * Requires at least: 5.0
 * Requires PHP:      5.6
 * Author:            NordicNodes
 * Author URI:        https://nordicnodes.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       martins-link-network
 * Domain Path:       /languages
 */

if(!defined ('ABSPATH')) {die;} // Block direct access to the file

define('MAADNE_LINK_NETWORK_VERSION', '1.2.50');

require_once(ABSPATH . "/wp-admin/includes/plugin.php");
require_once(ABSPATH . "/wp-admin/includes/file.php");
require_once(ABSPATH . "/wp-admin/includes/class-wp-upgrader.php");


class martinsLinkNetworkFront 
{
        
    private $cacheFile = "";
    private $logFile = "";
    private $links = [];
    private $data = "";
    private $usedKeyword = [];
    
    
    public function __construct() 
    {
        // Set file locations
        $uploadDir = wp_upload_dir();        
        $this->cacheFile = $uploadDir["basedir"] . "/martinsLinkNetworkCache.txt";
        $this->logFile = $uploadDir["basedir"] . "/martinsLinkNetworkLog.txt";
    }
     
    
    public function setup() 
    {    
        // Setup plugin
        add_action('wp', [$this, "getData"], 0);
        
        // Try adding links to elementor content
        add_filter('elementor/frontend/the_content', [$this, "insertLinksContent"], 1);
        
        // Try adding links to content
        add_filter('the_content', [$this, "insertLinksContent"], 2);
            
        // Try adding links to footer
        add_action('wp_footer', [$this, "insertLinksFooter"], 2000);
    }
    
    
    // Get data from service API or from cache
    public function getData() 
    {       
        // Initiate file system
        WP_Filesystem();
        global $wp_filesystem;
        
        // Clean cache and log if version has changed
        $version = get_option("martinslinknetwork_version");
        if ($version !== MAADNE_LINK_NETWORK_VERSION) {
            if (is_file($this->cacheFile)) {
                unlink($this->cacheFile);
            }
            if (is_file($this->logFile)) {
                unlink($this->logFile);
            }
            update_option("martinslinknetwork_version", MAADNE_LINK_NETWORK_VERSION, true);
        }
        
        // Get log data
        $logData = "";
        if ($wp_filesystem->exists($this->logFile)) {
            $logData = $wp_filesystem->get_contents($this->logFile);
        }
        
        // Get data from cache if exists
        $isCache = false;        
        if ($wp_filesystem->exists($this->cacheFile) && time() - $wp_filesystem->mtime($this->cacheFile) < 86400 + rand(1, 3600)) { // 24 hours in seconds
            $this->data = $wp_filesystem->get_contents($this->cacheFile);
            $isCache = true;
        }
        
        // Get data from API if no cached data
        else {
            // Check if saving cache is possible
            if (!$wp_filesystem->put_contents($this->cacheFile, "-")) {
                echo("<hr style='margin:0;padding:0;height:1px;border:0;' /><div style='text-align:center;'><b>LexonRank plugin, could not write cache to upload folder!</b><br />Please check your folder permissions...</div>");
            }
            else { 
                // Clear cache test
                if (is_file($this->cacheFile)) {
                    unlink($this->cacheFile);
                }
                
                // Save cache
                $result = wp_remote_post("https://lexonrank.com/api/domainsV2", ['timeout' => 30, 'method' => 'POST', 'body' => ["url" => get_site_url(), "email" => get_option("admin_email"), "version" => MAADNE_LINK_NETWORK_VERSION, "logData" => $logData]]);
                if (!isset($result->errors)) {
                    $this->data = $result["body"];
                    $wp_filesystem->put_contents($this->cacheFile, $this->data);
                    
                    // Purge external cache plugins
                    $this->purgeExternalCaches();
                }
            }
        }
        
        // Save current url to log
        if (!$isCache) {
            // Cleanup log and save only this url if new data was requested from server
            $log = [md5(get_permalink()) => get_permalink()];
        }
        else {
            $log = json_decode($logData, true);
            $log[md5(get_permalink())] = get_permalink();
        }
        $wp_filesystem->put_contents($this->logFile, json_encode($log));       
       
        // Extract links from data
        if ($this->data <> "") {
            $this->links = json_decode($this->data, true);
        }  
    }
    
    
    function purgeExternalCaches() {
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
        
        // FlyingPress
        if ( class_exists( '\FlyingPress\Purge' ) && method_exists( '\FlyingPress\Purge', 'purge_everything' ) ) {
            \FlyingPress\Purge::purge_everything();
        }

        // NitroPack
        if ( function_exists( 'nitropack_sdk_purge' ) ) {
            nitropack_sdk_purge(null, null, 'LexonRank received new data');
        }
    }
    
    
    // Group a websites main link and pages into a single array
    public function groupLinkPages($link)
    {
        $linkGroup = [];
        $mainLink = ["url" => $link["scheme"] . "://" . $link["domain"], "keywords" => $link["keywords"]];
        $linkGroup[] = $mainLink;

        if (!isset($link["pages"])) {
            $link["pages"] = [];
        }
        foreach($link["pages"] as $page) {
            $linkGroup[] = $page;
        } 
        
        return $linkGroup;
    }
    
    
    // Insert links into content
    public function insertLinksContent($content) {
        if (!is_array($this->links) || count($this->links) === 0 || !is_main_query() || !in_the_loop()) {
            return $content;
        }

        // Remove <script> og <style> temporary and save for later
        $placeholders = [];
        $content = preg_replace_callback('/<(script|style)[^>]*>.*?<\/\1>/is', function ($matches) use (&$placeholders) {
            $key = '__PLACEHOLDER_' . count($placeholders) . '__';
            $placeholders[$key] = $matches[0];
            return $key;
        }, $content);

        // Split content into tags and text
        $parts = preg_split('/(<[^>]+>)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        $output = "";

        for ($loop = 1; $loop <= 2; $loop++) {
            $this->links = array_values($this->links); // Reindex

            foreach ($parts as $index => $part) {
                // Check if this is a HTML tag or text
                if (preg_match('/^<[^>]+>$|^__PLACEHOLDER_\d+__$/', $part)) {
                    $output .= $part;
                    continue;
                }

                // Handl text nodes
                foreach ($this->links as $i => $link) {
                    $rel = wp_rand(1, 100) <= (isset($link["fp"]) ? (int)$link["fp"] : 100) ? "follow" : "nofollow";
                    $linkGroup = $this->groupLinkPages($link);
                    $isMatched = false;

                    shuffle($linkGroup);
                    foreach ($linkGroup as $page) {
                        shuffle($page["keywords"]);

                        foreach ($page["keywords"] as $keyword) {
                            $isLongTail = str_word_count($keyword["name"]) > 1;
                            $pos = strrpos($part, " " . $keyword["name"] . " ");

                            if ((($loop == 1 && $isLongTail) || ($loop == 2 && !$isLongTail)) &&
                                $pos !== false &&
                                !in_array($keyword["name"], $this->usedKeyword)) {

                                $part = substr_replace(
                                    $part,
                                    "<a id='" . esc_attr($link["key"]) . "' href='" . esc_url($page["url"]) . "' target='_blank' rel='" . esc_attr($rel) . "'>" . esc_html($keyword["name"]) . "</a>",
                                    $pos + 1,
                                    strlen($keyword["name"])
                                );

                                $this->usedKeyword[] = $keyword["name"];
                                unset($this->links[$i]);
                                $isMatched = true;
                                break 2;
                            }
                        }
                    }
                }

                $output .= $part;
            }

            // Prepare output for next loop
            $parts = preg_split('/(<[^>]+>)/', $output, -1, PREG_SPLIT_DELIM_CAPTURE);
            $output = "";
        }

        // Put content back together
        $output = implode('', $parts);

        // Re-add <script> and <style>-tags
        foreach ($placeholders as $key => $original) {
            $output = str_replace($key, $original, $output);
        }

        return $output;
    }

    
    
    public function insertLinksFooter() 
    {
        // Show links
        if (is_array($this->links) && count($this->links) > 0) {  
            $linkStr = "";
            foreach($this->links as $link) {
                // Group main link and pages for shuffling
                $linkGroup =  $this->groupLinkPages($link);

                // Try using a random url with random keyword
                $keyword = null;
                $text = null;
                shuffle($linkGroup);
                $url = $linkGroup[0]["url"];
                $rel = wp_rand(1, 100) <= (isset($link["fp"]) ? (int)$link["fp"] : 100) ? "follow" : "nofollow";                
                
                // Find a keyword
                for ($loop = 1; $loop <= 2; $loop++) {
                    foreach($linkGroup as $lg) {
                        $keywords = $lg["keywords"] ? $lg["keywords"] : [];
                        shuffle($keywords);
                        
                        foreach($keywords as $k) {
                            $isLongTail = count(explode(" ", $k["name"])) > 1 ? true : false;

                            // First loop tries longtail keywords only, next loop single keywords
                            if (($loop == 1 && $isLongTail) || ($loop == 2 && !$isLongTail)) {
                                $keyword = $k["name"];
                                $text = rtrim(ltrim($k["text"]));
                                $url = $lg["url"];
                                break 3;
                            }
                        }
                    }
                }
                
                // Set style
                $linkStyle = "font-size:9px;text-decoration:underline;color:#777777;"; // padding:0 10px;
                
                // Use keyword "link" if no keywords and no website name
                if (!$text) {
                    $keyword = $keyword ? $keyword : $link["name"];
                    $keyword = $keyword ? $keyword : "link";
                    $linkStr .= "<a id='" . $link["key"] . "' style='" . $linkStyle . "' href='" . $url . "' rel='" . $rel . "'>" . ucfirst($keyword) . "</a>. ";
                }
                else {
                    $linkStr .= ucfirst(str_replace($keyword, "<a id='" . $link["key"] . "' style='" . $linkStyle . "' href='" . $url . "' rel='" . $rel . "'>" . $keyword . "</a>", $text)) . ". "; // 
                }
                
            }
            
            // Build allowed html array
            $allowedHtml = wp_kses_allowed_html();
            $allowedHtml['a'] = array();
            $allowedHtml['a']['id'] = array();
            $allowedHtml['a']['href'] = array();
            $allowedHtml['a']['style'] = array();
            $allowedHtml['a']['rel'] = array();
            
            echo("<div id='" . md5("martinslinknetwork" . str_replace("http://", "", str_replace("https://", "", get_site_url()))) . "' style='display:block;width:100%;font-size:9px;color:#777777;border:0;border-top:1px solid #ccc;background-color:#ffffff;padding:5px 0;text-align:center;'>" . wp_kses(substr($linkStr, 0, -1), $allowedHtml) . "</div>");
        }
    }
    
}


class martinsLinkNetworkAdmin 
{
    private $url = "";
    private $key = "";
    
    public function __construct() 
    {
        $this->url = wp_parse_url(get_site_url());
        
        add_action('admin_init', [$this, "redirectDashboard"]);
        add_action('admin_init', [$this, "showDeactivation"]);
        add_action('admin_menu', [$this, "addMenuItems"]);
        add_action('admin_enqueue_scripts', [$this, 'adminScripts']);
        add_action( 'admin_head', function() {
            remove_submenu_page( 'index.php', 'martins-link-network-install-ad-network' );
        } );
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'addActionLinks']);
        register_activation_hook(__FILE__, [$this, 'pluginActivated']);
    }

    public function adminScripts($hook) {
        if (strpos($hook, 'martins-link-network') === false) {
            return;
        }
        wp_enqueue_style('maadne-inter-font', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap', [], null);
    }
    
    public function pluginActivated()
    {
        $martinsLinkNetworkFront = new martinsLinkNetworkFront(); 
        $martinsLinkNetworkFront->getData();
    }

    private function getCommonStyles() {
        return "
            <style>
                .maadne-admin-page {
                    max-width: 800px;
                    margin-top: 20px;
                    font-family: 'Inter', sans-serif;
                }
                .maadne-logo {
                    font-weight: 900;
                    font-size: 32px;
                    letter-spacing: -2px;
                    color: inherit;
                    margin-bottom: 30px;
                    display: flex;
                    align-items: center;
                    text-decoration: none;
                }
                .maadne-logo span {
                    color: #00f2ff;
                    text-shadow: 0 0 10px rgba(0, 242, 255, 0.2);
                }
                .maadne-card {
                    background: #fff;
                    border: 1px solid #c3c4c7;
                    border-radius: 8px;
                    padding: 40px;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
                }
                .maadne-btn-primary {
                    background: #00f2ff !important;
                    color: #000 !important;
                    border: 1px solid #00b8c4 !important;
                    padding: 8px 30px !important;
                    font-weight: 700 !important;
                    text-transform: uppercase !important;
                    letter-spacing: 0.5px !important;
                    border-radius: 3px !important;
                    cursor: pointer;
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    text-decoration: none;
                    line-height: 1.5 !important;
                    box-shadow: none !important;
                    text-shadow: none !important;
                    height: auto !important;
                }
                .maadne-btn-primary:hover {
                    background: #00d9e6 !important;
                    border-color: #00b8c4 !important;
                    color: #000 !important;
                }
                .maadne-badge {
                    background: #00f2ff15;
                    border: 1px solid #00f2ff30;
                    color: #00b8c4;
                    padding: 4px 12px;
                    border-radius: 100px;
                    font-size: 10px;
                    font-weight: 800;
                    text-transform: uppercase;
                    letter-spacing: 1px;
                    display: inline-block;
                    margin-bottom: 15px;
                }
                .maadne-footer {
                    margin-top: 50px;
                    padding-top: 25px;
                    border-top: 1px solid #dcdcde;
                    color: #646970;
                    font-size: 13px;
                }
            </style>
        ";
    }
    
    public function dashboardPage() 
    {
        ?>
        <div class="wrap">
            <?php echo $this->getCommonStyles(); ?>
            <div class="maadne-admin-page" style="margin-top: 40px; font-family: 'Inter', sans-serif;">
                <div class="maadne-logo" style="font-size: 42px;">LEXON<span>RANK</span></div>
                <h2 style="font-size: 24px; margin-top: 30px;">Unable to connect to Dashboard</h2>
                <p style="color: #646970; font-size: 16px; max-width: 600px; margin-bottom: 30px;">We couldn't establish a secure connection to the SEO external dashboard. Please check your internet connection or try again later.</p>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <a href="<?php echo admin_url('index.php?page=martins-link-network-dashboard'); ?>" class="button button-primary maadne-btn-primary">Retry Connection</a>
                    <a href="<?php echo admin_url('plugins.php'); ?>" class="button button-secondary">Go to Plugins</a>
                </div>
            </div>
        </div>
        <?php
    }

    public function showDeactivation() 
    {
        if (isset($_GET['action']) && $_GET['action'] == 'deactivate' && isset($_GET['plugin']) && $_GET['plugin'] == 'martins-link-network/martinsLinkNetwork.php' && !isset($_GET["skip_martins-link-network-deactivation"])) {
            ?>
            <!DOCTYPE html>
            <html <?php language_attributes(); ?>>
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Deactivating LexonRank</title>
                <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700;900&display=swap">
                <?php echo $this->getCommonStyles(); ?>
                <style>
                    body { background: #f0f2f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
                    .maadne-admin-page { margin-top: 0; padding: 20px; }
                    .maadne-card { text-align: center; }
                    h3 { font-size: 1.5rem; letter-spacing: -0.5px; margin-top: 0; }
                    .hint-box { background: #f8f9fa; border: 1px solid #e9ecef; padding: 15px; border-radius: 6px; margin: 20px 0; font-style: italic; color: #646970; }
                </style>
            </head>
            <body>
                <div class="maadne-admin-page">
                    <div class="maadne-logo" style="justify-content: center;">LEXON<span>RANK</span></div>
                    <div class="maadne-card">
                        <div class="maadne-badge">Before you go...</div>
                        <h3>Did you know?</h3>
                        <p style="font-size: 16px; line-height: 1.6; color: #1d2327;">
                            <b>For only a few bucks:</b> Outbound links in your own website are removed, and you will still get backlinks.
                            Actually, you can resell backlinks to your clients too!
                        </p>
                        
                        <div class="hint-box">
                            "Boost your SEO rankings even further with our VIP network."
                        </div>

                        <div style="margin: 30px 0;">
                            <a href="https://lexonrank.com" target="_blank" class="maadne-btn-primary">Check Out VIP</a>
                            <a href="<?php echo esc_url(admin_url("/plugins.php?action=deactivate&plugin=martins-link-network%2FmartinsLinkNetwork.php&plugin_status=all&paged=1&s&_wpnonce=" . $_GET["_wpnonce"] . "&skip_martins-link-network-deactivation=1")); ?>" 
                               class="button button-link" style="color: #646970; margin-left: 15px;">Just Deactivate</a>
                        </div>

                        <hr style="border: 0; border-top: 1px solid #eee; margin: 30px 0;">
                        
                        <h4 style="margin-bottom: 15px;">Are you more into free ads for your website?</h4>
                        <a href="<?php echo admin_url('index.php?page=martins-link-network-install-ad-network'); ?>" class="button button-secondary" style="height: auto; padding: 8px 20px;">Install LexonAds</a>
                    </div>
                </div>
            </body>
            </html>
            <?php
            die();
        }
    }
    
    
    // Installs separate plugin for the ad network
    public function installAdNetwork() 
    {
        if (isset($_GET['page']) && $_GET['page'] == 'martins-link-network-install-ad-network') {
            ?>
            <div class="wrap">
                <?php echo $this->getCommonStyles(); ?>
                <div class="maadne-admin-page" style="margin-top: 40px;">
                    <div class="maadne-logo">LEXON<span>RANK</span></div>
                    <div class="maadne-card">
                        <div class="maadne-badge">Automated Installation</div>
                        <h3 style="margin-top:0; margin-bottom: 25px;">Installing LexonAds</h3>
                        
                        <div style="background: #f8f9fa; padding: 20px; border-radius: 6px; margin-bottom: 30px;">
                            <?php
                            $wp_upgrader = new WP_Upgrader();
                            $install = $wp_upgrader->run([
                                "package"                       => "https://lexonads.com/assets/martins-ad-network.zip",
                                "destination"                   => plugin_dir_path(__FILE__) . "../martins-ad-network",
                                "clear_destination"             => true,
                                "abort_if_destination_exists"   => false
                            ]);
                            
                            if (is_array($install)) {
                                echo("<p style='color: #1cbb8c;'><strong>✓</strong> Plugin files installed successfully.</p>");
                            }
                            else {
                                echo("<p style='color: #d63638;'><strong>✕</strong> Could not install plugin files.</p>");
                            }
                            
                            $activate = activate_plugin( 'martins-ad-network/martinsAdNetwork.php');
                            if (!$activate) {
                                echo("<p style='color: #1cbb8c;'><strong>✓</strong> Plugin activated and ready.</p>");
                            }
                            else {
                                echo("<p style='color: #d63638;'><strong>✕</strong> Could not activate plugin automatically.</p>");
                            }
                            ?>
                        </div>
                        
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <a href="<?php echo admin_url("plugins.php"); ?>" class="button button-primary maadne-btn-primary">Go to Plugins</a>
                            <a href="https://lexonads.com" target="_blank" class="button button-secondary">How it works</a>
                        </div>
                    </div>
                </div>
            </div>
            <?php
            die();
        }
    }
    
    
    public function redirectDashboard() 
    {
        if (isset($_GET['page']) && $_GET['page'] == 'martins-link-network-dashboard') {
            $this->getDashboardKey();

            if ($this->key != "failed") {
                wp_redirect("https://lexonrank.com/admin/#/statswp/" . $this->url["host"] . "/" . $this->key);
                exit;
            }
        }            
            
    }

    
    public function addMenuItems() 
    {
        add_dashboard_page('LexonRank', 'LexonRank', 'manage_options', 'martins-link-network-dashboard', [$this, 'dashboardPage'], 2);
        add_dashboard_page('Install Free Ad Network', 'Install Free Ad Network', 'manage_options', 'martins-link-network-install-ad-network', [$this, 'installAdNetwork'], 2);
    }  
    
    
    public function addActionLinks($links) 
    {
        $mylinks = array(
            "<a href='https://lexonrank.com' target='_blank'><b>Upgrade to VIP</b></a>",
            "<a href='" . admin_url('index.php?page=martins-link-network-dashboard') . "'>Dashboard</a>",
            "<a href='" . admin_url('index.php?page=martins-link-network-install-ad-network') . "'><b>Install Free Ad Network</b></a>",
            "<a href='https://lexonrank.com#support' target='_blank'>Support</a>"
        );
        
       return array_merge($mylinks, $links);
    }
    
    
    public function getDashboardKey()
    {
        $result = wp_remote_post("https://lexonrank.com/api/domainsV2/?getKey", ['timeout' => 30, 'method' => 'POST', 'body' => ["url" => get_site_url(), "email" => get_option("admin_email"), "version" => MAADNE_LINK_NETWORK_VERSION]]);
        
        if (!is_wp_error($result)) {
            $this->data = json_decode($result["body"]);

            if ($this->data && isset($this->data->status) && $this->data->status == "success") {
                $key = $this->data->key;
                update_option("martinslinknetwork_key", $key, false);
            }
            else {
                $key = get_option("martinslinknetwork_key");        
            }
        } 
        else {
            $key = "failed";
        }
        
        $this->key = $key;
    }
}


// Start the show
if (!is_admin()) {
    $martinsLinkNetworkFront = new martinsLinkNetworkFront(); 
    $martinsLinkNetworkFront->setup();
}
else {
    $martinsLinkNetworkAdmin = new martinsLinkNetworkAdmin();
}
