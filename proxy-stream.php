<?php
/**
 * Standalone Audio Stream Proxy
 * Bypasses WordPress to avoid HTTP/2 protocol conflicts
 * Version: 2.2.0 - With enhanced security
 */

// Clear opcache only if explicitly debugging
if ( defined( 'MBR_LRP_DEBUG' ) && MBR_LRP_DEBUG && function_exists( 'opcache_reset' ) ) {
    @opcache_reset();
}

// Get the WordPress root directory
$wp_load_path = dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) . '/wp-load.php';

// Only load WordPress for auth check, then prevent any output
define( 'SHORTINIT', true );
if ( file_exists( $wp_load_path ) ) {
    require_once( $wp_load_path );
}

// Check if proxy is enabled
$proxy_enabled = get_option( 'mbr_lrp_proxy_enabled', '1' );
if ( $proxy_enabled !== '1' ) {
    http_response_code( 403 );
    die( 'Proxy disabled' );
}

// Validate authentication token
$provided_token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
$valid_token = get_option( 'mbr_lrp_proxy_token', '' );

if ( empty( $valid_token ) ) {
    // Generate token if it doesn't exist
    $valid_token = wp_generate_password( 32, false, false );
    update_option( 'mbr_lrp_proxy_token', $valid_token, false );
}

if ( empty( $provided_token ) || ! hash_equals( $valid_token, $provided_token ) ) {
    http_response_code( 403 );
    die( 'Invalid authentication token' );
}

// Get and validate stream URL
$stream_url = isset( $_GET['url'] ) ? sanitize_text_field( wp_unslash( $_GET['url'] ) ) : '';

if ( empty( $stream_url ) ) {
    http_response_code( 400 );
    die( 'No URL provided' );
}

// Decode URL
$stream_url = rawurldecode( $stream_url );

