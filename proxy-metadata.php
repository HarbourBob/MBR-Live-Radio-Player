<?php
/**
 * Standalone Metadata Proxy
 * Fetches ONLY Icecast metadata from streams
 * Completely separate from audio streaming
 * Version: 1.1.0 - With enhanced security
 */

// Get the WordPress root directory
$wp_load_path = dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) . '/wp-load.php';

// Only load WordPress for auth check
define( 'SHORTINIT', true );
if ( file_exists( $wp_load_path ) ) {
    require_once( $wp_load_path );
}

// URL validation is shared with includes/class-proxy.php so this endpoint and
// the stream proxy cannot end up with different ideas about what is safe. The
// validator is written to run under SHORTINIT, which is how this file is
// loaded when it is requested directly.
if ( ! defined( 'ABSPATH' ) ) {
    // Requested without a WordPress bootstrap: nothing here can run safely.
    http_response_code( 500 );
    exit;
}

require_once __DIR__ . '/includes/class-url-validator.php';

// Check if proxy is enabled
$proxy_enabled = get_option( 'mbr_lrp_proxy_enabled', '1' );
if ( $proxy_enabled !== '1' ) {
    http_response_code( 403 );
    header( 'Content-Type: application/json' );
    echo json_encode( array(
        'success' => false,
        'error' => 'Metadata proxy disabled'
    ));
    exit;
}

// NOTE: This endpoint does NOT require authentication token
// It's called internally by fetch_icecast_metadata() and only extracts metadata
// It has SSRF protection below and is read-only (no streaming)

// Get and validate stream URL
$stream_url = isset( $_GET['url'] ) ? sanitize_text_field( wp_unslash( $_GET['url'] ) ) : '';
$stream_url = rawurldecode( $stream_url );

if ( empty( $stream_url ) ) {
    http_response_code( 400 );
    header( 'Content-Type: application/json' );
    echo json_encode( array(
        'success' => false,
        'error' => 'No URL provided'
    ));
    exit;
}

/**
 * SSRF validation for this endpoint.
 *
 * Delegates to MBR_LRP_URL_Validator so there is one implementation rather
 * than two. The copy that used to live here was the weaker of the pair: it
 * only pattern-matched a handful of IPv6 prefixes rather than validating
 * properly, resolved IPv4 records alone, and allowed a seven-entry list of
 * ports that excluded most Shoutcast and Icecast mounts -- which is why
 * stations on ports such as 8010 or 8030 never showed now-playing text.
 *
 * @param string     $url        URL to check.
 * @param array|null $pinned_ips Filled with the addresses it resolved to.
 * @return bool
 */
function mbr_validate_metadata_url( $url, &$pinned_ips = null ) {
    return MBR_LRP_URL_Validator::validate( $url, $pinned_ips );
}

/**
 * Resolve a relative Location header against the URL that produced it.
 */
function mbr_metadata_absolute_url( $base, $relative ) {
    return MBR_LRP_URL_Validator::absolute_url( $base, $relative );
}

// Validate URL, keeping the addresses it resolved to so the request can be
// pinned to them rather than trusting a second DNS lookup.
$validated_ips = array();

if ( ! mbr_validate_metadata_url( $stream_url, $validated_ips ) ) {
    http_response_code( 403 );
    header( 'Content-Type: application/json' );
    echo json_encode( array(
        'success' => false,
        'error' => 'Invalid or unsafe URL'
    ));
    exit;
}

// Check cache first
$cache_key = 'mbr_metadata_' . md5( $stream_url );
$cached = get_transient( $cache_key );

if ( $cached !== false ) {
    header( 'Content-Type: application/json' );
    echo json_encode( array(
        'success' => true,
        'cached' => true,
        'data' => array(
            'title' => $cached['title'],
            'url' => $cached['url'],
            'timestamp' => time() // Fresh timestamp on each request
        )
    ));
    exit;
}

// Many Shoutcast and Icecast stations answer the configured URL with a redirect
// to the real mount point -- Shoutcast v2 and most load balancers do this as a
// matter of course. Those redirects have to be followed or metadata never
// arrives. They are followed manually, one hop at a time, with every
// destination put back through mbr_validate_metadata_url() before it is used,
// so a station cannot redirect this server somewhere it should not go.
$redirect_hops = 0;
$request_url   = $stream_url;

metadata_request:

// Initialize cURL for metadata extraction
$ch = curl_init( $request_url );

if ( ! $ch ) {
    http_response_code( 500 );
    header( 'Content-Type: application/json' );
    echo json_encode( array(
        'success' => false,
        'error' => 'Failed to initialize request'
    ));
    exit;
}

// Request Icecast metadata
curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
    'Icy-MetaData: 1',
    'Connection: close',
    'User-Agent: Mozilla/5.0'
));

curl_setopt( $ch, CURLOPT_RETURNTRANSFER, false );

// SECURITY: cURL must not follow redirects by itself, because it would do so
// without the destination ever being re-validated. Redirects are handled
// manually below, with each hop revalidated before it is requested.
curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false );
curl_setopt( $ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS );
curl_setopt( $ch, CURLOPT_TIMEOUT, 15 );
curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 10 );
curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 2 );

// Bind the connection to the addresses that passed validation. The hostname
// is still used for the Host header, TLS SNI and certificate checking, so
// this closes the DNS-rebinding window without weakening TLS.
MBR_LRP_URL_Validator::pin_curl_handle( $ch, $request_url, $validated_ips );

