<?php
/**
 * Plugin Name:       Martins Free And Easy SEO Link Building - Genuine SEO BackLinks
 * Plugin URI:        https://linkbuilding.martinstools.com
 * Description:       Easy SEO backlinks plugin for WordPress, SEO backlinks for blogs, SEO backlinks for WooCommerce. Boost your Ecommerce business sales with easy automatic link building.
 * Version:           1.2.37
 * Requires at least: 5.0
 * Requires PHP:      5.6
 * Author:            Martins Tools
 * Author URI:        https://www.martinstools.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       martins-link-network
 * Domain Path:       /languages
 */

if(!defined ('ABSPATH')) {die;} // Block direct access to the file


require_once(ABSPATH . "/wp-admin/includes/plugin.php");
require_once(ABSPATH . "/wp-admin/includes/file.php");
require_once(ABSPATH . "/wp-admin/includes/class-wp-upgrader.php");


class martinsLinkNetworkFront 
{
        
    private $version = "1.2.37";
    private $cacheFile = "";
    private $logFile = "";
    private $versionFile = "";
    private $links = [];
    private $data = "";
    private $usedKeyword = [];
    
    
    public function __construct() 
    {
        // Set file locations
        $uploadDir = wp_upload_dir();        
        $this->cacheFile = $uploadDir["basedir"] . "/martinsLinkNetworkCache.txt";
        $this->logFile = $uploadDir["basedir"] . "/martinsLinkNetworkLog.txt";
        $this->versionFile = $uploadDir["basedir"] . "/martinsLinkNetworkVersion.txt";
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
        if ($version !== $this->version) {
            if (is_file($this->cacheFile)) {
                unlink($this->cacheFile);
            }
            if (is_file($this->logFile)) {
                unlink($this->logFile);
            }
            update_option("martinslinknetwork_version", $this->version, true);
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
                echo("<hr style='margin:0;padding:0;height:1px;border:0;' /><div style='text-align:center;'><b>Martins Link Network plugin, could not write cache to upload folder!</b><br />Please check your folder permissions...</div>");
            }
            else { 
                // Clear cache test
                if (is_file($this->cacheFile)) {
                    unlink($this->cacheFile);
                }
                
                // Save cache
                $result = wp_remote_post("https://linknetwork.martinstools.com/api/domainsV2", ['timeout' => 30, 'method' => 'POST', 'body' => ["url" => get_site_url(), "email" => get_option("admin_email"), "version" => $this->version, "logData" => $logData]]);
                if (!isset($result->errors)) {
                    $this->data = $result["body"];
                    $wp_filesystem->put_contents($this->cacheFile, $this->data);
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
    public function insertLinksContent($content) 
    {
        // Check if we're inside the main loop in a single Post.
        if (is_array($this->links) && count($this->links) > 0 && is_main_query() && in_the_loop()) {
            $extend = "";
            
            for ($loop = 1; $loop <= 2; $loop++) {
                $i = 0;
                
                foreach($this->links as $link) {
                    $rel = wp_rand(1, 100) <= (isset($link["fp"]) ? (int)$link["fp"] : 100) ? "follow" : "nofollow";
                    
                    // Group main link and pages for shuffling
                    $linkGroup = $this->groupLinkPages($link);

                    // Try matching pages keywords IF pages exists (Some websites do not have any pages)
                    $isMatched = false;
                    shuffle($linkGroup);

                    foreach($linkGroup as $page) {
                        // Uncomment these keywords for testing
                        //$page["keywords"][] = ["name" => "ipsum dolor sit", "text" => "Lorem ipsum dolor sit amet"];
                        //$page["keywords"][] = ["name" => "dolor sit", "text" => "Ipsum dolor sit amet"];
                        
                        shuffle($page["keywords"]);
                        foreach($page["keywords"] as $keyword) {
                            $isLongTail = count(explode(" ", $keyword["name"])) > 1 ? true : false;
                            $pos = strrpos($content, " " . $keyword["name"] . " ");
                            $endTag = substr(substr($content, $pos), strpos(substr($content, $pos), "<"), 3);

                            // First loop tries longtail keywords only, next loop single keywords
                            if ((($loop == 1 && $isLongTail) || ($loop == 2 && !$isLongTail)) && $pos !== false && !in_array($keyword["name"], $this->usedKeyword) && $endTag != "</a" && $endTag != "</h")
                            {
                                $content = substr_replace($content, "<a href='" . $page["url"] . "' target='_blank' rel='" . $rel . "'>" . $keyword["name"] . "</a>", $pos+1, strlen($keyword["name"]));
                                unset($this->links[$i]);
                                $this->usedKeyword[] = $keyword["name"];
                                $isMatched = true;
                                
                                break 2; // Only make 1 keyword clickable, and continue with next link in linkGroup
                            }
                        }
                    }

                    $i++;
                }   
                
                // Reindex links array as we might have removed some items
                $this->links = array_values($this->links);
            }
        }
        
        return $content;
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
                    $linkStr .= "<a style='" . $linkStyle . "' href='" . $url . "' rel='" . $rel . "'>" . ucfirst($keyword) . "</a>. ";
                }
                else {
                    $linkStr .= ucfirst(str_replace($keyword, "<a style='" . $linkStyle . "' href='" . $url . "' rel='" . $rel . "'>" . $keyword . "</a>", $text)) . ". "; // 
                }
                
            }
            
            // Build allowed html array
            $allowedHtml = wp_kses_allowed_html();
            $allowedHtml['a'] = array();
            $allowedHtml['a']['href'] = array();
            $allowedHtml['a']['style'] = array();
            $allowedHtml['a']['rel'] = array();
            
            echo("<div id='" . md5("martinslinknetwork" . str_replace("http://", "", str_replace("https://", "", get_site_url()))) . "' style='display:block;width:100%;font-size:9px;color:#777777;border:0;border-top:1px solid #ccc;background-color:#ffffff;padding:5px;text-align:center;'>" . wp_kses(substr($linkStr, 0, -1), $allowedHtml) . "</div>");
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
        
        //add_action('admin_init', [$this, "installAdNetwork"]);
        add_action('admin_init', [$this, "redirectDashboard"]);
        add_action('admin_init', [$this, "showDeactivation"]);
        add_action('admin_menu', [$this, "addMenuItems"]);
        add_action( 'admin_head', function() {
            remove_submenu_page( 'index.php', 'martins-link-network-install-ad-network' );
        } );
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'addActionLinks']);
        register_activation_hook(__FILE__, [$this, 'pluginActivated']);
    }
    
    
    public function pluginActivated()
    {
        $martinsLinkNetworkFront = new martinsLinkNetworkFront(); 
        $martinsLinkNetworkFront->getData();
    }
    
    
    public function dashboardPage() 
    {
        echo("Oops!!! Unable to connect to Dashboard.<br />Please try again later...");
    }

    
    public function showDeactivation() 
    {
        if (isset($_GET['action']) && $_GET['action'] == 'deactivate' && isset($_GET['plugin']) && $_GET['plugin'] == 'martins-link-network/martinsLinkNetwork.php' && !isset($_GET["skip_martins-link-network-deactivation"])) {
            echo("<html><body style='text-align:center;color:#000;margin-top:50px;background-color:#f5f7fb;font-family:Helvetica,Arial,sans-serif;'>");
            echo("<h1>Martins Link Building</h1>");
            
            echo("<div style='max-width:800px;padding:20px 20px 50px 20px;margin:auto;border-radius:0.25rem;background-color:#fff;'>");
            echo("<h3>Did you know...</h3>");
            echo("<b>For only $10:</b> Outbound links in your own website is removed, and you will still get backlinks.<br /><br />");
            
            echo("<small><b>Hint:</b><br /><i>Actually, you can resell backlinks to your clients too!</i></small><br /><br />");
            echo("<a href='https://easy-link-building.martinstools.com'><button style='font-size:0.925rem;color:#fff;background-color:#1cbb8c;padding:0.4rem 1rem;border-radius:0.3rem;border:0;cursor:pointer;'>Check Out VIP</button></a> <a href='" . esc_url(admin_url("/plugins.php?action=deactivate&plugin=martins-link-network%2FmartinsLinkNetwork.php&plugin_status=all&paged=1&s&_wpnonce=" . $_GET["_wpnonce"] . "&skip_martins-link-network-deactivation=1")) . "'><button style='font-size:0.925rem;color:#fff;background-color:#dddddd;padding:0.4rem 1rem;border-radius:0.3rem;border:0;cursor:pointer;'>Just Deactivate</button></a>");
            
            echo("<br /><br /><h3>Are you more into free ads for your website?</h3>");
            echo("<a href='" . admin_url('?page=martins-link-network-install-ad-network') . "'><button style='font-size:0.925rem;color:#fff;background-color:#1cbb8c;padding:0.4rem 1rem;border-radius:0.3rem;border:0;cursor:pointer;'>Install Martins Free Ad Network</button></a>");
            
            echo("</div>");
            echo("</body></html>");
            die();
        }
    }
    
    
    // Installs separate plugin for the ad network
    public function installAdNetwork() 
    {
        if (isset($_GET['page']) && $_GET['page'] == 'martins-link-network-install-ad-network') {
            echo("<div style='width:100%;height:100%;padding:0;margin:0;text-align:center;'>");
            echo("<h1>Martins Ad Network</h1>");

            echo("<div style='max-width:800px;padding:20px 20px 50px 20px;margin:auto;border-radius:0.25rem;background-color:#fff;'>");
            echo("<h3>Installing plugin</h3>");

            $wp_upgrader = new WP_Upgrader();
            $install = $wp_upgrader->run([
                "package"                       => "https://adnetwork.martinstools.com/assets/martins-ad-network.zip", // plugin_dir_path(__FILE__) . "martins-ad-network.zip",
                "destination"                   => plugin_dir_path(__FILE__) . "../martins-ad-network",
                "clear_destination"             => true,
                "abort_if_destination_exists"   => false
            ]);
            
            if (is_array($install)) {
                echo("<b>Plugin installed!</b><br /><br />");
            }
            else {
                echo("<b>Could not install plugin!</b><br /><br />");
            }
            
            $activate = activate_plugin( 'martins-ad-network/martinsAdNetwork.php');
            if (!$activate) { // No errors
                echo("<b>Plugin activated!</b><br /><br />");
            }
            else {
                echo("<b>Could not activate plugin!</b><br /><br />");
            }
            
            echo("<a href='" . admin_url("plugins.php") . "'><button style='font-size:0.925rem;color:#fff;background-color:#1cbb8c;padding:0.4rem 1rem;border-radius:0.3rem;border:0;cursor:pointer;'>Continue</button></a> <a href='https://free-ad-network.martinstools.com' target='_blank'><button style='font-size:0.925rem;color:#fff;background-color:#1cbb8c;padding:0.4rem 1rem;border-radius:0.3rem;border:0;cursor:pointer;'>How it works</button></a>");
            echo("</div>");
            echo("</div>");
            die();
        }
    }
    
    
    public function redirectDashboard() 
    {
        if (isset($_GET['page']) && $_GET['page'] == 'martins-link-network-dashboard') {
            // Get key for the dashboard
            $this->getDashboardKey();

            // Redirect to external dashboard
            if ($this->key != "failed") {
                wp_redirect("https://linknetwork.martinstools.com/admin/#/statswp/" . $this->url["host"] . "/" . $this->key);
                exit;
            }
            else {
                $this->dashboardPage();
            }
        }            
            
    }

    
    public function addMenuItems() 
    {
        add_dashboard_page('Martins Link Building', 'Martins Link Building', 'manage_options', 'martins-link-network-dashboard', [$this, 'dashboardPage'], 2);
        add_dashboard_page('Install Ad Network', 'Install Ad Network', 'manage_options', 'martins-link-network-install-ad-network', [$this, 'installAdNetwork'], 2);
    }  
    
    
    public function addActionLinks($links) 
    {
        // Add links in plugin list
        $mylinks = array(
            "<a href='https://easy-link-building.martinstools.com' target='_blank'><b>Upgrade VIP</b></a>",
            "<a href='" . admin_url('?page=martins-link-network-dashboard') . "'>Dashboard</a>",
            "<a href='" . admin_url('?page=martins-link-network-install-ad-network') . "'><b>Install Free Ad Network</b></a>",
            "<a href='https://easy-link-building.martinstools.com#contact' target='_blank'>Support</a>"
        );
        
       return array_merge($mylinks, $links);
    }
    
    
    public function getDashboardKey()
    {
        // Try getting dashboard key from server
        $result = wp_remote_post("https://linknetwork.martinstools.com/api/domainsV2/?getKey", ['timeout' => 30, 'method' => 'POST', 'body' => ["url" => get_site_url(), "email" => get_option("admin_email")]]);
        if (!isset($result->errors)) {
            $this->data = json_decode($result["body"]);

            if ($this->data->status == "success") {
                $key = $this->data->key;
                update_option("martinslinknetwork_key", $key, false);
            }
            else {
                // New key not allowed. Using cached key
                $key = get_option("martinslinknetwork_key");        
            }
        } 
        // New key failed
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
