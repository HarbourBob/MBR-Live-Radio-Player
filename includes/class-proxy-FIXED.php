<?php
/**
 * Stream Proxy Handler
 * Converts HTTP audio streams to HTTPS for secure playback
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MBR_LRP_Proxy {
    
    /**
     * Constructor
     */
    public function __construct() {
        // Register proxy endpoint
        add_action( 'init', array( $this, 'register_proxy_endpoint' ) );
        add_action( 'template_redirect', array( $this, 'handle_proxy_request' ) );
        
        // Add settings
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        
        // AJAX endpoint for fetching metadata
        add_action( 'wp_ajax_mbr_get_metadata', array( $this, 'ajax_get_metadata' ) );
        add_action( 'wp_ajax_nopriv_mbr_get_metadata', array( $this, 'ajax_get_metadata' ) );
    }
    
    /**
     * Validate URL to prevent SSRF attacks
     * 
     * @param string $url URL to validate
     * @return bool True if URL is safe, false otherwise
     */
    private function is_valid_stream_url( $url ) {
        // Parse URL
        $parsed = parse_url( $url );
        
        if ( ! $parsed || ! isset( $parsed['scheme'] ) || ! isset( $parsed['host'] ) ) {
            return false;
        }
        
        // Only allow HTTP/HTTPS
        if ( ! in_array( $parsed['scheme'], array( 'http', 'https' ), true ) ) {
            return false;
        }
        
        // Block localhost and common localhost variations
        $blocked_hosts = array( 'localhost', '127.0.0.1', '::1', '0.0.0.0', '0000:0000:0000:0000:0000:0000:0000:0001' );
        if ( in_array( strtolower( $parsed['host'] ), $blocked_hosts, true ) ) {
            return false;
        }
        
        // Resolve hostname to IP and check if it's private
        $ip = gethostbyname( $parsed['host'] );
        
        // Validate IP and block private/reserved ranges
        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
            return false;
        }
        
        // Block common internal ports
        $blocked_ports = array( 22, 23, 25, 110, 143, 445, 3306, 5432, 6379, 11211, 27017 );
        if ( isset( $parsed['port'] ) && in_array( (int) $parsed['port'], $blocked_ports, true ) ) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Check rate limit for proxy requests
     * 
     * @param string $identifier Unique identifier (IP or user ID)
     * @return bool True if within rate limit, false if exceeded
     */
    private function check_rate_limit( $identifier ) {
        $transient_key = 'mbr_proxy_rate_' . md5( $identifier );
        $requests = get_transient( $transient_key );
        
        if ( false === $requests ) {
            set_transient( $transient_key, 1, MINUTE_IN_SECONDS );
            return true;
        }
        
        // Max 60 requests per minute
        if ( $requests > 60 ) {
            return false;
        }
        
        set_transient( $transient_key, $requests + 1, MINUTE_IN_SECONDS );
        return true;
    }
    
