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
     * How long a slot-table lock may be held before it is treated as stale.
     */
    const SLOT_LOCK_TIMEOUT = 5;

    /**
     * Addresses the most recently validated URL resolved to.
     *
     * Kept so the connection can be pinned to the addresses that were actually
     * checked rather than to whatever a second DNS lookup returns.
     *
     * @var string[]
     */
    private $validated_ips = array();

    /**
     * CURLOPT_RESOLVE entries applied to WordPress HTTP API requests.
     *
     * @var string[]
     */
    private $pinned_resolve = array();

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
     * Thin wrapper kept for readability; the implementation lives in
     * MBR_LRP_URL_Validator so the stream proxy and the standalone metadata
     * proxy cannot drift apart.
     *
     * @param string $ip IP address to test.
     * @return bool True if the address is a safe public address.
     */
    private function is_safe_ip( $ip ) {
        return MBR_LRP_URL_Validator::is_safe_ip( $ip );
    }

    /**
     * Resolve a relative Location header against the URL that produced it.
     *
     * @param string $base     Absolute URL the redirect came from.
     * @param string $relative Relative or root-relative target.
     * @return string|false Absolute URL, or false if it cannot be built.
     */
    private function resolve_relative_url( $base, $relative ) {
        return MBR_LRP_URL_Validator::absolute_url( $base, $relative );
    }

    /**
     * Validate an outbound URL, and remember what it resolved to.
     *
     * All of the actual checks -- scheme, host, literal-IP handling, A and
     * AAAA resolution, and the port policy -- now live in the shared
     * validator. The addresses it returns are kept in $this->validated_ips so
     * the request that follows can be pinned to them.
     *
     * @param string $url URL to validate.
     * @return bool True if the URL is safe to request.
     */
    private function is_valid_stream_url( $url ) {
        $ips = array();

        if ( ! MBR_LRP_URL_Validator::validate( $url, $ips ) ) {
            $this->validated_ips = array();
            $this->debug_log( 'Rejected unsafe or unresolvable URL', $url );
            return false;
        }

        $this->validated_ips = $ips;

        return true;
    }

    /**
     * Pin a cURL handle to the addresses the URL was validated against.
     *
     * @param resource|CurlHandle $handle cURL handle.
     * @param string              $url    URL being requested.
     * @return void
     */
    private function pin_curl_dns( $handle, $url ) {
        MBR_LRP_URL_Validator::pin_curl_handle( $handle, $url, $this->validated_ips );
    }

    /**
     * Apply the current DNS pin to a WordPress HTTP API request.
     *
     * Hooked temporarily around wp_remote_* calls that fetch a visitor-supplied
     * URL, so those take the same validated addresses as the direct cURL paths.
     *
     * @param resource|CurlHandle $handle cURL handle created by WP_Http_Curl.
     * @return void
     */
    public function apply_pinned_dns( $handle ) {
        if ( ! empty( $this->pinned_resolve ) && $handle ) {
            curl_setopt( $handle, CURLOPT_RESOLVE, $this->pinned_resolve );
        }
    }

    /**
     * Fetch a URL with WordPress's HTTP API, following redirects manually so
     * every hop is revalidated, and pinning each request to validated
     * addresses.
     *
     * WordPress follows up to five redirects by default and does not re-run
     * our SSRF checks on the way, so any wp_remote_get() handed a
     * visitor-supplied URL has to set redirection to 0 and do this itself.
     *
     * @param string $url  URL to fetch (already validated by the caller).
     * @param array  $args wp_remote_get() arguments.
     * @return array|WP_Error Response, or WP_Error on failure.
     */
    private function fetch_validated( $url, $args = array() ) {
        $args['redirection'] = 0;
        $args['sslverify']   = true;

        $hops = 0;

        while ( true ) {
            $this->pinned_resolve = MBR_LRP_URL_Validator::curl_resolve_entries( $url, $this->validated_ips );
            add_action( 'http_api_curl', array( $this, 'apply_pinned_dns' ) );

            $response = wp_remote_get( $url, $args );

            remove_action( 'http_api_curl', array( $this, 'apply_pinned_dns' ) );
            $this->pinned_resolve = array();

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $code = (int) wp_remote_retrieve_response_code( $response );

            if ( $code < 300 || $code >= 400 ) {
                return $response;
            }

            $location = wp_remote_retrieve_header( $response, 'location' );

            if ( is_array( $location ) ) {
                $location = reset( $location );
            }

            if ( empty( $location ) || $hops >= MBR_LRP_URL_Validator::MAX_REDIRECTS ) {
                return $response;
            }

            $next = MBR_LRP_URL_Validator::absolute_url( $url, $location );

            if ( $next === false || ! $this->is_valid_stream_url( $next ) ) {
                $this->debug_log( 'Blocked redirect to an unsafe destination', (string) $next );
                return new WP_Error( 'mbr_lrp_unsafe_redirect', 'Redirect destination failed validation' );
            }

            $url = $next;
            $hops++;
        }
    }

    /**
     * Normalise an upstream Content-Type into something browsers will play.
     *
     * AAC is where this matters. Shoutcast and Icecast label AAC and HE-AAC
     * mounts in a dozen different ways -- audio/aacp, audio/aac+,
     * audio/x-aac, audio/mp4a-latm -- and browsers accept almost none of
     * them, which is why an AAC station could arrive as "no supported source
     * was found" while the identical MP3 mount played perfectly. Several
     * servers also send application/octet-stream, or no Content-Type at all,
     * in which case the file extension is a better guess than assuming MP3.
     *
     * @param string $content_type Upstream Content-Type header value.
     * @param string $url          URL being streamed, used to infer a type.
     * @return string Content-Type to send to the listener.
     */
    private function normalize_audio_content_type( $content_type, $url = '' ) {
        $type = strtolower( trim( (string) $content_type ) );

        // Drop any parameters ("audio/aacp;charset=utf-8").
        $semicolon = strpos( $type, ';' );
        if ( $semicolon !== false ) {
            $type = trim( substr( $type, 0, $semicolon ) );
        }

        // Every AAC spelling in the wild collapses to audio/aac, which is what
        // Chrome, Firefox, Edge and Safari actually accept for an ADTS stream.
        if ( strpos( $type, 'aacp' ) !== false
            || strpos( $type, 'aac+' ) !== false
            || in_array( $type, array( 'audio/aac', 'audio/x-aac', 'audio/x-hx-aac-adts', 'audio/vnd.dlna.adts', 'audio/mp4a-latm', 'application/aacp', 'audio/mpeg4-generic' ), true ) ) {
            return 'audio/aac';
        }

        // Common MP3 aliases; audio/mpeg is the only universally accepted one.
        if ( in_array( $type, array( 'audio/mp3', 'audio/x-mp3', 'audio/mpeg3', 'audio/x-mpeg', 'audio/mpg', 'audio/x-mpegurl-stream' ), true ) ) {
            return 'audio/mpeg';
        }

        if ( in_array( $type, array( 'audio/x-ogg', 'audio/vorbis', 'audio/opus' ), true ) ) {
            return 'audio/ogg';
        }

        // Anything genuinely playable is passed straight through.
        if ( $type !== '' && strpos( $type, 'audio/' ) === 0 ) {
            return $type;
        }

        if ( in_array( $type, array( 'application/ogg', 'application/vnd.apple.mpegurl', 'application/x-mpegurl', 'video/mp2t' ), true ) ) {
            return $type;
        }

        // No usable Content-Type: infer from the path before falling back.
        return $this->guess_content_type_from_url( $url );
    }

    /**
     * Best-guess Content-Type for a stream URL with no usable header.
     *
     * @param string $url Stream URL.
     * @return string
     */
    private function guess_content_type_from_url( $url ) {
        $path = (string) parse_url( (string) $url, PHP_URL_PATH );
        $ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

        $map = array(
            'aac'  => 'audio/aac',
            'aacp' => 'audio/aac',
            'adts' => 'audio/aac',
            'm4a'  => 'audio/mp4',
            'mp4'  => 'audio/mp4',
            'ogg'  => 'audio/ogg',
            'oga'  => 'audio/ogg',
            'opus' => 'audio/ogg',
            'flac' => 'audio/flac',
            'wav'  => 'audio/wav',
            'mp3'  => 'audio/mpeg',
            'ts'   => 'video/mp2t',
            'm3u8' => 'application/vnd.apple.mpegurl',
            'm3u'  => 'application/vnd.apple.mpegurl',
        );

        if ( isset( $map[ $ext ] ) ) {
            return $map[ $ext ];
        }

        // A ";type=.aac" or "?type=aac" hint is common on Shoutcast mounts.
        if ( stripos( (string) $url, 'aac' ) !== false ) {
            return 'audio/aac';
        }

        return 'audio/mpeg';
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

        $key  = 'mbr_streams_' . md5( $identifier );
        $lock = 'mbr_lrp_slot_' . md5( $identifier );

        // Read-count-write is not atomic on its own: two requests arriving
        // together could each see the same free slot and both take it, or one
        // could overwrite the other's write. Under ordinary traffic that never
        // shows, but deliberate concurrent abuse is exactly what this limit
        // exists to stop, so the whole sequence is done under a lock.
        if ( ! $this->acquire_slot_lock( $lock ) ) {
            // The lock could not be taken -- almost certainly contention
            // rather than a fault. Let the listener through: refusing genuine
            // playback because a mutex was busy would be a worse failure than
            // briefly allowing one stream over the cap.
            $this->debug_log( 'Could not take slot lock; allowing stream' );
            return true;
        }

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
            $this->release_slot_lock( $lock );
            $this->debug_log( 'Concurrent stream limit reached' );
            return false;
        }

        // Unique per slot. A timestamp is not: two streams starting in the
        // same second would collide, and releasing one would release both.
        $token = uniqid( '', true );

        $slots[ $token ] = $now + self::SLOT_TTL;
        set_transient( $key, $slots, self::SLOT_TTL );

        $this->release_slot_lock( $lock );

        $this->stream_slot   = array( 'key' => $key, 'token' => $token, 'lock' => $lock );
        $this->slot_beat_at  = $now;

        // Release the slot however the request ends -- listener navigating
        // away, connection dropping, or PHP hitting its own limits.
        register_shutdown_function( array( $this, 'release_stream_slot' ) );

        return true;
    }

    /**
     * Take an exclusive lock on one client's slot table.
     *
     * Uses an INSERT IGNORE against the options table, which the unique index
     * on option_name makes atomic -- the same mechanism WordPress core uses
     * for its own upgrader locks. A lock older than SLOT_LOCK_TIMEOUT is
     * treated as abandoned by a killed worker and taken over.
     *
     * @param string $lock_option Option name to lock on.
     * @return bool True if the lock is held.
     */
    private function acquire_slot_lock( $lock_option ) {
        global $wpdb;

        if ( ! isset( $wpdb ) ) {
            return false;
        }

        for ( $attempt = 0; $attempt < 3; $attempt++ ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic lock acquisition; no caching possible by design.
            $acquired = $wpdb->query(
                $wpdb->prepare(
                    "INSERT IGNORE INTO `{$wpdb->options}` ( `option_name`, `option_value`, `autoload` ) VALUES (%s, %s, 'no')",
                    $lock_option,
                    (string) time()
                )
            );

            if ( $acquired ) {
                return true;
            }

            // Held by somebody else. If it is stale, clear it and retry.
            // Read straight from the table: the options cache can hold a value
            // from earlier in this request, and a stale read here would mean
            // stealing a lock somebody is legitimately holding.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Lock state must not be cached.
            $held_since = (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s LIMIT 1", $lock_option )
            );

            if ( $held_since === 0 || ( $held_since + self::SLOT_LOCK_TIMEOUT ) < time() ) {
                delete_option( $lock_option );
                continue;
            }

            usleep( 20000 );
        }

        return false;
    }

    /**
     * Release a slot-table lock.
     *
     * @param string $lock_option Option name used for the lock.
     * @return void
     */
    private function release_slot_lock( $lock_option ) {
        delete_option( $lock_option );
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

        if ( ! $this->acquire_slot_lock( $this->stream_slot['lock'] ) ) {
            // Missing one heartbeat is harmless -- the slot has SLOT_TTL
            // seconds left and the next write will try again.
            return;
        }

        $slots = get_transient( $this->stream_slot['key'] );
        if ( ! is_array( $slots ) ) {
            $slots = array();
        }

        $slots[ $this->stream_slot['token'] ] = $now + self::SLOT_TTL;
        set_transient( $this->stream_slot['key'], $slots, self::SLOT_TTL );

        $this->release_slot_lock( $this->stream_slot['lock'] );
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
        $lock  = $this->stream_slot['lock'];

        $locked = $this->acquire_slot_lock( $lock );

        $slots = get_transient( $key );

        if ( is_array( $slots ) ) {
            unset( $slots[ $token ] );

            if ( empty( $slots ) ) {
                delete_transient( $key );
            } else {
                set_transient( $key, $slots, self::SLOT_TTL );
            }
        }

        if ( $locked ) {
            $this->release_slot_lock( $lock );
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
        $is_hls_manifest = in_array( $extension, array( 'm3u8', 'm3u', 'pls' ), true );

        // A playlist fetch is a short text request, not a stream, so it is
        // counted in the manifest bucket. Left out, resolving a .pls station
        // took one of the listener's concurrent-stream slots before playback
        // had even begun.

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
        
        // Check for a Shoutcast base URL that needs a stream path appending.
        // This used to test for ":8000" specifically, which meant a station on
        // any other port -- and AAC mounts are very often on 8010, 8020 or
        // 8030 rather than the base port -- never got the correction and was
        // handed the server's HTML status page instead of audio.
        // fix_shoutcast_url() returns the URL untouched when it does not apply.
        $probe_path = (string) wp_parse_url( $url, PHP_URL_PATH );
        $probe_port = wp_parse_url( $url, PHP_URL_PORT );

        if ( ! empty( $probe_port ) && ( $probe_path === '' || $probe_path === '/' ) ) {
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
            // For file-based requests (HLS segments, playlists), proxy them.
            // Redirects are followed by fetch_validated(), which revalidates
            // every hop; wp_remote_get() on its own would follow up to five
            // without rechecking any of them.
            $args = array(
                'timeout' => 30,
                'headers' => array(
                    'User-Agent' => self::USER_AGENT,
                ),
            );
            
            $response = $this->fetch_validated( $url, $args );
            
            if ( is_wp_error( $response ) ) {
                status_header( 502 );
                echo esc_html( 'Failed to fetch: ' . $response->get_error_message() );
                return;
            }
            
            // Get content type
            $content_type = wp_remote_retrieve_header( $response, 'content-type' );
            if ( is_array( $content_type ) ) {
                $content_type = reset( $content_type );
            }
            $content_type = $this->normalize_audio_content_type( $content_type, $url );
            
            // Set headers
            status_header( wp_remote_retrieve_response_code( $response ) );
            header( 'Content-Type: ' . sanitize_text_field( $content_type ) );
            header( 'Cache-Control: public, max-age=60' );
            header( 'Access-Control-Allow-Origin: *' );
            
            // Output body
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw manifest or segment data.
            echo wp_remote_retrieve_body( $response );
            exit;
        }
        
        // Everything else -- Icecast, Shoutcast and direct file streams -- is
        // streamed through here rather than handed back to the browser.
        //
        // Earlier versions redirected HTTPS streams to the browser on the
        // grounds that it could fetch them itself. That defeated the point of
        // the request: by the time it reaches the proxy the player has already
        // decided it wants the stream proxied, usually because the browser
        // refused it. It is also where AAC stations came unstuck, since a
        // direct fetch gets the station's own Content-Type -- audio/aacp and
        // friends, which browsers will not decode -- along with no CORS
        // headers, instead of the normalised type this proxy sends.
        $this->stream_with_passthru( $url );
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
        
        // Try common Shoutcast stream paths in order of likelihood. The AAC
        // spellings are here because an AAC mount on a Shoutcast v1 server
        // frequently answers on ;stream.aac while the MP3 mount answers on
        // the bare ;, and only trying the MP3 forms found nothing.
        $paths_to_try = array(
            '/;',              // Default Shoutcast stream endpoint
            '/stream',         // Common alternative
            '/;stream.mp3',    // Explicit format
            '/;stream.aac',    // Explicit AAC format
            '/stream.aac',     // AAC mount on some builds
            '/;stream.nsv',    // Nullsoft streaming video/audio
        );
        
        foreach ( $paths_to_try as $path ) {
            $test_url = $parsed['scheme'] . '://' . $parsed['host'];
            if ( ! empty( $parsed['port'] ) ) {
                $test_url .= ':' . $parsed['port'];
            }
            $test_url .= $path;
            
            if ( ! $this->is_valid_stream_url( $test_url ) ) {
                continue;
            }
            
            $probe = $this->probe_stream_headers( $test_url );
            
            if ( $probe === false ) {
                continue;
            }
            
            $content_type = isset( $probe['content_type'] ) ? $probe['content_type'] : '';
            $icy_name     = isset( $probe['icy_name'] ) ? $probe['icy_name'] : '';
            
            // Check if this returns audio content or has ICY headers (Shoutcast/Icecast indicator)
            if ( 
                stripos( $content_type, 'audio' ) !== false || 
                stripos( $content_type, 'mpeg' ) !== false ||
                stripos( $content_type, 'aac' ) !== false ||
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
     * Read a stream's response headers without downloading the audio.
     *
     * Deliberately a GET that is aborted as soon as the headers arrive, not a
     * HEAD. Shoutcast servers answer HEAD inconsistently -- 404, 405, 400 or
     * an empty reply are all common on servers that serve a perfectly good
     * stream to a real GET -- so probing with HEAD produced false negatives
     * and sent the caller down the wrong path.
     *
     * @param string $url URL to probe (must already be validated).
     * @return array|false status, content_type, icy_name and location, or false.
     */
    private function probe_stream_headers( $url, $timeout = 6 ) {
        if ( ! function_exists( 'curl_init' ) ) {
            return false;
        }
        
        $ch = curl_init( $url );
        
        if ( ! $ch ) {
            return false;
        }
        
        $result = array(
            'status'       => 0,
            'content_type' => '',
            'icy_name'     => '',
            'location'     => '',
        );
        
        curl_setopt( $ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1 );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, false );
        curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false );
        curl_setopt( $ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS );
        curl_setopt( $ch, CURLOPT_TIMEOUT, $timeout );
        curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, $timeout );
        curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
        curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 2 );
        curl_setopt( $ch, CURLOPT_USERAGENT, self::USER_AGENT );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, array( 'Icy-MetaData: 1', 'Connection: close' ) );
        $this->pin_curl_dns( $ch, $url );
        
        curl_setopt( $ch, CURLOPT_HEADERFUNCTION, function( $curl, $header ) use ( &$result ) {
            // Shoutcast answers "ICY 200 OK" rather than an HTTP status line.
            if ( preg_match( '#^(?:HTTP/[\d.]+|ICY)\s+(\d{3})#i', $header, $m ) ) {
                $result['status'] = (int) $m[1];
            } elseif ( stripos( $header, 'content-type:' ) === 0 ) {
                $parts = explode( ':', $header, 2 );
                $result['content_type'] = isset( $parts[1] ) ? trim( $parts[1] ) : '';
            } elseif ( stripos( $header, 'icy-name:' ) === 0 ) {
                $parts = explode( ':', $header, 2 );
                $result['icy_name'] = isset( $parts[1] ) ? trim( $parts[1] ) : '';
            } elseif ( stripos( $header, 'location:' ) === 0 ) {
                $parts = explode( ':', $header, 2 );
                $result['location'] = isset( $parts[1] ) ? trim( $parts[1] ) : '';
            }
            
            return strlen( $header );
        } );
        
        // Abort the moment the first audio byte arrives: we only wanted the
        // headers, and nothing here should download a stream.
        curl_setopt( $ch, CURLOPT_WRITEFUNCTION, function( $curl, $data ) {
            return 0;
        } );
        
        curl_exec( $ch );
        curl_close( $ch );
        
        // A status of 0 with a Content-Type still means the server answered;
        // some Shoutcast builds send headers with no recognisable status line.
        if ( $result['status'] === 0 && $result['content_type'] === '' && $result['icy_name'] === '' ) {
            return false;
        }
        
        return $result;
    }
    
    /**
     * Stream Shoutcast using cURL with proper streaming
     */
    private function stream_with_passthru( $url ) {
        $this->debug_log( 'stream_with_passthru called', $url );

        // Revalidate here rather than trusting an earlier check. Shoutcast
        // path correction and playlist resolution can both change the URL
        // between validation and this point, and revalidating also refreshes
        // the address list the connection is pinned to.
        if ( ! $this->is_valid_stream_url( $url ) ) {
            status_header( 403 );
            exit;
        }

        // Redirects are handled on the real request rather than by a separate
        // probe. A probe cannot be trusted here: many stream servers answer a
        // probe request differently from a real one (or refuse it outright),
        // and when the probe missed a redirect the actual request -- with
        // FOLLOWLOCATION off -- forwarded the redirect's own tiny HTML body to
        // the listener as though it were audio. Each hop is still validated
        // before it is followed.
        $redirect_hops = 0;

        // There is deliberately no preflight request here any more.
        //
        // Checking reachability first meant every stream was opened twice, and
        // a growing number of stations cannot survive that. Bauer and other
        // AdsWizz-fronted streams hand out a URL carrying a one-time session
        // key (aw_0_1st.skey, minted per request by the directory that issued
        // the playlist); spending it on a probe leaves nothing valid for the
        // request that actually plays. The real response tells us everything
        // the probe did, one connection later, so it is read from that
        // instead.
        
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

        // Bind the connection to the addresses validation actually approved,
        // rather than letting cURL run its own second lookup that a hostile
        // DNS server could answer with an internal address.
        $this->pin_curl_dns( $ch, $url );
        
        // Don't request Icecast metadata - keep stream pure audio only
        curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
            'Connection: close'
        ));
        
        // Get headers from stream. The default is inferred from the URL rather
        // than assumed to be MP3: labelling an AAC stream audio/mpeg is a
        // reliable way to make a browser refuse it.
        $headers_sent = false;
        $content_type = $this->guess_content_type_from_url( $url );
        $status_code  = 0;
        $location     = '';
        $stream_url   = $url;

        curl_setopt( $ch, CURLOPT_HEADERFUNCTION, function( $curl, $header ) use ( &$content_type, &$headers_sent, &$status_code, &$location, $stream_url ) {
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
                    // Every AAC spelling, MP3 alias and missing or generic
                    // type is resolved to something browsers will actually
                    // decode. See normalize_audio_content_type().
                    $content_type = $this->normalize_audio_content_type( $parts[1], $stream_url );
                }
            }
            
            // Log any redirect or location headers
            if ( stripos( $header, 'Location:' ) === 0 ) {
                // Redirects are resolved and validated before streaming begins.
            }
            
            // Anything that is not a success is not the stream, and must not
            // be dressed up as one. A redirect's HTML body used to be
            // forwarded as audio; so did a 403 refusal page, which is what
            // reached listeners as "stream format not supported" -- an error
            // about the audio when the station had in fact declined the
            // connection outright.
            if ( $status_code >= 300 ) {
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

            // Swallow the body of any non-success response rather than
            // passing an error page to the listener labelled as audio.
            if ( $status_code >= 300 ) {
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

        // The station refused or failed the request. Report that plainly
        // rather than letting the listener see a format error.
        if ( $status_code >= 400 ) {
            $this->debug_log( "Stream returned HTTP {$status_code}", $url );

            // A 401 or 403 usually means this server is being refused
            // specifically, often because the station blocks datacentre
            // addresses. The browser may fare better connecting itself -- but
            // only over HTTPS, since handing an http:// URL back to a page
            // served over HTTPS is blocked as mixed content.
            if ( ( $status_code === 401 || $status_code === 403 ) && stripos( $url, 'https://' ) === 0 && ! headers_sent() ) {
                wp_redirect( $url, 302 );
                exit;
            }

            if ( ! headers_sent() ) {
                status_header( 502 );
                header( 'Content-Type: text/plain; charset=utf-8' );
                header( 'Access-Control-Allow-Origin: *' );
                echo esc_html(
                    'MBR_STREAM_ERROR: the station returned HTTP ' . $status_code . '. '
                    . 'The stream address may have expired, or the station may be refusing requests from this server.'
                );
            }

            exit;
        }

        exit;
    }
    
    /**
     * Stream Shoutcast/Icecast audio using cURL for proper chunked streaming
     */
    
    /**
     * Return playlist content as text
     */
    private function return_playlist_content( $url ) {
        // Playlist hosts redirect constantly; fetch_validated() follows those
        // hops itself so each destination is revalidated first.
        $response = $this->fetch_validated( $url, array(
            'timeout' => 10,
            'user-agent' => self::USER_AGENT,
        ) );
        
        if ( is_wp_error( $response ) ) {
            status_header( 502 );
            header( 'Content-Type: text/plain; charset=utf-8' );
            header( 'Access-Control-Allow-Origin: *' );
            echo esc_html( 'MBR_PLAYLIST_ERROR: could not reach the playlist host -- ' . $response->get_error_message() );
            exit;
        }
        
        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        
        header( 'Content-Type: text/plain; charset=utf-8' );
        header( 'X-Content-Type-Options: nosniff' );
        header( 'Access-Control-Allow-Origin: *' );
        
        // The response used to be forwarded whatever it was. A directory that
        // refuses the request answers with an HTML error or challenge page and
        // HTTP 403, and that page was handed to the playlist parser, which
        // dutifully treated its markup as candidate stream URLs. The listener
        // then saw "stream format not supported" -- a message about the audio,
        // when the real fault was that the playlist was never fetched at all.
        if ( $code < 200 || $code >= 300 ) {
            status_header( 502 );
            echo esc_html( 'MBR_PLAYLIST_ERROR: the playlist host returned HTTP ' . $code . '. It is most likely refusing requests from this server.' );
            exit;
        }
        
        if ( trim( $body ) === '' ) {
            status_header( 502 );
            echo esc_html( 'MBR_PLAYLIST_ERROR: the playlist host returned an empty response.' );
            exit;
        }
        
        if ( preg_match( '#<\s*(?:!doctype|html|head|body|script)\b#i', substr( $body, 0, 1024 ) ) ) {
            status_header( 502 );
            echo esc_html( 'MBR_PLAYLIST_ERROR: the playlist host returned a web page rather than a playlist. It is most likely blocking requests from this server, or the URL is wrong.' );
            exit;
        }
        
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
