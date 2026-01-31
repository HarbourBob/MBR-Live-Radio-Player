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
    
    /**
     * AJAX handler to get current metadata
     */
    public function ajax_get_metadata() {
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
        // Call the metadata proxy endpoint
        $metadata_proxy_url = home_url( '/mbr-metadata-proxy/?url=' . rawurlencode( $stream_url ) );
        
        // Use WordPress HTTP API
        $response = wp_remote_get( $metadata_proxy_url, array(
            'timeout' => 10,
            'sslverify' => true,
            'httpversion' => '1.1'
        ));
        
        if ( is_wp_error( $response ) ) {
            return false;
        }
        
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        
        if ( ! $data || ! isset( $data['title'] ) || empty( $data['title'] ) ) {
            return false;
        }
        
        return $data;
    }
    
    /**
     * Register proxy endpoint
     */
    public function register_proxy_endpoint() {
        // Main stream proxy
        add_rewrite_rule( '^mbr-radio-proxy/?$', 'index.php?mbr_radio_proxy=1', 'top' );
        add_filter( 'query_vars', function( $vars ) {
            $vars[] = 'mbr_radio_proxy';
            return $vars;
        });
        
        // Metadata proxy endpoint
        add_rewrite_rule( '^mbr-metadata-proxy/?$', 'index.php?mbr_metadata_proxy=1', 'top' );
        add_filter( 'query_vars', function( $vars ) {
            $vars[] = 'mbr_metadata_proxy';
            return $vars;
        });
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
        // Get and validate URL
        $url = isset( $_GET['url'] ) ? rawurldecode( wp_unslash( $_GET['url'] ) ) : '';
        
        if ( empty( $url ) ) {
            status_header( 400 );
            wp_send_json_error( 'No URL provided' );
        }
        
        // Validate URL to prevent SSRF
        if ( ! $this->is_valid_stream_url( $url ) ) {
            status_header( 403 );
            wp_send_json_error( 'Invalid or unsafe URL' );
        }
        
        // Check rate limit
        $client_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
        if ( ! $this->check_rate_limit( $client_ip . '_metadata' ) ) {
            status_header( 429 );
            wp_send_json_error( 'Rate limit exceeded' );
        }
        
        // Try to fetch metadata from the stream
        $metadata = $this->fetch_stream_metadata( $url );
        
        if ( $metadata ) {
            wp_send_json_success( $metadata );
        } else {
            wp_send_json_error( 'Could not fetch metadata' );
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
        
        // Check rate limit
        $client_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
        if ( ! $this->check_rate_limit( $client_ip . '_stream' ) ) {
            status_header( 429 );
            echo esc_html( 'Rate limit exceeded. Please try again later.' );
            exit;
        }
        
        // Check if this is a playlist file
        if ( $this->is_playlist_url( $url ) ) {
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
     * Stream audio content
     */
    private function stream_audio( $url ) {
        // Set up stream context with headers
        $args = array(
            'timeout' => 0, // No timeout for streaming
            'sslverify' => true,
            'stream' => true,
            'filename' => wp_tempnam(),
            'headers' => array(
                'Icy-MetaData' => '1',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            )
        );
        
        // Make request
        $response = wp_remote_get( $url, $args );
        
        if ( is_wp_error( $response ) ) {
            status_header( 500 );
            echo esc_html( 'Failed to connect to stream: ' . $response->get_error_message() );
            return;
        }
        
        // Get content type
        $content_type = wp_remote_retrieve_header( $response, 'content-type' );
        if ( empty( $content_type ) ) {
            $content_type = 'audio/mpeg'; // Default
        }
        
        // Set headers for streaming
        header( 'Content-Type: ' . sanitize_text_field( $content_type ) );
        header( 'Cache-Control: no-cache, no-store, must-revalidate' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );
        header( 'Access-Control-Allow-Origin: *' );
        
        // Forward ICY headers if present
        $icy_headers = array( 'icy-name', 'icy-genre', 'icy-url', 'icy-br', 'icy-metaint' );
        foreach ( $icy_headers as $header ) {
            $value = wp_remote_retrieve_header( $response, $header );
            if ( ! empty( $value ) ) {
                header( $header . ': ' . sanitize_text_field( $value ) );
            }
        }
        
        // Get the temporary file
        $filename = $response['filename'];
        if ( file_exists( $filename ) ) {
            readfile( $filename );
            unlink( $filename );
        }
    }
    
    /**
     * Fix Shoutcast URL by trying common stream paths
     */
    private function fix_shoutcast_url( $url ) {
        $parsed = parse_url( $url );
        
        if ( ! $parsed || ! isset( $parsed['host'] ) ) {
            return $url;
        }
        
        // Common Shoutcast/Icecast stream paths
        $paths_to_try = array(
            '/stream',
            '/;stream.mp3',
            '/;stream.nsv',
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
                'sslverify' => true,
                'redirection' => 0,
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
            
            // Check if this returns audio content or has ICY headers
            if ( 
                stripos( $content_type, 'audio' ) !== false || 
                stripos( $content_type, 'mpeg' ) !== false ||
                stripos( $content_type, 'ogg' ) !== false ||
                ! empty( $icy_name )
            ) {
                return $test_url;
            }
        }
        
        return $url;
    }
    
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
        echo wp_kses_post( $body );
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
