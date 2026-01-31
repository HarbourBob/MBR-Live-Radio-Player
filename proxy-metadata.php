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

// Enhanced SSRF protection
function mbr_validate_metadata_url( $url ) {
    $parsed = parse_url( $url );
    
    if ( ! $parsed || ! isset( $parsed['scheme'] ) || ! isset( $parsed['host'] ) ) {
        return false;
    }
    
    // Only HTTP/HTTPS
    if ( ! in_array( $parsed['scheme'], array( 'http', 'https' ), true ) ) {
        return false;
    }
    
    $host = strtolower( $parsed['host'] );
    
    // Block localhost variations
    $blocked_hosts = array( 'localhost', '127.0.0.1', '::1', '0.0.0.0', 'metadata.google.internal', '169.254.169.254' );
    if ( in_array( $host, $blocked_hosts, true ) ) {
        return false;
    }
    
    // Check IPv4 private ranges
    if ( filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
        if ( filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
            return false;
        }
    }
    
    // Check IPv6 private ranges
    if ( filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
        $hex = bin2hex( @inet_pton( $host ) );
        if ( $hex && ( substr( $hex, 0, 2 ) === 'fc' || substr( $hex, 0, 2 ) === 'fd' ||
                       substr( $hex, 0, 3 ) === 'fe8' || substr( $hex, 0, 3 ) === 'fe9' ||
                       substr( $hex, 0, 3 ) === 'fea' || substr( $hex, 0, 3 ) === 'feb' ) ) {
            return false;
        }
    }
    
    // For hostnames, check DNS resolution
    if ( ! filter_var( $host, FILTER_VALIDATE_IP ) ) {
        $ips = @gethostbynamel( $host );
        if ( $ips === false || empty( $ips ) ) {
            return false;
        }
        
        foreach ( $ips as $ip ) {
            if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
                if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
                    return false;
                }
            }
        }
    }
    
    // Restrict ports
    if ( isset( $parsed['port'] ) ) {
        $allowed_ports = array( 80, 443, 8000, 8080, 8443, 8888, 9000 );
        if ( ! in_array( (int) $parsed['port'], $allowed_ports, true ) ) {
            return false;
        }
    }
    
    return true;
}

// Validate URL
if ( ! mbr_validate_metadata_url( $stream_url ) ) {
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

// Initialize cURL for metadata extraction
$ch = curl_init( $stream_url );

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
curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
curl_setopt( $ch, CURLOPT_MAXREDIRS, 5 );
curl_setopt( $ch, CURLOPT_TIMEOUT, 15 );
curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 10 );
curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );

// Variables for metadata extraction
$icy_metaint = 0;
$metadata_found = false;
$metadata_title = '';
$metadata_url = '';
$buffer = '';
$bytes_received = 0;

// Header callback - capture metadata interval
curl_setopt( $ch, CURLOPT_HEADERFUNCTION, function( $curl, $header ) use ( &$icy_metaint ) {
    if ( stripos( $header, 'icy-metaint:' ) === 0 ) {
        $parts = explode( ':', $header, 2 );
        if ( isset( $parts[1] ) ) {
            $icy_metaint = (int) trim( $parts[1] );
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
$curl_error = curl_error( $ch );
curl_close( $ch );

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