// Variables for metadata extraction
$icy_metaint = 0;
$metadata_found = false;
$metadata_title = '';
$metadata_url = '';
$buffer = '';
$bytes_received = 0;

// Header callback - capture metadata interval
$redirect_target = '';

curl_setopt( $ch, CURLOPT_HEADERFUNCTION, function( $curl, $header ) use ( &$icy_metaint, &$redirect_target ) {
    if ( stripos( $header, 'icy-metaint:' ) === 0 ) {
        $parts = explode( ':', $header, 2 );
        if ( isset( $parts[1] ) ) {
            $icy_metaint = (int) trim( $parts[1] );
        }
    }

    // Remember where a redirect points; it is validated after the transfer.
    if ( stripos( $header, 'location:' ) === 0 ) {
        $parts = explode( ':', $header, 2 );
        if ( isset( $parts[1] ) ) {
            $redirect_target = trim( $parts[1] );
        }
    }

    return strlen( $header );
});

// Write callback - extract metadata
curl_setopt( $ch, CURLOPT_WRITEFUNCTION, function( $ch, $data ) use ( &$icy_metaint, &$metadata_found, &$metadata_title, &$metadata_url, &$buffer, &$bytes_received ) {
    // If no metadata interval, we can't extract metadata
    if ( $icy_metaint === 0 ) {
        return 0; // Stop downloading
    }
    
    // If we already found metadata, stop downloading
    if ( $metadata_found ) {
        return 0;
    }
    
    $buffer .= $data;
    $bytes_received += strlen( $data );
    
    // Do we have enough data to reach first metadata block?
    if ( strlen( $buffer ) >= $icy_metaint + 1 ) {
        // Skip audio data
        $buffer = substr( $buffer, $icy_metaint );
        
        // Read metadata length byte
        $meta_length_byte = ord( $buffer[0] );
        $meta_length = $meta_length_byte * 16;
        $buffer = substr( $buffer, 1 );
        
        
        // Do we have the full metadata block?
        if ( $meta_length > 0 && strlen( $buffer ) >= $meta_length ) {
            $metadata_raw = substr( $buffer, 0, $meta_length );
            
            // Parse metadata
            if ( preg_match( "/StreamTitle='(.*?)';/", $metadata_raw, $matches ) ) {
                $metadata_title = trim( $matches[1] );
            }
            
            if ( preg_match( "/StreamUrl='(.*?)';/", $metadata_raw, $matches ) ) {
                $metadata_url = trim( $matches[1] );
            }
            
            
            $metadata_found = true;
            return 0; // Stop downloading
        }
    }
    
    // Safety: don't download more than 100KB
    if ( $bytes_received > 102400 ) {
        return 0;
    }
    
    return strlen( $data );
});

// Execute
curl_exec( $ch );
$curl_error   = curl_error( $ch );
$http_code    = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
curl_close( $ch );

// Follow one redirect hop, validating the destination first.
if ( $http_code >= 300 && $http_code < 400 && $redirect_target !== '' && $redirect_hops < 5 ) {
    $next = mbr_metadata_absolute_url( $request_url, $redirect_target );

    if ( $next !== false && mbr_validate_metadata_url( $next, $validated_ips ) ) {
        $redirect_hops++;
        $request_url = $next;

        // Reset per-request state before retrying against the new URL.
        $icy_metaint    = 0;
        $metadata_found = false;
        $metadata_title = '';
        $metadata_url   = '';
        $buffer         = '';
        $bytes_received = 0;

        goto metadata_request;
    }

    // Destination failed validation: stop rather than follow it.
    http_response_code( 403 );
    header( 'Content-Type: application/json' );
    echo json_encode( array(
        'success' => false,
        'error'   => 'Stream redirected to an address that failed validation'
    ));
    exit;
}

// Prepare response
$result = array(
    'success' => false,
    'data' => array(
        'title' => '',
        'url' => '',
        'timestamp' => time()
    )
);

if ( $metadata_found && ! empty( $metadata_title ) ) {
    $result['success'] = true;
    $result['data']['title'] = $metadata_title;
    $result['data']['url'] = $metadata_url;
    
    // Cache only title and URL (timestamp added fresh on each request)
    $cache_data = array(
        'title' => $metadata_title,
        'url' => $metadata_url
    );
    set_transient( $cache_key, $cache_data, 30 );
    
} elseif ( $curl_error ) {
    // Report transport failures (TLS, DNS, timeout) explicitly. Falling
    // through to "does not support metadata" here sent people looking at
    // their station config when the real fault was the connection.
    $result['error'] = $curl_error;
    $result['metaint'] = $icy_metaint;
} elseif ( $icy_metaint === 0 ) {
    $result['error'] = 'Stream does not support Icecast metadata';
    $result['metaint'] = 0;
} elseif ( ! $metadata_found ) {
    $result['error'] = 'Metadata block not found in stream data';
    $result['metaint'] = $icy_metaint;
    $result['bytes_received'] = $bytes_received;
} else {
    $result['error'] = $curl_error ? $curl_error : 'Could not extract metadata';
}

// Send JSON response
header( 'Content-Type: application/json' );
header( 'Access-Control-Allow-Origin: *' );
header( 'Cache-Control: no-cache, no-store, must-revalidate' );
header( 'Pragma: no-cache' );
header( 'Expires: 0' );
echo json_encode( $result );
exit;
