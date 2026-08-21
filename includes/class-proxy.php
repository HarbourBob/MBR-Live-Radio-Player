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
     * User agent used for every outbound proxy request.
     */
    const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    /**
     * Hard ceiling, in seconds, on a single proxied stream connection.
     *
     * Streaming used to run with CURLOPT_TIMEOUT 0 and set_time_limit(0), so a
     * visitor could pin a PHP worker open indefinitely. Two hours is longer
     * than any realistic listening session while still guaranteeing the worker
     * is eventually released; the player reconnects transparently.
     * Filter: mbr_lrp_max_stream_seconds
     */
    const MAX_STREAM_SECONDS = 7200;

    /**
     * Maximum simultaneous proxied streams permitted per client IP.
     *
     * This has to be generous. One page can legitimately hold several streams
     * at once -- an embedded player, a sticky bar and a pop-out window are
     * three on their own, before a second tab or a household sharing one IP.
     * The cap exists to stop a script opening hundreds of connections, not to
     * ration ordinary listening.
     * Filter: mbr_lrp_max_concurrent_streams (0 disables the cap entirely)
     */
    const MAX_CONCURRENT_STREAMS = 10;

    /**
     * How long a concurrency slot survives without a heartbeat, in seconds.
     *
     * Deliberately short and independent of MAX_STREAM_SECONDS. A slot is
     * refreshed while audio is still flowing, so a genuine long listen keeps
     * its place; a slot left behind by a dropped connection or a killed worker
     * clears itself within minutes rather than lingering for hours.
     */
    const SLOT_TTL = 300;

    /**
     * Seconds between slot heartbeats while streaming.
     */
    const SLOT_HEARTBEAT = 60;

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

        // Keep the cached station host allowlist in step with station edits.
        add_action( 'save_post_mbr_radio_station', array( $this, 'flush_station_host_cache' ) );
        add_action( 'deleted_post', array( $this, 'flush_station_host_cache' ) );
    }

    /**
     * Clear the cached list of configured station hosts.
     *
     * @return void
     */
    public function flush_station_host_cache() {
        delete_transient( 'mbr_lrp_station_hosts' );
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
     * Write a proxy diagnostic message to the PHP error log.
     *
     * SECURITY: stream URLs frequently carry access tokens, signed query
     * parameters or embedded credentials, so nothing is logged on a normal
     * production site. When WP_DEBUG is on, any URL passed as context is
     * reduced to scheme://host/path -- the query string and any userinfo are
     * discarded before the message is written.
     *
     * @param string $message Short, fixed description of the event.
     * @param string $context Optional URL or hostname for context.
     * @return void
     */
    private function debug_log( $message, $context = '' ) {
        if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
            return;
        }

        if ( $context !== '' ) {
            $parsed = wp_parse_url( $context );
            if ( is_array( $parsed ) && isset( $parsed['host'] ) ) {
                $context = ( isset( $parsed['scheme'] ) ? $parsed['scheme'] . '://' : '' )
                    . $parsed['host']
                    . ( isset( $parsed['port'] ) ? ':' . $parsed['port'] : '' )
                    . ( isset( $parsed['path'] ) ? $parsed['path'] : '' );
            }
            $message .= ' [' . $context . ']';
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Gated behind WP_DEBUG.
        error_log( 'MBR Proxy: ' . $message );
    }

    /**
     * Determine whether a literal IP address is safe to connect to.
     *
     * Rejects loopback, private, link-local, reserved and cloud metadata
     * addresses for both IPv4 and IPv6, including IPv4-mapped IPv6 forms.
     *
     * @param string $ip IP address to test.
     * @return bool True if the address is a safe public address.
     */
    private function is_safe_ip( $ip ) {
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return false;
        }

        // Cloud metadata services.
        if ( $ip === '169.254.169.254' || strtolower( $ip ) === 'fd00:ec2::254' ) {
            return false;
        }

        // IPv4-mapped and IPv4-compatible IPv6 (e.g. ::ffff:127.0.0.1) are
        // unwrapped so the underlying IPv4 address is judged on its own merits.
        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
            $packed = @inet_pton( $ip );
            if ( $packed !== false && strlen( $packed ) === 16 ) {
                $hex = bin2hex( $packed );

                if ( substr( $hex, 0, 24 ) === str_repeat( '0', 20 ) . 'ffff'
                    || ( substr( $hex, 0, 24 ) === str_repeat( '0', 24 ) && substr( $hex, 24 ) !== str_repeat( '0', 8 ) ) ) {
                    $mapped = long2ip( hexdec( substr( $hex, 24, 8 ) ) );
                    return $this->is_safe_ip( $mapped );
                }
            }
        }

        // Blocks ::1, fc00::/7 (unique local), fe80::/10 (link-local),
        // 10/8, 172.16/12, 192.168/16, 127/8, 0/8, 169.254/16 and friends.
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * Resolve a relative Location header against the URL that produced it.
     *
     * @param string $base     Absolute URL the redirect came from.
     * @param string $relative Relative or root-relative target.
     * @return string|false Absolute URL, or false if it cannot be built.
     */
    private function resolve_relative_url( $base, $relative ) {
        $parts = wp_parse_url( $base );
        if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
            return false;
        }

        $origin = $parts['scheme'] . '://' . $parts['host']
            . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );

        if ( strpos( $relative, '//' ) === 0 ) {
            return $parts['scheme'] . ':' . $relative;
        }

        if ( strpos( $relative, '/' ) === 0 ) {
            return $origin . $relative;
        }

        $dir = isset( $parts['path'] ) ? rtrim( dirname( $parts['path'] ), '/' ) : '';
        return $origin . $dir . '/' . $relative;
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
        
        // Bracketed IPv6 literals arrive from parse_url as "[::1]".
        $host_ip = trim( $host, '[]' );

        // When the URL uses a literal IP, judge that address directly.
        if ( filter_var( $host_ip, FILTER_VALIDATE_IP ) ) {
            if ( ! $this->is_safe_ip( $host_ip ) ) {
                return false;
            }
        }
        
        // For hostnames, resolve DNS and reject if ANY resolved address is
        // private or reserved.
        //
        // SECURITY: earlier versions allowed a list of "trusted" streaming
        // domains to skip this check entirely. That was unsafe -- several of
        // those providers hand out user-controlled subdomains, and DNS records
        // can be repointed at any time, which is precisely the DNS-rebinding
        // case SSRF validation exists to defend against. Every hostname is now
        // resolved and every resulting address is checked, with no exceptions.
        if ( ! filter_var( $host, FILTER_VALIDATE_IP ) ) {
            // Suppress errors and warnings for gethostbynamel
            $ips = @gethostbynamel( $host );

            // If DNS resolution fails, block it for security
            if ( $ips === false || empty( $ips ) ) {
                $this->debug_log( 'Failed to resolve hostname', $host );
                return false;
            }

            // Check each resolved IP
            foreach ( $ips as $ip ) {
                if ( ! $this->is_safe_ip( $ip ) ) {
                    $this->debug_log( 'Hostname resolves to a private or reserved address', $host );
                    return false;
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
                $this->debug_log( "Blocked non-standard port {$parsed['port']}", $url );
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
     * @param int    $multiplier Scales the allowance for request types that
     *                           legitimately poll far more often, such as HLS
     *                           manifests. Defaults to the standard allowance.
     * @return bool True if within rate limit, false if exceeded
     */
    private function check_rate_limit( $identifier, $multiplier = 1 ) {
        $multiplier = max( 1, (int) $multiplier );

        // Logged-in administrators are exempt - the limiter exists to deter
        // abuse from anonymous visitors, and tripping it during site testing
        // silently kills metadata.
        if ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
            return true;
        }
        
        // Check if temporarily blocked first (most efficient check)
        $blocked_key = 'mbr_blocked_' . md5( $identifier );
        if ( get_transient( $blocked_key ) ) {
            return false;
        }
        
        // Burst protection (per minute)
        if ( ! $this->count_request( 'mbr_rate_short_' . md5( $identifier ), 60 * $multiplier, MINUTE_IN_SECONDS ) ) {
            $this->debug_log( 'Short-term rate limit exceeded' );
            return false;
        }
        
        // Sustained protection (per hour). A single listener polls roughly 120
        // times an hour, and several households can share one IP behind carrier
        // NAT, so the ceiling has to accommodate genuine concurrent listeners.
        if ( ! $this->count_request( 'mbr_rate_long_' . md5( $identifier ), 1200 * $multiplier, HOUR_IN_SECONDS ) ) {
            set_transient( $blocked_key, true, 15 * MINUTE_IN_SECONDS );
            $this->debug_log( 'Long-term rate limit exceeded; client blocked for 15 minutes' );
            return false;
        }
        
        return true;
    }
    
    /**
     * Reserve a concurrent-stream slot for a client IP.
     *
     * Proxied streams are long-lived, so each one occupies a PHP worker for as
     * long as the listener keeps playing. Rate limiting alone does not help
     * here -- a handful of requests is enough to tie up a shared host if each
     * one runs for hours. A small per-IP ceiling keeps ordinary listening
     * (a page, a pop-out, a phone) working while preventing one client from
     * opening dozens of simultaneous connections.
     *
     * Slots carry their own expiry so an abandoned connection cannot leak a
     * slot permanently, and are released explicitly when streaming ends.
     *
     * @param string $identifier Client identifier, normally the IP address.
     * @return bool True if a slot was reserved.
     */
    private function acquire_stream_slot( $identifier ) {
        if ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
            return true;
        }

        $max = (int) apply_filters( 'mbr_lrp_max_concurrent_streams', self::MAX_CONCURRENT_STREAMS );
        if ( $max <= 0 ) {
            return true;
        }

        $key   = 'mbr_streams_' . md5( $identifier );
        $now   = time();
        $slots = get_transient( $key );

        if ( ! is_array( $slots ) ) {
            $slots = array();
        }

        // Drop slots that have missed their heartbeat.
        foreach ( $slots as $token => $expires ) {
            if ( (int) $expires <= $now ) {
                unset( $slots[ $token ] );
            }
        }

        if ( count( $slots ) >= $max ) {
            $this->debug_log( 'Concurrent stream limit reached' );
            return false;
        }

        // Unique per slot. A timestamp is not: two streams starting in the
        // same second would collide, and releasing one would release both.
        $token = uniqid( '', true );

        $slots[ $token ] = $now + self::SLOT_TTL;
        set_transient( $key, $slots, self::SLOT_TTL );

        $this->stream_slot   = array( 'key' => $key, 'token' => $token );
        $this->slot_beat_at  = $now;

        // Release the slot however the request ends -- listener navigating
        // away, connection dropping, or PHP hitting its own limits.
        register_shutdown_function( array( $this, 'release_stream_slot' ) );

        return true;
    }

    /**
     * Currently held concurrency slot, if any.
     *
     * @var array|null
     */
    private $stream_slot = null;

    /**
     * Unix time of the last slot heartbeat.
     *
     * @var int
     */
    private $slot_beat_at = 0;

    /**
     * Extend the held slot while audio is still flowing.
     *
     * Called from the streaming write callback. Cheap: it only touches the
     * database once per SLOT_HEARTBEAT seconds.
     *
     * @return void
     */
    public function heartbeat_stream_slot() {
        if ( ! is_array( $this->stream_slot ) ) {
            return;
        }

        $now = time();
        if ( $now - $this->slot_beat_at < self::SLOT_HEARTBEAT ) {
            return;
        }
        $this->slot_beat_at = $now;

        $slots = get_transient( $this->stream_slot['key'] );
        if ( ! is_array( $slots ) ) {
            $slots = array();
        }

        $slots[ $this->stream_slot['token'] ] = $now + self::SLOT_TTL;
        set_transient( $this->stream_slot['key'], $slots, self::SLOT_TTL );
    }

    /**
     * Release the concurrency slot held by this request.
     *
     * @return void
     */
    public function release_stream_slot() {
        if ( ! is_array( $this->stream_slot ) ) {
            return;
        }

        $key   = $this->stream_slot['key'];
        $token = $this->stream_slot['token'];
        $slots = get_transient( $key );

        if ( is_array( $slots ) ) {
            unset( $slots[ $token ] );

            if ( empty( $slots ) ) {
                delete_transient( $key );
            } else {
                set_transient( $key, $slots, self::SLOT_TTL );
            }
        }

        $this->stream_slot = null;
    }

    /**
     * Count one request against a FIXED time window.
     *
     * Note the deliberate preservation of the original window end. Calling
     * set_transient() with a fresh timeout would push the expiry forward on
     * every request, so a continuously polling listener would never see the
     * window roll over and the "limit" would become a lifetime total. Instead
     * we store the window end alongside the count and shorten the timeout as
     * the window runs down.
     *
     * @return bool true if the request is within the limit.
     */
    private function count_request( $key, $limit, $window ) {
        $now  = time();
        $data = get_transient( $key );
        
        // No window yet, a legacy scalar value, or the window has elapsed
        if ( ! is_array( $data ) || empty( $data['reset'] ) || $data['reset'] <= $now ) {
            set_transient( $key, array( 'count' => 1, 'reset' => $now + $window ), $window );
            return true;
        }
        
        if ( $data['count'] >= $limit ) {
            return false;
        }
        
        $data['count']++;
        set_transient( $key, $data, $data['reset'] - $now );
        
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
            $this->debug_log( 'Cache cleared by admin user' );
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
                wp_send_json_success( $this->maybe_add_artwork( $this->normalize_metadata( $metadata ) ) );
                return;
            }
        }
        
        // Check cache (populated by the main streaming connection)
        $stream_key = 'mbr_lrp_metadata_' . md5( $stream_url );
        $metadata = get_transient( $stream_key );
        
        if ( $metadata && ! empty( $metadata['title'] ) ) {
            wp_send_json_success( $this->maybe_add_artwork( $this->normalize_metadata( $metadata ) ) );
            return;
        }
        
        // No metadata in cache - try to fetch it directly from the stream
        $metadata = $this->fetch_icecast_metadata( $stream_url );
        
        if ( $metadata && ! empty( $metadata['title'] ) ) {
            // Cache it for future requests
            set_transient( $stream_key, $metadata, 30 ); // 30 seconds
            wp_send_json_success( $this->maybe_add_artwork( $this->normalize_metadata( $metadata ) ) );
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
     * If the stream sent no artwork URL, optionally look one up from the
     * iTunes Search API using the "Artist - Title" string. Opt-in via the
     * mbr_lrp_artwork_lookup setting (off by default - external API call).
     */
    private function maybe_add_artwork( $metadata ) {
        // Many stations put a homepage or "buy this track" link in StreamUrl
        // rather than an image. Discard anything that isn't actually artwork
        // so the lookup below can fill the gap instead.
        if ( ! empty( $metadata['url'] ) && ! $this->url_is_image( $metadata['url'] ) ) {
            $metadata['url'] = '';
        }
        
        // Stream provided real artwork, or we have nothing to search with
        if ( ! empty( $metadata['url'] ) || empty( $metadata['title'] ) ) {
            return $metadata;
        }
        
        // Feature is opt-in
        if ( get_option( 'mbr_lrp_artwork_lookup', '0' ) !== '1' ) {
            return $metadata;
        }
        
        $artwork = $this->lookup_track_artwork( $metadata['title'] );
        if ( ! empty( $artwork ) ) {
            $metadata['url'] = $artwork;
        }
        
        return $metadata;
    }
    
    /**
     * Is this URL actually an image? Checks the file extension first (cheap),
     * and falls back to a cached HEAD request for extensionless URLs.
     */
    private function url_is_image( $url ) {
        $path = parse_url( $url, PHP_URL_PATH );
        $ext  = $path ? strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) : '';
        
        if ( $ext !== '' ) {
            return in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp' ), true );
        }
        
        // SECURITY: this URL is supplied by the broadcaster in the stream's own
        // metadata, not by the site owner, so it is untrusted input and must
        // clear SSRF validation before the server will make any request to it.
        // Without this a stream could point the artwork field at an internal
        // address and use the response as a blind port scan.
        if ( ! $this->is_valid_stream_url( $url ) ) {
            return false;
        }

        // No extension - ask the server what it is, and remember the answer
        $cache_key = 'mbr_lrp_imgchk_' . md5( $url );
        $cached = get_transient( $cache_key );
        
        if ( $cached !== false ) {
            return ( $cached === 'yes' );
        }
        
        // wp_safe_remote_head() sets reject_unsafe_urls, so WordPress
        // revalidates the target of every redirect it follows. That restores
        // redirect support for artwork hosts that use one, without handing an
        // untrusted redirect chain to the server unchecked.
        $response = wp_safe_remote_head( $url, array(
            'timeout'     => 3,
            'sslverify'   => true,
            'redirection' => 3,
        ) );
        
        $is_image = false;
        if ( ! is_wp_error( $response ) ) {
            $type = wp_remote_retrieve_header( $response, 'content-type' );
            if ( is_array( $type ) ) {
                $type = reset( $type );
            }
            $is_image = ( is_string( $type ) && stripos( $type, 'image/' ) === 0 );
        }
        
        set_transient( $cache_key, $is_image ? 'yes' : 'no', 6 * HOUR_IN_SECONDS );
        
        return $is_image;
    }
    
    /**
     * Look up track artwork via the iTunes Search API (no key required).
     * Results are cached per-title; failures are negative-cached so we
     * never hammer the API from the 30-second metadata polling loop.
     */
    private function lookup_track_artwork( $title ) {
        $cache_key = 'mbr_lrp_art_' . md5( $title );
        $cached = get_transient( $cache_key );
        
        if ( $cached !== false ) {
            return ( $cached === 'none' ) ? '' : $cached;
        }
        
        // "Artist - Title" -> "Artist Title" search term
        $term = trim( str_replace( ' - ', ' ', $title ) );
        
        if ( $term === '' ) {
            return '';
        }
        
        $api_url = 'https://itunes.apple.com/search?' . http_build_query( array(
            'term'    => $term,
            'media'   => 'music',
            'entity'  => 'song',
            'limit'   => 1,
            'country' => 'GB',
        ) );
        
        $response = wp_remote_get( $api_url, array(
            'timeout'   => 5,
            'sslverify' => true,
            'headers'   => array( 'User-Agent' => 'MBR Live Radio Player' ),
        ) );
        
        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            // API unreachable / rate limited - brief negative cache, retry soon
            set_transient( $cache_key, 'none', 5 * MINUTE_IN_SECONDS );
            return '';
        }
        
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        
        if ( empty( $data['results'][0]['artworkUrl100'] ) ) {
            // Genuine no-match (jingles, adverts, news bulletins) - longer negative cache
            set_transient( $cache_key, 'none', 15 * MINUTE_IN_SECONDS );
            return '';
        }
        
        // Upscale the 100x100 thumbnail to 600x600 (same CDN, always HTTPS)
        $artwork = str_replace( '100x100bb', '600x600bb', $data['results'][0]['artworkUrl100'] );
        $artwork = esc_url_raw( $artwork );
        
        set_transient( $cache_key, $artwork, 6 * HOUR_IN_SECONDS );
        
        return $artwork;
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
            // Loopback request to this same site. Verification is relaxed only
            // because staging and local environments routinely present a
            // self-signed certificate to themselves; the destination is
            // home_url(), never a third party.
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

        // Optional hardening: restrict the proxy to hosts that actually appear
        // in this site's configured stations, so it cannot be used as a
        // general-purpose bandwidth relay. Off by default because the proxy
        // endpoint is documented as accepting any stream URL and enabling it
        // unconditionally would break existing setups.
        //   add_filter( 'mbr_lrp_restrict_proxy_to_stations', '__return_true' );
        if ( apply_filters( 'mbr_lrp_restrict_proxy_to_stations', false ) && ! $this->is_configured_station_host( $url ) ) {
            status_header( 403 );
            echo esc_html( 'URL is not associated with a configured station' );
            exit;
        }
        
        // HLS manifests refresh every few seconds by design, so they get their
        // own generous allowance rather than being exempted outright. The old
        // check matched ".m3u8" anywhere in the URL, which meant any request
        // could opt out of rate limiting simply by appending it to the query
        // string. Only the URL path is considered now.
        $path            = (string) wp_parse_url( $url, PHP_URL_PATH );
        $extension       = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        $is_hls_manifest = in_array( $extension, array( 'm3u8', 'm3u' ), true );

        $client_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';

        $bucket     = $is_hls_manifest ? $client_ip . '_hls' : $client_ip . '_stream';
        $multiplier = $is_hls_manifest ? 10 : 1;

        if ( ! $this->check_rate_limit( $bucket, $multiplier ) ) {
            status_header( 429 );
            echo esc_html( 'Rate limit exceeded. Please try again later.' );
            exit;
        }

        // Cap simultaneous long-lived streams per client. Manifest fetches are
        // short requests and are not counted.
        if ( ! $is_hls_manifest && ! $this->acquire_stream_slot( $client_ip ) ) {
            status_header( 429 );
            echo esc_html( 'Too many simultaneous streams from your connection.' );
            exit;
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
     * Does this URL's host belong to one of the site's configured stations?
     *
     * Host-level rather than exact-URL matching, because Shoutcast path
     * correction, playlist resolution and HLS variant manifests all legitimately
     * produce a different path on the same server than the one saved in the
     * station settings.
     *
     * @param string $url URL to test.
     * @return bool
     */
    private function is_configured_station_host( $url ) {
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        if ( $host === '' ) {
            return false;
        }

        $allowed = get_transient( 'mbr_lrp_station_hosts' );

        if ( ! is_array( $allowed ) ) {
            $allowed = array();

            $stations = get_posts( array(
                'post_type'      => 'mbr_radio_station',
                'post_status'    => 'publish',
                'posts_per_page' => 200,
                'fields'         => 'ids',
                'no_found_rows'  => true,
            ) );

            foreach ( $stations as $station_id ) {
                $stream = get_post_meta( $station_id, '_mbr_lrp_stream_url', true );
                if ( ! $stream ) {
                    continue;
                }
                $station_host = strtolower( (string) wp_parse_url( $stream, PHP_URL_HOST ) );
                if ( $station_host !== '' ) {
                    $allowed[ $station_host ] = true;
                }
            }

            set_transient( 'mbr_lrp_station_hosts', $allowed, HOUR_IN_SECONDS );
        }

        return isset( $allowed[ $host ] );
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
                'sslverify' => true,
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
                $this->debug_log( 'Fixed Shoutcast URL', $test_url );
                return $test_url;
            }
        }
        
        // If none of the paths worked, return the original URL
        $this->debug_log( 'Could not find working stream path', $url );
        return $url;
    }
    
    /**
     * Stream Shoutcast using cURL with proper streaming
     */
    private function stream_with_passthru( $url ) {
        $this->debug_log( 'stream_with_passthru called', $url );

        // Redirects are handled on the real request rather than by a separate
        // probe. A probe cannot be trusted here: many stream servers answer a
        // probe request differently from a real one (or refuse it outright),
        // and when the probe missed a redirect the actual request -- with
        // FOLLOWLOCATION off -- forwarded the redirect's own tiny HTML body to
        // the listener as though it were audio. Each hop is still validated
        // before it is followed.
        $redirect_hops = 0;

        // First, do a quick check to see if the stream is accessible
        $test_ch = curl_init( $url );
        curl_setopt( $test_ch, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $test_ch, CURLOPT_HEADER, true );
        curl_setopt( $test_ch, CURLOPT_NOBODY, true );
        curl_setopt( $test_ch, CURLOPT_TIMEOUT, 5 );
        curl_setopt( $test_ch, CURLOPT_SSL_VERIFYPEER, true );
        curl_setopt( $test_ch, CURLOPT_SSL_VERIFYHOST, 2 );
        curl_setopt( $test_ch, CURLOPT_FOLLOWLOCATION, false );
        curl_setopt( $test_ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS );
        curl_setopt( $test_ch, CURLOPT_HTTPHEADER, array(
            'User-Agent: ' . self::USER_AGENT
        ));
        curl_exec( $test_ch );
        $http_code = curl_getinfo( $test_ch, CURLINFO_HTTP_CODE );
        curl_close( $test_ch );
        
        // If we get 403 or 401, the stream is blocking server connections
        // Redirect to the stream directly - browsers may have better luck
        if ( $http_code == 403 || $http_code == 401 ) {
            $this->debug_log( "Stream returned HTTP {$http_code} - server blocked. Redirecting browser to connect directly.", $url );
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
        
        // Streaming needs a long execution window, but not an unlimited one.
        // An unbounded worker is what turns a public proxy into a cheap way to
        // exhaust a shared host's PHP processes.
        $max_seconds = (int) apply_filters( 'mbr_lrp_max_stream_seconds', self::MAX_STREAM_SECONDS );
        $max_seconds = max( 60, $max_seconds );

        @set_time_limit( $max_seconds );
        @ini_set( 'max_execution_time', (string) $max_seconds );
        
        // Disable WordPress actions that might buffer output
        remove_action('shutdown', 'wp_ob_end_flush_all', 1);
        
        // Apache settings
        if ( function_exists( 'apache_setenv' ) ) {
            @apache_setenv('no-gzip', '1');
        }
        
        // Initialize cURL
        stream_attempt:

        $this->debug_log( 'Initializing cURL', $url );
        $ch = curl_init( $url );
        
        if ( ! $ch ) {
            $this->debug_log( 'Failed to initialize cURL' );
            status_header( 500 );
            die( 'Failed to initialize stream' );
        }
        
        // Force HTTP/1.1 to avoid HTTP/2 protocol issues
        curl_setopt( $ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1 );
        
        // Set cURL options for streaming
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, false );
        curl_setopt( $ch, CURLOPT_BINARYTRANSFER, true );
        // Redirects were resolved and validated above, so cURL must not follow
        // any further Location header on its own.
        curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false );
        curl_setopt( $ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS );
        curl_setopt( $ch, CURLOPT_TIMEOUT, $max_seconds );
        curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 15 );
        curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
        curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 2 );
        curl_setopt( $ch, CURLOPT_USERAGENT, self::USER_AGENT );
        curl_setopt( $ch, CURLOPT_BUFFERSIZE, 8192 );
        
        // Don't request Icecast metadata - keep stream pure audio only
        curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
            'Connection: close'
        ));
        
        // Get headers from stream
        $headers_sent = false;
        $content_type = 'audio/mpeg';
        $status_code  = 0;
        $location     = '';

        curl_setopt( $ch, CURLOPT_HEADERFUNCTION, function( $curl, $header ) use ( &$content_type, &$headers_sent, &$status_code, &$location ) {
            // Capture the status line. Shoutcast answers "ICY 200 OK" rather
            // than an HTTP status line, so both shapes are matched.
            if ( preg_match( '#^(?:HTTP/[\d.]+|ICY)\s+(\d{3})#i', $header, $m ) ) {
                $status_code = (int) $m[1];
            }

            if ( stripos( $header, 'Location:' ) === 0 ) {
                $parts = explode( ':', $header, 2 );
                if ( isset( $parts[1] ) ) {
                    $location = trim( $parts[1] );
                }
            }

            $len = strlen( $header );
            
            if ( stripos( $header, 'Content-Type:' ) === 0 ) {
                $parts = explode( ':', $header, 2 );
                if ( isset( $parts[1] ) ) {
                    $content_type = trim( $parts[1] );
                    
                    // Normalize AAC+ variants to standard audio/aac for better browser compatibility
                    if ( stripos( $content_type, 'aacp' ) !== false || stripos( $content_type, 'aac+' ) !== false ) {
                        $content_type = 'audio/aac';
                        // Content-Type normalised to audio/aac for browser compatibility.
                    }
                    
                    // Upstream Content-Type captured for the response header.
                }
            }
            
            // Log any redirect or location headers
            if ( stripos( $header, 'Location:' ) === 0 ) {
                // Redirects are resolved and validated before streaming begins.
            }
            
            // A redirect is not the stream. Emitting headers here is what sent
            // the redirect's HTML body to the listener labelled as audio.
            if ( $status_code >= 300 && $status_code < 400 ) {
                return strlen( $header );
            }

            // Send our headers after we've received the stream's headers
            if ( ! $headers_sent && trim( $header ) === '' ) {
                // End of headers, send ours now
                if ( ! headers_sent() ) {
                    // Response headers are being emitted to the listener.
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
        
        curl_setopt( $ch, CURLOPT_WRITEFUNCTION, function( $ch, $data ) use ( &$bytes_written, &$first_bytes_logged, &$status_code ) {
            $data_len = strlen( $data );

            // Swallow a redirect's body rather than passing it to the listener.
            if ( $status_code >= 300 && $status_code < 400 ) {
                return $data_len;
            }
            
            // Stream payload bytes are deliberately never logged.

            // Audio is still flowing, so keep this client's concurrency slot
            // alive. Throttled internally to one write per minute.
            $this->heartbeat_stream_slot();
            
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
        $this->debug_log( 'Starting cURL execution', $url );
        curl_exec( $ch );

        // Follow a redirect on the real response, validating it first.
        if ( $status_code >= 300 && $status_code < 400 && $location !== '' ) {
            curl_close( $ch );

            if ( $redirect_hops >= 5 ) {
                $this->debug_log( 'Too many redirects', $url );
                status_header( 508 );
                exit;
            }

            $next = $location;
            if ( ! preg_match( '#^https?://#i', $next ) ) {
                $next = $this->resolve_relative_url( $url, $next );
            }

            if ( $next === false || ! $this->is_valid_stream_url( $next ) ) {
                $this->debug_log( 'Blocked redirect to an unsafe destination', (string) $next );
                status_header( 403 );
                exit;
            }

            $redirect_hops++;
            $url = $next;
            $this->debug_log( 'Following validated redirect', $url );

            goto stream_attempt;
        }
        
        $curl_error = curl_errno( $ch );
        if ( $curl_error ) {
            $this->debug_log( "cURL error #{$curl_error}" );
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
        register_setting( 'mbr_lrp_proxy_settings', 'mbr_lrp_artwork_lookup', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '0'
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
                    
                    <tr>
                        <th scope="row">
                            <?php esc_html_e( 'Track Artwork Lookup', 'mbr-live-radio-player' ); ?>
                        </th>
                        <td>
                            <label>
                                <input 
                                    type="checkbox" 
                                    name="mbr_lrp_artwork_lookup" 
                                    value="1"
                                    <?php checked( get_option( 'mbr_lrp_artwork_lookup', '0' ), '1' ); ?>
                                />
                                <?php esc_html_e( 'Look up track artwork when the stream does not provide it', 'mbr-live-radio-player' ); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e( 'Most stations broadcast the track title but no artwork. When enabled, your server looks up album artwork from the iTunes Search API (free, no account needed) using the track title. Results are cached, so at most one lookup is made per track. Note: this sends track titles (never visitor data) to Apple, so it is off by default.', 'mbr-live-radio-player' ); ?>
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
