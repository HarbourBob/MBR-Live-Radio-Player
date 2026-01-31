<?php
/**
 * Plugin Name: MBR Live Radio Player
 * Plugin URI: https://robertp419.sg-host.com/radio/
 * Description: Beautiful, modern live radio player for WordPress. Create unlimited radio stations with custom artwork and HLS stream support.
 * Version: 3.8.5
 * Author: Robert Palmer
 * Author URI: https://madebyrobert.co.uk
 * Text Domain: mbr-live-radio-player
 * Domain Path: /languages
 * Requires at least: 5.2
 * Requires PHP: 7.2
 * Tested up to: 6.8
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Buy Me a Coffee
add_filter( 'plugin_row_meta', function ( $links, $file, $data ) {
    if ( ! function_exists( 'plugin_basename' ) || $file !== plugin_basename( __FILE__ ) ) {
        return $links;
    }

    $url = 'https://buymeacoffee.com/robertpalmer/';
    $links[] = sprintf(
	// translators: %s: The name of the plugin author.
        '<a href="%s" target="_blank" rel="noopener nofollow" aria-label="%s">☕ %s</a>',
        esc_url( $url ),
		// translators: %s: The name of the plugin author.
        esc_attr( sprintf( __( 'Buy %s a coffee', 'mbr-live-radio-player' ), isset( $data['AuthorName'] ) ? $data['AuthorName'] : __( 'the author', 'mbr-live-radio-player' ) ) ),
        esc_html__( 'Buy me a coffee', 'mbr-live-radio-player' )
    );

    return $links;
}, 10, 3 );


// Define plugin constants
define( 'MBR_LRP_VERSION', '3.8.0' );
define( 'MBR_LRP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MBR_LRP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MBR_LRP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Activation hook to clear all old cached data
 */
register_activation_hook( __FILE__, 'mbr_lrp_activation_clear_cache' );

/**
 * Clear all cached data on plugin activation
 */
function mbr_lrp_activation_clear_cache() {
    global $wpdb;
    
    // Use prepared statement to clear all MBR transients from database
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like( '_transient_mbr' ) . '%',
            $wpdb->esc_like( '_transient_timeout_mbr' ) . '%'
        )
    );
    
    // Flush rewrite rules to ensure proxy endpoints work
    flush_rewrite_rules();
    
    // Generate secure proxy token if it doesn't exist
    if ( ! get_option( 'mbr_lrp_proxy_token' ) ) {
        $token = wp_generate_password( 32, false, false );
        update_option( 'mbr_lrp_proxy_token', $token, false );
    }
}

/**
 * Get or generate proxy authentication token
 * This provides an additional security layer for standalone proxy files
 */
function mbr_lrp_get_proxy_token() {
    $token = get_option( 'mbr_lrp_proxy_token' );
    if ( empty( $token ) ) {
        $token = wp_generate_password( 32, false, false );
        update_option( 'mbr_lrp_proxy_token', $token, false );
    }
    return $token;
}

// Include required files
require_once MBR_LRP_PLUGIN_DIR . 'includes/class-post-type.php';
require_once MBR_LRP_PLUGIN_DIR . 'includes/class-meta-boxes.php';
require_once MBR_LRP_PLUGIN_DIR . 'includes/class-shortcode.php';
require_once MBR_LRP_PLUGIN_DIR . 'includes/class-assets.php';
require_once MBR_LRP_PLUGIN_DIR . 'includes/class-proxy.php';
require_once MBR_LRP_PLUGIN_DIR . 'includes/class-settings.php';

/**
 * Initialize the plugin
 */
function mbr_lrp_init() {
    // Initialize classes
    new MBR_LRP_Post_Type();
    new MBR_LRP_Meta_Boxes();
    new MBR_LRP_Shortcode();
    new MBR_LRP_Assets();
    new MBR_LRP_Proxy();
    new MBR_LRP_Settings();
}
add_action( 'plugins_loaded', 'mbr_lrp_init' );

/**
 * Add rewrite rules for popup player
 */
function mbr_lrp_add_rewrite_rules() {
    add_rewrite_rule( '^mbr-radio-popup/?$', 'index.php?mbr_radio_popup=1', 'top' );
}
add_action( 'init', 'mbr_lrp_add_rewrite_rules' );

/**
 * Add query vars for popup player
 */
function mbr_lrp_query_vars( $vars ) {
    $vars[] = 'mbr_radio_popup';
    return $vars;
}
add_filter( 'query_vars', 'mbr_lrp_query_vars' );

/**
 * Template redirect for popup player
 */
function mbr_lrp_template_redirect() {
    $popup_query = get_query_var( 'mbr_radio_popup' );
    
    // Also check the REQUEST_URI as a fallback (sanitized for security)
    $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
    if ( $popup_query || ( $request_uri && strpos( $request_uri, 'mbr-radio-popup' ) !== false ) ) {
        // Prevent caching
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
        header( 'Cache-Control: post-check=0, pre-check=0', false );
        header( 'Pragma: no-cache' );
        
        include MBR_LRP_PLUGIN_DIR . 'popup-player.php';
        exit;
    }
}
add_action( 'template_redirect', 'mbr_lrp_template_redirect' );

/**
 * Activation hook
 */
function mbr_lrp_activate() {
    // Set default proxy settings
    add_option( 'mbr_lrp_proxy_enabled', '1' );
    add_option( 'mbr_lrp_require_proxy', 'http_only' );
    
    // Register popup player rewrite rules
    mbr_lrp_add_rewrite_rules();
    
    // Register proxy rewrite rules
    add_rewrite_rule( '^mbr-radio-proxy/?$', 'index.php?mbr_radio_proxy=1', 'top' );
    add_rewrite_rule( '^mbr-metadata-proxy/?$', 'index.php?mbr_metadata_proxy=1', 'top' );
    
    // Flush rewrite rules
    flush_rewrite_rules();
    
    // Set a transient to show admin notice
    set_transient( 'mbr_lrp_activation_notice', true, 5 );
}
register_activation_hook( __FILE__, 'mbr_lrp_activate' );

/**
 * Show admin notice after activation
 */
function mbr_lrp_activation_notice() {
    if ( get_transient( 'mbr_lrp_activation_notice' ) ) {
        ?>
        <div class="notice notice-success is-dismissible">
            <p><strong>MBR Live Radio Player activated!</strong></p>
            <p>If the pop-out button shows a 404 error, please go to <strong>Settings → Permalinks</strong> and click <strong>Save Changes</strong> to flush rewrite rules.</p>
        </div>
        <?php
        delete_transient( 'mbr_lrp_activation_notice' );
    }
}
add_action( 'admin_notices', 'mbr_lrp_activation_notice' );

/**
 * Deactivation hook
 */
function mbr_lrp_deactivate() {
    // Flush rewrite rules
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'mbr_lrp_deactivate' );