// Enhanced SSRF protection
function mbr_validate_stream_url( $url ) {
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
if ( ! mbr_validate_stream_url( $stream_url ) ) {
    http_response_code( 403 );
    die( 'Invalid or unsafe URL' );
}

// Check if this is a playlist fetch request
$return_playlist = isset( $_GET['playlist'] ) && $_GET['playlist'] === '1';

// If requesting playlist content, return it as text
if ( $return_playlist && preg_match('/\.(m3u|pls)(\?.*)?$/i', $stream_url) ) {
    $ch = curl_init( $stream_url );
    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
    curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
    curl_setopt( $ch, CURLOPT_TIMEOUT, 15 );
    curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
    curl_setopt( $ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36' );
    
    $playlist = curl_exec( $ch );
    $error = curl_error( $ch );
    curl_close( $ch );
    
    if ( $error ) {
        http_response_code( 500 );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Error message for stream proxy
        die( 'Failed to fetch playlist: ' . esc_html( $error ) );
    }
    
    header( 'Content-Type: text/plain; charset=utf-8' );
    header( 'Access-Control-Allow-Origin: *' );
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw playlist output required for streaming
    echo $playlist;
    exit;
}

// Disable ALL output buffering
while ( ob_get_level() > 0 ) {
    ob_end_clean();
}

// Set PHP settings for streaming
@ini_set( 'output_buffering', 'off' );
@ini_set( 'zlib.output_compression', 0 );
@ini_set( 'implicit_flush', 1 );
@ini_set( 'max_execution_time', 0 );
@ini_set( 'memory_limit', '256M' );

// Apache settings
if ( function_exists( 'apache_setenv' ) ) {
    @apache_setenv( 'no-gzip', '1' );
}

// Initialize cURL
error_log( "MBR Proxy: Initializing cURL for URL: {$stream_url}" );
$ch = curl_init( $stream_url );

if ( ! $ch ) {
    error_log( 'MBR Proxy: Failed to initialize cURL' );
    http_response_code( 500 );
    die( 'Failed to initialize stream' );
}

error_log( 'MBR Proxy: Starting cURL execution for: ' . $stream_url );

// Force HTTP/1.1
curl_setopt( $ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1 );

// Basic cURL options
curl_setopt( $ch, CURLOPT_RETURNTRANSFER, false );
curl_setopt( $ch, CURLOPT_BINARYTRANSFER, true );
curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
curl_setopt( $ch, CURLOPT_MAXREDIRS, 5 );
curl_setopt( $ch, CURLOPT_TIMEOUT, 0 );
curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 15 );
curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false );
curl_setopt( $ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36' );
curl_setopt( $ch, CURLOPT_BUFFERSIZE, 65536 ); // 64KB buffer for smoother streaming

// Request Icecast metadata so we know the interval
curl_setopt( $ch, CURLOPT_HTTPHEADER, array(
    'Icy-MetaData: 1',
    'Connection: close',
    'Accept: */*'
));

// Variables for header processing
$headers_sent = false;
$content_type = 'audio/mpeg';
$metaint = 0;

// Header callback - capture content type and metadata interval
curl_setopt( $ch, CURLOPT_HEADERFUNCTION, function( $curl, $header ) use ( &$content_type, &$headers_sent, &$metaint, $stream_url ) {
    $len = strlen( $header );
    
    // Get content type
    if ( stripos( $header, 'Content-Type:' ) === 0 ) {
        $parts = explode( ':', $header, 2 );
        if ( isset( $parts[1] ) ) {
            $content_type = trim( $parts[1] );
        }
    }
    
    // Get metadata interval
    if ( stripos( $header, 'icy-metaint:' ) === 0 ) {
        $parts = explode( ':', $header, 2 );
        if ( isset( $parts[1] ) ) {
            $metaint = (int) trim( $parts[1] );
            error_log( "MBR Proxy: Metadata interval detected: {$metaint}" );
        }
    }
    
    // Send headers when we reach the end of stream headers
    if ( ! $headers_sent && trim( $header ) === '' ) {
        if ( ! headers_sent() ) {
            error_log( "MBR Proxy: Stream Content-Type: {$content_type}, Metaint: {$metaint}" );
            http_response_code( 200 );
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

// Write callback with metadata stripping
$bytes_written = 0;
$flush_threshold = 16384;
$first_write = true;
$buffer = '';
$bytes_in_chunk = 0;

curl_setopt( $ch, CURLOPT_WRITEFUNCTION, function( $ch, $data ) use ( &$bytes_written, $flush_threshold, &$first_write, $stream_url, &$metaint, &$buffer, &$bytes_in_chunk ) {
    
    // If no metadata interval, just passthrough
    if ( $metaint === 0 ) {
        if ( $first_write ) {
            error_log( "MBR Proxy: No metadata, pure passthrough" );
            $first_write = false;
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw binary audio stream data
        echo $data;
        $bytes_written += strlen( $data );
        
        if ( $bytes_written >= $flush_threshold ) {
            if ( ob_get_level() > 0 ) @ob_flush();
            @flush();
            $bytes_written = 0;
        }
        
        return connection_aborted() ? 0 : strlen( $data );
    }
    
    // Add to buffer
    $buffer .= $data;
    
    // Process buffer and strip metadata
    while ( strlen( $buffer ) > 0 ) {
        $need_bytes = $metaint - $bytes_in_chunk;
        
        // Do we have a complete audio chunk?
        if ( strlen( $buffer ) >= $need_bytes ) {
            // Output audio chunk
            $audio = substr( $buffer, 0, $need_bytes );
            $buffer = substr( $buffer, $need_bytes );
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw binary audio chunk
            echo $audio;
            $bytes_written += strlen( $audio );
            
            if ( $first_write ) {
                $hex = bin2hex( substr( $audio, 0, 16 ) );
                error_log( "MBR Proxy: First clean audio: {$hex}" );
                $first_write = false;
            }
            
            $bytes_in_chunk = 0;
            
            // Now handle metadata
            if ( strlen( $buffer ) >= 1 ) {
                $len_byte = ord( $buffer[0] );
                $meta_len = $len_byte * 16;
                $buffer = substr( $buffer, 1 );
                
                // Skip metadata block
                if ( $meta_len > 0 ) {
                    if ( strlen( $buffer ) >= $meta_len ) {
                        $buffer = substr( $buffer, $meta_len );
                    } else {
                        // Put length byte back, need more data
                        $buffer = chr( $len_byte ) . $buffer;
                        break;
                    }
                }
            } else {
                // Need more data for length byte
                break;
            }
        } else {
            // Partial audio chunk
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw binary audio buffer
            echo $buffer;
            $bytes_written += strlen( $buffer );
            $bytes_in_chunk += strlen( $buffer );
            $buffer = '';
            break;
        }
    }
    
    // Flush periodically
    if ( $bytes_written >= $flush_threshold ) {
        if ( ob_get_level() > 0 ) @ob_flush();
        @flush();
        $bytes_written = 0;
    }
    
    return connection_aborted() ? 0 : strlen( $data );
});

// Execute streaming
curl_exec( $ch );

// Get HTTP response code
$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

// Log errors
if ( curl_errno( $ch ) ) {
    error_log( 'MBR Radio Proxy Error: ' . curl_error( $ch ) . ' (Code: ' . curl_errno( $ch ) . ') for URL: ' . $stream_url );
} elseif ( $http_code >= 400 ) {
    error_log( 'MBR Radio Proxy HTTP Error ' . $http_code . ' for URL: ' . $stream_url );
}

// Cleanup
curl_close( $ch );
exit;
