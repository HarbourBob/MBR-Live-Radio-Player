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
        add_action( 'admin_init', array( $this, 'maybe_flush_rewrite_rules' ) );
        
        // AJAX endpoint for fetching metadata
        add_action( 'wp_ajax_mbr_get_metadata', array( $this, 'ajax_get_metadata' ) );
        add_action( 'wp_ajax_nopriv_mbr_get_metadata', array( $this, 'ajax_get_metadata' ) );
        
        // AJAX endpoints for streaming (fallback when rewrite rules don't work)
        add_action( 'wp_ajax_mbr_proxy_stream', array( $this, 'ajax_proxy_stream' ) );
        add_action( 'wp_ajax_nopriv_mbr_proxy_stream', array( $this, 'ajax_proxy_stream' ) );
        add_action( 'wp_ajax_mbr_proxy_metadata', array( $this, 'ajax_proxy_metadata_endpoint' ) );
        add_action( 'wp_ajax_nopriv_mbr_proxy_metadata', array( $this, 'ajax_proxy_metadata_endpoint' ) );
    }
    
    /**
     * AJAX handler for stream proxy (fallback)
     */
    public function ajax_proxy_stream() {
        // Validate authentication token
        $provided_token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
        $valid_token = get_option( 'mbr_lrp_proxy_token', '' );
        
        if ( empty( $valid_token ) ) {
            // Generate token if it doesn't exist
            $valid_token = wp_generate_password( 32, false, false );
            update_option( 'mbr_lrp_proxy_token', $valid_token, false );
        }
        
        if ( empty( $provided_token ) || ! hash_equals( $valid_token, $provided_token ) ) {
            status_header( 403 );
            echo esc_html( 'Invalid authentication token' );
            exit;
        }
        
        $this->handle_stream_proxy();
    }
    
    /**
     * AJAX handler for metadata proxy (fallback)
     */
    public function ajax_proxy_metadata_endpoint() {
        // NOTE: This endpoint does NOT require authentication token
        // It only fetches metadata (read-only), and the standalone proxy file
        // handles the actual metadata extraction with token validation
        $this->handle_metadata_proxy();
    }
    
    /**
     * Flush rewrite rules if proxy endpoints aren't registered
     */
    public function maybe_flush_rewrite_rules() {
        $rules = get_option( 'rewrite_rules' );
        
        // Check if our proxy endpoints are registered
        if ( ! isset( $rules['mbr-radio-proxy/?$'] ) || ! isset( $rules['mbr-metadata-proxy/?$'] ) ) {
            // Register the rules
            $this->register_proxy_endpoint();
            // Flush to make them take effect
            flush_rewrite_rules( false );
        }
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
        
        $host = strtolower( $parsed['host'] );
        
        // Block localhost and common localhost variations
        $blocked_hosts = array( 'localhost', '127.0.0.1', '::1', '0.0.0.0', 'metadata.google.internal' );
        if ( in_array( $host, $blocked_hosts, true ) ) {
            return false;
        }
        
        // Block AWS/GCP metadata endpoints
        if ( $host === '169.254.169.254' || $host === 'fd00:ec2::254' ) {
            return false;
        }
        
        // Check if the host itself is a private IP (when URL uses IP directly)
        if ( filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
            // It's an IPv4 address - check if it's private or reserved
            if ( filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
                return false; // Block private IPs like 192.168.x.x, 10.x.x.x, 172.16.x.x
            }
        }
        
        // Check IPv6 addresses
        if ( filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
            // Block private IPv6 ranges
            $hex = bin2hex( @inet_pton( $host ) );
            if ( $hex ) {
                // Block fc00::/7 (Unique Local Address) - starts with fc or fd
                // Block fe80::/10 (Link-local) - starts with fe8, fe9, fea, feb
                // Block ::1 (localhost)
                if ( substr( $hex, 0, 2 ) === 'fc' || 
                     substr( $hex, 0, 2 ) === 'fd' ||
                     substr( $hex, 0, 3 ) === 'fe8' ||
                     substr( $hex, 0, 3 ) === 'fe9' ||
                     substr( $hex, 0, 3 ) === 'fea' ||
                     substr( $hex, 0, 3 ) === 'feb' ||
                     $host === '::1' ) {
                    return false;
                }
            }
        }
        
        // For hostnames, resolve DNS to check if they point to private IPs
        if ( ! filter_var( $host, FILTER_VALIDATE_IP ) ) {
            // Get a list of trusted streaming domain patterns that bypass DNS checks
            $trusted_patterns = apply_filters( 'mbr_lrp_trusted_stream_domains', array(
                '/\.shoutcast\.com$/i',
                '/\.icecast\.org$/i',
                '/\.somafm\.com$/i',
                '/\.streamon\.fm$/i',
                '/\.radio\.net$/i',
                '/\.radiojar\.com$/i',
                '/\.listen2myradio\.com$/i',
                '/\.streaminghub\.com$/i',
                '/\.radionomy\.com$/i',
                '/\.streamguys\.com$/i',
            ));
            
            $is_trusted = false;
            foreach ( $trusted_patterns as $pattern ) {
                if ( preg_match( $pattern, $host ) ) {
                    $is_trusted = true;
                    break;
                }
            }
            
            // If not a trusted domain, perform DNS resolution check
            if ( ! $is_trusted ) {
                // Suppress errors and warnings for gethostbynamel
                $ips = @gethostbynamel( $host );
                
                // If DNS resolution fails, block it for security
                if ( $ips === false || empty( $ips ) ) {
                    error_log( "MBR Proxy: Failed to resolve hostname: {$host}" );
                    return false;
                }
                
                // Check each resolved IP
                foreach ( $ips as $ip ) {
                    // Check IPv4
                    if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
                        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
                            error_log( "MBR Proxy: Hostname {$host} resolves to private IPv4: {$ip}" );
                            return false;
                        }
                    }
                    
                    // Check IPv6
                    if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
                        $hex = bin2hex( @inet_pton( $ip ) );
                        if ( $hex && ( substr( $hex, 0, 2 ) === 'fc' || substr( $hex, 0, 2 ) === 'fd' || 
                                       substr( $hex, 0, 3 ) === 'fe8' || substr( $hex, 0, 3 ) === 'fe9' ||
                                       substr( $hex, 0, 3 ) === 'fea' || substr( $hex, 0, 3 ) === 'feb' ) ) {
                            error_log( "MBR Proxy: Hostname {$host} resolves to private IPv6: {$ip}" );
                            return false;
                        }
                    }
                }
            }
        }
        
        // Restrict to common streaming ports only
        if ( isset( $parsed['port'] ) ) {
            $allowed_ports = array( 
                80, 443,           // HTTP/HTTPS
                8000, 8080, 8443,  // Common streaming ports
                8888, 9000,        // Alternative streaming ports
                1935,              // RTMP
                4190, 4191,        // Icecast variants
                9001, 9002,        // More streaming alternatives
                7000, 7001         // Additional Icecast ports
            );
            if ( ! in_array( (int) $parsed['port'], $allowed_ports, true ) ) {
                error_log( "MBR Proxy: Blocked non-standard port {$parsed['port']} for URL: {$url}" );
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Check rate limit for proxy requests
     * Enhanced with short-term, long-term, and temporary blocking
     * 
     * @param string $identifier Unique identifier (IP or user ID)
     * @return bool True if within rate limit, false if exceeded
     */
    private function check_rate_limit( $identifier ) {
        // Check if temporarily blocked first (most efficient check)
        $blocked_key = 'mbr_blocked_' . md5( $identifier );
        if ( get_transient( $blocked_key ) ) {
            return false;
        }
        
        // Short-term rate limit (per minute) - catches burst attacks
        $short_key = 'mbr_rate_short_' . md5( $identifier );
        $short_requests = get_transient( $short_key );
        
        if ( false === $short_requests ) {
            set_transient( $short_key, 1, MINUTE_IN_SECONDS );
        } else {
            // Reduced from 60 to 30 requests per minute
            // Normal usage: metadata polls every 5 seconds = 12/min, plus a few streams = ~15/min
            if ( $short_requests > 30 ) {
                error_log( "MBR: Short-term rate limit exceeded for {$identifier}" );
                return false;
            }
            set_transient( $short_key, $short_requests + 1, MINUTE_IN_SECONDS );
        }
        
        // Long-term rate limit (per hour) - catches sustained attacks
        $long_key = 'mbr_rate_long_' . md5( $identifier );
        $long_requests = get_transient( $long_key );
        
        if ( false === $long_requests ) {
            set_transient( $long_key, 1, HOUR_IN_SECONDS );
        } else {
            // 500 requests per hour = reasonable for multiple concurrent users/tabs
            if ( $long_requests > 500 ) {
                // Temporary block for 1 hour
                set_transient( $blocked_key, true, HOUR_IN_SECONDS );
                error_log( "MBR: Long-term rate limit exceeded, blocking {$identifier} for 1 hour" );
                return false;
            }
            set_transient( $long_key, $long_requests + 1, HOUR_IN_SECONDS );
        }
        
        return true;
    }
    
    /**
     * AJAX handler to get current metadata
     */
    public function ajax_get_metadata() {
        // NOTE: This endpoint does NOT require authentication token
        // It's a read-only endpoint that only fetches metadata, no streaming
        // Rate limiting provides sufficient protection
        
        // Allow manual cache clearing only for admins with nonce verification
        if ( isset( $_GET['clear_cache'] ) && $_GET['clear_cache'] === '1' ) {
            // Check if user is admin
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( 'Unauthorized' );
            }
            
            // Verify nonce
            if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'mbr_clear_cache' ) ) {
                wp_send_json_error( 'Invalid security token' );
            }
            
            global $wpdb;
            // Use prepared statement for cache clearing
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                    $wpdb->esc_like( '_transient_mbr_' ) . '%',
                    $wpdb->esc_like( '_transient_timeout_mbr_' ) . '%'
                )
            );
            error_log( "MBR: Cache cleared by admin user" );
        }
        
        // Get stream URL and validate
        $stream_url = isset( $_GET['stream_url'] ) ? rawurldecode( wp_unslash( $_GET['stream_url'] ) ) : '';
        
        if ( empty( $stream_url ) ) {
            wp_send_json_error( 'No stream URL provided' );
        }
        
        // Validate URL to prevent SSRF
        if ( ! $this->is_valid_stream_url( $stream_url ) ) {
            wp_send_json_error( 'Invalid or unsafe stream URL' );
        }
        
        // Check rate limit
        $client_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
        if ( ! $this->check_rate_limit( $client_ip ) ) {
            wp_send_json_error( 'Rate limit exceeded' );
        }
        
        // Check if this is a SomaFM stream - they have a JSON API!
        if ( stripos( $stream_url, 'somafm.com' ) !== false ) {
            $metadata = $this->fetch_somafm_metadata( $stream_url );
            if ( $metadata && ! empty( $metadata['title'] ) ) {
                wp_send_json_success( $this->normalize_metadata( $metadata ) );
                return;
            }
        }
        
        // Check cache (populated by the main streaming connection)
        $stream_key = 'mbr_lrp_metadata_' . md5( $stream_url );
        $metadata = get_transient( $stream_key );
        
        if ( $metadata && ! empty( $metadata['title'] ) ) {
            wp_send_json_success( $this->normalize_metadata( $metadata ) );
            return;
        }
        
        // No metadata in cache - try to fetch it directly from the stream
        $metadata = $this->fetch_icecast_metadata( $stream_url );
        
        if ( $metadata && ! empty( $metadata['title'] ) ) {
            // Cache it for future requests
            set_transient( $stream_key, $metadata, 30 ); // 30 seconds
            wp_send_json_success( $this->normalize_metadata( $metadata ) );
            return;
        }
        
        // No metadata available - return empty
        wp_send_json_success( array( 'title' => '', 'url' => '', 'timestamp' => 0 ) );
    }
    
    /**
     * Normalize metadata to ensure all required fields are present
     */
    private function normalize_metadata( $metadata ) {
        return array(
            'title' => isset( $metadata['title'] ) ? sanitize_text_field( $metadata['title'] ) : '',
            'url' => isset( $metadata['url'] ) ? esc_url_raw( $metadata['url'] ) : '',
            'timestamp' => isset( $metadata['timestamp'] ) ? absint( $metadata['timestamp'] ) : time()
        );
    }
    
    /**
     * Fetch metadata from SomaFM JSON API
     */
    private function fetch_somafm_metadata( $stream_url ) {
        // Extract station name from URL
        // Examples:
        // http://ice1.somafm.com/groovesalad-128-mp3 -> groovesalad
        // https://ice2.somafm.com/secretagent-128-aac -> secretagent
        
        if ( preg_match( '/somafm\.com\/([a-z0-9]+)-/i', $stream_url, $matches ) ) {
            $station = sanitize_text_field( $matches[1] );
        } else {
            return false;
        }
        
        // Fetch from SomaFM's JSON API
        $api_url = 'https://somafm.com/songs/' . $station . '.json';
        
        $response = wp_remote_get( $api_url, array(
            'timeout' => 5,
            'sslverify' => true
        ) );
        
        if ( is_wp_error( $response ) ) {
            return false;
        }
        
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        
        if ( empty( $data ) || ! isset( $data['songs'] ) || empty( $data['songs'] ) ) {
            return false;
        }
        
        // Get the most recent song (first in array)
        $current_song = $data['songs'][0];
        
        // Format: "Artist - Title" or just "Title" if no artist
        $title_parts = array();
        if ( ! empty( $current_song['artist'] ) ) {
            $title_parts[] = trim( $current_song['artist'] );
        }
        if ( ! empty( $current_song['title'] ) ) {
            $title_parts[] = trim( $current_song['title'] );
        }
        
        $metadata = array(
            'title' => implode( ' - ', $title_parts ),
            'url' => '', // Don't use buyurl for artwork (it's an iTunes link)
            'timestamp' => time()
        );
        
        // Try to get album art from SomaFM's image URLs
        if ( ! empty( $current_song['albumart'] ) ) {
            $metadata['url'] = esc_url_raw( $current_song['albumart'] );
        }
        
        // Cache it
        $stream_key = 'mbr_lrp_metadata_' . md5( $stream_url );
        set_transient( $stream_key, $metadata, 30 );
        
        return $metadata;
    }
    
    /**
     * Fetch metadata from Icecast stream using dedicated metadata proxy
     */
    private function fetch_icecast_metadata( $stream_url ) {
        // Call the metadata proxy endpoint using rewrite rules
        $metadata_proxy_url = home_url( '/mbr-metadata-proxy/?url=' . rawurlencode( $stream_url ) );
        
        // Use WordPress HTTP API
        $response = wp_remote_get( $metadata_proxy_url, array(
            'timeout' => 15,
            'sslverify' => false,
            'httpversion' => '1.1'
        ));
        
        if ( is_wp_error( $response ) ) {
            return false;
        }
        
        $http_code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        
        $data = json_decode( $body, true );
        
        if ( ! $data || ! isset( $data['success'] ) || ! $data['success'] ) {
            return false;
        }
        
        return $data['data'];
    }
    
    /**
     * Register proxy endpoint
     */
    public function register_proxy_endpoint() {
        // Main stream proxy
        add_rewrite_rule( '^mbr-radio-proxy/?$', 'index.php?mbr_radio_proxy=1', 'top' );
        
        // Metadata proxy endpoint
        add_rewrite_rule( '^mbr-metadata-proxy/?$', 'index.php?mbr_metadata_proxy=1', 'top' );
        
        // Register query vars
        add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
    }
    
    /**
     * Add query vars
     */
    public function add_query_vars( $vars ) {
        $vars[] = 'mbr_radio_proxy';
        $vars[] = 'mbr_metadata_proxy';
        return $vars;
    }
    
    /**
     * Handle proxy request
     */
    public function handle_proxy_request() {
        // Check if this is a proxy request
        $is_proxy = get_query_var( 'mbr_radio_proxy' );
        $is_metadata = get_query_var( 'mbr_metadata_proxy' );
        
        if ( $is_proxy ) {
            $this->handle_stream_proxy();
        } elseif ( $is_metadata ) {
            $this->handle_metadata_proxy();
        }
    }
    
    /**
     * Handle metadata proxy request
     */
    private function handle_metadata_proxy() {
        // Include the standalone metadata proxy
        $metadata_proxy_path = plugin_dir_path( dirname( __FILE__ ) ) . 'proxy-metadata.php';
        
        if ( file_exists( $metadata_proxy_path ) ) {
            // The proxy file handles everything and exits
            require_once( $metadata_proxy_path );
        } else {
            status_header( 500 );
            header( 'Content-Type: application/json' );
            echo json_encode( array(
                'success' => false,
                'error' => 'Metadata proxy file not found'
            ));
            exit;
        }
    }
    
    /**
     * Fetch metadata from stream headers
     */
    private function fetch_stream_metadata( $url ) {
        // Request just headers with Icy-MetaData flag
        $response = wp_remote_get( $url, array(
            'timeout' => 10,
            'sslverify' => true,
            'stream' => false,
            'headers' => array(
                'Icy-MetaData' => '1',
                'User-Agent' => 'MBR Live Radio Player'
            )
        ));
        
        if ( is_wp_error( $response ) ) {
            return false;
        }
        
        // Check for Icecast/Shoutcast headers
        $icy_name = wp_remote_retrieve_header( $response, 'icy-name' );
        $icy_description = wp_remote_retrieve_header( $response, 'icy-description' );
        
        if ( ! empty( $icy_name ) || ! empty( $icy_description ) ) {
            $title = ! empty( $icy_name ) ? $icy_name : $icy_description;
            return array(
                'title' => sanitize_text_field( $title ),
                'url' => '',
                'timestamp' => time()
            );
        }
        
        return false;
    }
    
    /**
     * Handle stream proxy request
     */
    private function handle_stream_proxy() {
        // Get and validate URL
        $url = isset( $_GET['url'] ) ? rawurldecode( wp_unslash( $_GET['url'] ) ) : '';
        
        if ( empty( $url ) ) {
            status_header( 400 );
            echo esc_html( 'No URL provided' );
            exit;
        }
        
        // Validate URL to prevent SSRF
        if ( ! $this->is_valid_stream_url( $url ) ) {
            status_header( 403 );
            echo esc_html( 'Invalid or unsafe URL' );
            exit;
        }
        
        // Check rate limit (but skip for HLS manifests which reload frequently)
        $is_hls_manifest = stripos( $url, '.m3u8' ) !== false || stripos( $url, '.m3u' ) !== false;
        if ( ! $is_hls_manifest ) {
            $client_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
            if ( ! $this->check_rate_limit( $client_ip . '_stream' ) ) {
                status_header( 429 );
                echo esc_html( 'Rate limit exceeded. Please try again later.' );
                exit;
            }
        }
        
        // Check if this is a playlist fetch request (has playlist=1 parameter)
        $is_playlist_request = isset( $_GET['playlist'] ) && $_GET['playlist'] === '1';
        
        // Check if this is a playlist file OR explicit playlist request
        if ( $is_playlist_request || $this->is_playlist_url( $url ) ) {
            $this->return_playlist_content( $url );
            exit;
        }
        
        // Check for Shoutcast URL that needs fixing
        if ( stripos( $url, ':8000' ) !== false && stripos( $url, '/stream' ) === false && stripos( $url, '.mp3' ) === false ) {
            $url = $this->fix_shoutcast_url( $url );
        }
        
        // Stream the content
        $this->stream_audio( $url );
        exit;
    }
    
    /**
     * Check if URL is a playlist file
     */
    private function is_playlist_url( $url ) {
        $ext = strtolower( pathinfo( parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
        return in_array( $ext, array( 'm3u', 'm3u8', 'pls' ), true );
    }
    
    /**
     * Stream audio content - smart routing
     */
    private function stream_audio( $url ) {
        // Parse URL to check what we're dealing with
        $parsed = parse_url( $url );
        $path = isset( $parsed['path'] ) ? $parsed['path'] : '';
        
        // Get file extension if present
        $ext = '';
        if ( ! empty( $path ) ) {
            $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        }
        
        // Check if this is a Shoutcast stream (ends with /;)
        $is_shoutcast = substr( $path, -2 ) === '/;';
        
        // For Shoutcast, we MUST proxy (browsers can't connect directly)
        if ( $is_shoutcast ) {
            // Remove the /; and try to fix the URL to find the actual stream
            $clean_url = substr( $url, 0, -2 );
            $fixed_url = $this->fix_shoutcast_url( $clean_url );
            $this->stream_with_passthru( $fixed_url );
            return;
        }
        
        // Check if it's a file type that should be proxied (HLS segments, playlists)
        $is_file_to_proxy = in_array( $ext, array( 'ts', 'm3u8', 'm3u' ), true );
        
        if ( $is_file_to_proxy ) {
            // For file-based requests (HLS segments, playlists), proxy them
            $args = array(
                'timeout' => 30,
                'sslverify' => true,
                'headers' => array(
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                ),
            );
            
            $response = wp_remote_get( $url, $args );
            
            if ( is_wp_error( $response ) ) {
                status_header( 500 );
                echo esc_html( 'Failed to fetch: ' . $response->get_error_message() );
                return;
            }
            
            // Get content type
            $content_type = wp_remote_retrieve_header( $response, 'content-type' );
            if ( empty( $content_type ) ) {
                // Guess based on extension
                if ( stripos( $url, '.ts' ) !== false ) {
                    $content_type = 'video/mp2t';
                } elseif ( stripos( $url, '.m3u8' ) !== false || stripos( $url, '.m3u' ) !== false ) {
                    $content_type = 'application/vnd.apple.mpegurl';
                } else {
                    $content_type = 'application/octet-stream';
                }
            }
            
            // Set headers
            status_header( wp_remote_retrieve_response_code( $response ) );
            header( 'Content-Type: ' . sanitize_text_field( $content_type ) );
            header( 'Cache-Control: public, max-age=60' );
            header( 'Access-Control-Allow-Origin: *' );
            
            // Output body
            echo wp_remote_retrieve_body( $response );
            exit;
        }
        
        // For everything else (Icecast, direct streams)
        // If it's HTTP, we need to proxy it for HTTPS sites
        // If it's already HTTPS, browser can handle it directly
        $parsed_url = parse_url( $url );
        if ( isset( $parsed_url['scheme'] ) && $parsed_url['scheme'] === 'http' ) {
            // HTTP stream needs proxying for HTTPS compatibility
            $this->stream_with_passthru( $url );
        } else {
            // HTTPS stream - browser can handle directly
            wp_redirect( $url, 302 );
            exit;
        }
    }
    
    /**
     * Fix Shoutcast stream URLs that point to server root instead of stream
     * Shoutcast servers return HTML when accessing the root, but audio at /; or /stream
     */
    private function fix_shoutcast_url( $url ) {
        // Parse the URL
        $parsed = parse_url( $url );
        
        if ( ! $parsed || empty( $parsed['host'] ) ) {
            return $url;
        }
        
        // Check if this looks like a Shoutcast server (has port, no path or root path)
        $has_port = ! empty( $parsed['port'] );
        $is_root = empty( $parsed['path'] ) || $parsed['path'] === '/';
        
        if ( ! $has_port || ! $is_root ) {
            return $url; // Not a typical Shoutcast base URL
        }
        
        // Try common Shoutcast stream paths in order of likelihood
        $paths_to_try = array(
            '/;',              // Default Shoutcast stream endpoint
            '/stream',         // Common alternative
            '/;stream.mp3',    // Explicit format
            '/;stream.nsv',    // Nullsoft streaming video/audio
        );
        
        foreach ( $paths_to_try as $path ) {
            $test_url = $parsed['scheme'] . '://' . $parsed['host'];
            if ( ! empty( $parsed['port'] ) ) {
                $test_url .= ':' . $parsed['port'];
            }
            $test_url .= $path;
            
            // Quick test: fetch just headers to see if this returns audio
            $response = wp_remote_head( $test_url, array(
                'timeout' => 5,
                'sslverify' => false,
                'redirection' => 0, // Don't follow redirects
                'headers' => array(
                    'Icy-MetaData' => '1',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                )
            ) );
            
            if ( is_wp_error( $response ) ) {
                continue;
            }
            
            $content_type = wp_remote_retrieve_header( $response, 'content-type' );
            $icy_name = wp_remote_retrieve_header( $response, 'icy-name' );
            
            // Check if this returns audio content or has ICY headers (Shoutcast/Icecast indicator)
            if ( 
                stripos( $content_type, 'audio' ) !== false || 
                stripos( $content_type, 'mpeg' ) !== false ||
                stripos( $content_type, 'ogg' ) !== false ||
                ! empty( $icy_name )
            ) {
                error_log( "MBR Proxy: Fixed Shoutcast URL from {$url} to {$test_url}" );
                return $test_url;
            }
        }
        
        // If none of the paths worked, return the original URL
        error_log( "MBR Proxy: Could not find working stream path for {$url}" );
        return $url;
    }
    
    /**
     * Stream Shoutcast using cURL with proper streaming
     */
    private function stream_with_passthru( $url ) {
        error_log( "MBR Proxy: stream_with_passthru called with URL: {$url}" );
        
        // First, do a quick check to see if the stream is accessible
        $test_ch = curl_init( $url );
        curl_setopt( $test_ch, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $test_ch, CURLOPT_HEADER, true );
        curl_setopt( $test_ch, CURLOPT_NOBODY, true );
        curl_setopt( $test_ch, CURLOPT_TIMEOUT, 5 );
        curl_setopt( $test_ch, CURLOPT_SSL_VERIFYPEER, false );
        curl_setopt( $test_ch, CURLOPT_HTTPHEADER, array(
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ));
        curl_exec( $test_ch );
        $http_code = curl_getinfo( $test_ch, CURLINFO_HTTP_CODE );
        curl_close( $test_ch );
        
        // If we get 403 or 401, the stream is blocking server connections
        // Redirect to the stream directly - browsers may have better luck
        if ( $http_code == 403 || $http_code == 401 ) {
            error_log( "MBR Proxy: Stream returned HTTP {$http_code} - server blocked. Redirecting browser to connect directly." );
            wp_redirect( $url, 302 );
            exit;
        }
        
        // Disable all output buffering BEFORE anything else
        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }
        
        // Set PHP INI settings
        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', 0);
        @ini_set('implicit_flush', 1);
        
        // CRITICAL: Set max execution time to unlimited for streaming
        @set_time_limit(0);
        @ini_set('max_execution_time', 0);
        
        // Disable WordPress actions that might buffer output
        remove_action('shutdown', 'wp_ob_end_flush_all', 1);
        
        // Apache settings
        if ( function_exists( 'apache_setenv' ) ) {
            @apache_setenv('no-gzip', '1');
        }
        
        // Initialize cURL
        error_log( "MBR Proxy: Initializing cURL for URL: {$url}" );
        $ch = curl_init( $url );
        
        if ( ! $ch ) {
            error_log( "MBR Proxy: FAILED to initialize cURL!" );
            status_header( 500 );
            die( 'Failed to initialize stream' );
        }
        
        // Force HTTP/1.1 to avoid HTTP/2 protocol issues
        curl_setopt( $ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1 );
        
        // Set cURL options for streaming
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, false );
        curl_setopt( $ch, CURLOPT_BINARYTRANSFER, true );
        curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
        curl_setopt( $ch, CURLOPT_MAXREDIRS, 5 );
        curl_setopt( $ch, CURLOPT_TIMEOUT, 0 );
        curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 15 );
        curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
        curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false );
        curl_setopt( $ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36' );
        curl_setopt( $ch, CURLOPT_BUFFERSIZE, 8192 );
        
        // Don't request Icecast metadata - keep stream pure audio only
        curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
            'Connection: close'
        ));
        
        // Get headers from stream
        $headers_sent = false;
        $content_type = 'audio/mpeg';
        
        curl_setopt( $ch, CURLOPT_HEADERFUNCTION, function( $curl, $header ) use ( &$content_type, &$headers_sent ) {
            $len = strlen( $header );
            
            if ( stripos( $header, 'Content-Type:' ) === 0 ) {
                $parts = explode( ':', $header, 2 );
                if ( isset( $parts[1] ) ) {
                    $content_type = trim( $parts[1] );
                    
                    // Normalize AAC+ variants to standard audio/aac for better browser compatibility
                    if ( stripos( $content_type, 'aacp' ) !== false || stripos( $content_type, 'aac+' ) !== false ) {
                        $content_type = 'audio/aac';
                        error_log( 'MBR Proxy: Normalized AAC+ to: ' . $content_type );
                    }
                    
                    error_log( 'MBR Proxy: Stream Content-Type: ' . $content_type );
                }
            }
            
            // Log any redirect or location headers
            if ( stripos( $header, 'Location:' ) === 0 ) {
                error_log( 'MBR Proxy: Redirect detected: ' . trim( $header ) );
            }
            
            // Send our headers after we've received the stream's headers
            if ( ! $headers_sent && trim( $header ) === '' ) {
                // End of headers, send ours now
                if ( ! headers_sent() ) {
                    error_log( 'MBR Proxy: Sending response with Content-Type: ' . $content_type );
                    status_header( 200 );
                    header( 'Content-Type: ' . $content_type );
                    header( 'Cache-Control: no-cache, no-store, must-revalidate' );
                    header( 'Pragma: no-cache' );
                    header( 'Expires: 0' );
                    header( 'Accept-Ranges: none' );
                    header( 'Connection: close' );
                    header( 'X-Accel-Buffering: no' );
                    header( 'Access-Control-Allow-Origin: *' );
                }
                $headers_sent = true;
            }
            
            return $len;
        });
        
        // Simple write callback - pure audio passthrough
        $bytes_written = 0;
        $first_bytes_logged = false;
        
        curl_setopt( $ch, CURLOPT_WRITEFUNCTION, function( $ch, $data ) use ( &$bytes_written, &$first_bytes_logged ) {
            $data_len = strlen( $data );
            
            // Log first 16 bytes for debugging
            if ( ! $first_bytes_logged && $data_len > 0 ) {
                $first_bytes = substr( $data, 0, min( 16, $data_len ) );
                $hex = bin2hex( $first_bytes );
                error_log( "MBR Proxy: First bytes (pure audio): {$hex}" );
                $first_bytes_logged = true;
            }
            
            // Just output the data directly
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw binary stream data
            echo $data;
            $bytes_written += $data_len;
            
            // Flush every 16KB for smooth streaming
            if ( $bytes_written >= 16384 ) {
                if ( ob_get_level() > 0 ) {
                    @ob_flush();
                }
                @flush();
                $bytes_written = 0;
            }
            
            return $data_len;
        });
        
        // Execute streaming
        error_log( "MBR Proxy: Starting cURL execution for: {$url}" );
        curl_exec( $ch );
        
        $curl_error = curl_errno( $ch );
        if ( $curl_error ) {
            error_log( "MBR Proxy: cURL error #{$curl_error}: " . curl_error( $ch ) );
        }
        
        curl_close( $ch );
        exit;
    }
    
    /**
     * Stream Shoutcast/Icecast audio using cURL for proper chunked streaming
     */
    
    /**
     * Return playlist content as text
     */
    private function return_playlist_content( $url ) {
        $response = wp_remote_get( $url, array(
            'timeout' => 10,
            'sslverify' => true,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ) );
        
        if ( is_wp_error( $response ) ) {
            status_header( 500 );
            echo esc_html( 'Failed to fetch playlist: ' . $response->get_error_message() );
            exit;
        }
        
        $body = wp_remote_retrieve_body( $response );
        
        if ( empty( $body ) ) {
            status_header( 500 );
            echo esc_html( 'Empty playlist response' );
            exit;
        }
        
        // Return as plain text with security headers
        header( 'Content-Type: text/plain; charset=utf-8' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Access-Control-Allow-Origin: *' );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw playlist content
        echo $body;
        exit;
    }
    
    /**
     * Add settings page
     */
    public function add_settings_page() {
        add_submenu_page(
            'edit.php?post_type=mbr_radio_station',
            __( 'Proxy Settings', 'mbr-live-radio-player' ),
            __( 'Proxy Settings', 'mbr-live-radio-player' ),
            'manage_options',
            'mbr-radio-proxy-settings',
            array( $this, 'render_settings_page' )
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting( 'mbr_lrp_proxy_settings', 'mbr_lrp_proxy_enabled', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field'
        ) );
        register_setting( 'mbr_lrp_proxy_settings', 'mbr_lrp_require_proxy', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field'
        ) );
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Stream Proxy Settings', 'mbr-live-radio-player' ); ?></h1>
            
            <div class="notice notice-info">
                <p>
                    <strong><?php esc_html_e( 'Why do I need this?', 'mbr-live-radio-player' ); ?></strong><br>
                    <?php esc_html_e( 'Most radio streams use HTTP, but your WordPress site uses HTTPS. Modern browsers block HTTP content on HTTPS pages for security. This proxy converts HTTP streams to HTTPS so they play correctly.', 'mbr-live-radio-player' ); ?>
                </p>
            </div>
            
            <form method="post" action="options.php">
                <?php settings_fields( 'mbr_lrp_proxy_settings' ); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <?php esc_html_e( 'Enable Stream Proxy', 'mbr-live-radio-player' ); ?>
                        </th>
                        <td>
                            <label>
                                <input 
                                    type="checkbox" 
                                    name="mbr_lrp_proxy_enabled" 
                                    value="1"
                                    <?php checked( get_option( 'mbr_lrp_proxy_enabled', '1' ), '1' ); ?>
                                />
                                <?php esc_html_e( 'Enable proxy for HTTP streams', 'mbr-live-radio-player' ); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e( 'Recommended: Keep this enabled to play HTTP streams on your HTTPS site.', 'mbr-live-radio-player' ); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <?php esc_html_e( 'Proxy Mode', 'mbr-live-radio-player' ); ?>
                        </th>
                        <td>
                            <label>
                                <input 
                                    type="radio" 
                                    name="mbr_lrp_require_proxy" 
                                    value="http_only"
                                    <?php checked( get_option( 'mbr_lrp_require_proxy', 'http_only' ), 'http_only' ); ?>
                                />
                                <?php esc_html_e( 'Proxy HTTP streams only (Recommended)', 'mbr-live-radio-player' ); ?>
                            </label>
                            <br>
                            <label>
                                <input 
                                    type="radio" 
                                    name="mbr_lrp_require_proxy" 
                                    value="all"
                                    <?php checked( get_option( 'mbr_lrp_require_proxy', 'http_only' ), 'all' ); ?>
                                />
                                <?php esc_html_e( 'Proxy all streams', 'mbr-live-radio-player' ); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e( 'HTTP streams need proxying on HTTPS sites. HTTPS streams work directly without proxy.', 'mbr-live-radio-player' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <hr>
            
            <h2><?php esc_html_e( 'Test Your Proxy', 'mbr-live-radio-player' ); ?></h2>
            <p><?php esc_html_e( 'Use this URL format for HTTP streams:', 'mbr-live-radio-player' ); ?></p>
            <code><?php echo esc_url( home_url( '/mbr-radio-proxy/?url=' ) ); ?>[YOUR_STREAM_URL]</code>
            
            <h3><?php esc_html_e( 'Example Streams to Test', 'mbr-live-radio-player' ); ?></h3>
            <ul>
                <li><strong>Capital UK (HTTP):</strong> <code>http://media-ice.musicradio.com/CapitalMP3</code></li>
                <li><strong>Classic FM (HTTP):</strong> <code>http://media-ice.musicradio.com/ClassicFMMP3</code></li>
                <li><strong>Absolute Radio (HTTP):</strong> <code>http://ais.absoluteradio.co.uk/absoluteradio.mp3</code></li>
            </ul>
        </div>
        <?php
    }
}
