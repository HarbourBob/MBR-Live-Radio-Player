<?php
/**
 * Popup Player Template
 * 
 * This template is loaded when the user clicks the pop-out button
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Get station ID from URL
$station_id = isset( $_GET['station_id'] ) ? intval( $_GET['station_id'] ) : 0;

if ( ! $station_id ) {
    wp_die( esc_html__( 'Invalid station ID', 'mbr-live-radio-player' ) );
}

// Get station details
$station = get_post( $station_id );

if ( ! $station || 'mbr_radio_station' !== $station->post_type || 'publish' !== $station->post_status ) {
    wp_die( esc_html__( 'Station not found', 'mbr-live-radio-player' ) );
}

$stream_url = get_post_meta( $station_id, '_mbr_lrp_stream_url', true );

if ( ! $stream_url ) {
    wp_die( esc_html__( 'No stream URL configured', 'mbr-live-radio-player' ) );
}

$station_title = get_the_title( $station_id );
$station_art = get_the_post_thumbnail_url( $station_id, 'medium' );

// Get proxy settings
$proxy_enabled = get_option( 'mbr_lrp_proxy_enabled', '1' ) === '1';

// Get authentication token for secure proxy access
$proxy_token = '';
if ( $proxy_enabled ) {
    $proxy_token = get_option( 'mbr_lrp_proxy_token', '' );
    if ( empty( $proxy_token ) ) {
        $proxy_token = wp_generate_password( 32, false, false );
        update_option( 'mbr_lrp_proxy_token', $proxy_token, false );
    }
}

// Use AJAX endpoint with trailing & so JS can append url parameter, and include token
$proxy_url = $proxy_enabled ? admin_url( 'admin-ajax.php?action=mbr_proxy_stream&token=' . urlencode( $proxy_token ) . '&' ) : '';

// Get appearance settings from post meta
$dark_mode = get_post_meta( $station_id, '_mbr_lrp_dark_mode', true ) === '1';
$dark_mode_class = $dark_mode ? ' mbr-dark-mode' : '';
$bg_gradient = $dark_mode ? 'linear-gradient(135deg, #1a1a2e 0%, #16213e 100%)' : 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)';

$glassmorphism = get_post_meta( $station_id, '_mbr_lrp_glassmorphism', true ) === '1';
$glassmorphism_class = $glassmorphism ? ' mbr-glassmorphism' : '';

// Get popout skin
$allowed_skins  = array( 'default', 'classic', 'gradient-dark', 'minimal', 'retro', 'slim-bar' );
$popout_skin    = get_post_meta( $station_id, '_mbr_lrp_popout_skin', true );
if ( empty( $popout_skin ) || ! in_array( $popout_skin, $allowed_skins, true ) ) {
    $popout_skin = 'default';
}
$popout_skin_class = ( $popout_skin !== 'default' ) ? ' mbr-skin-' . $popout_skin : '';

// Get custom gradient colors from post meta
$gradient_color_1 = get_post_meta( $station_id, '_mbr_lrp_gradient_color_1', true );
$gradient_color_2 = get_post_meta( $station_id, '_mbr_lrp_gradient_color_2', true );

// Validate colors at output time to prevent XSS (defense in depth)
if ( empty( $gradient_color_1 ) || ! preg_match( '/^#[a-f0-9]{6}$/i', $gradient_color_1 ) ) {
    $gradient_color_1 = '#667eea'; // Force safe default
}
if ( empty( $gradient_color_2 ) || ! preg_match( '/^#[a-f0-9]{6}$/i', $gradient_color_2 ) ) {
    $gradient_color_2 = '#764ba2'; // Force safe default
}

// Override bg_gradient if not dark mode and not glassmorphism (with validated colors)
if ( ! $dark_mode && ! $glassmorphism ) {
    $bg_gradient = 'linear-gradient(135deg, ' . esc_attr( $gradient_color_1 ) . ' 0%, ' . esc_attr( $gradient_color_2 ) . ' 100%)';
}

// Build gradient style for player (with validated colors)
$custom_gradient = '';
if ( ! $glassmorphism && ! $dark_mode ) {
    $custom_gradient = sprintf(
        ' style="--mbr-gradient-color-1: %s; --mbr-gradient-color-2: %s;"',
        esc_attr( $gradient_color_1 ),
        esc_attr( $gradient_color_2 )
    );
}

// Generate unique player ID
$player_id = 'mbr-radio-player-popup-' . $station_id;

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html( $station_title ); ?> - Radio Player</title>
    
    <!-- Player CSS -->
    <!-- phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Standalone popup page, not a WordPress page -->
    <link rel="stylesheet" href="<?php echo esc_url( MBR_LRP_PLUGIN_URL . 'assets/css/player.css' ); ?>?ver=<?php echo esc_attr( MBR_LRP_VERSION ); ?>">
    
    <!-- HLS.js (bundled locally) -->
    <!-- phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Standalone popup page, not a WordPress page -->
    <script src="<?php echo esc_url( MBR_LRP_PLUGIN_URL . 'assets/js/hls.min.js' ); ?>?ver=<?php echo esc_attr( MBR_LRP_VERSION ); ?>"></script>
    
    <style>
        /* Popup-specific styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: <?php echo esc_attr( $bg_gradient ); ?>;
            overflow: hidden;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .mbr-radio-player {
            max-width: 100%;
            margin: 0;
            padding: 10px;
            width: 100%;
        }
        
        .mbr-player-inner {
            box-shadow: none;
            background: transparent;
            padding: 16px;
        }
        
        /* Hide pop-out button in popup */
        .mbr-popout-btn {
            display: none !important;
        }
        
        /* Critical CSS - Force icon visibility */
        .mbr-play-btn .mbr-icon-pause { display: none !important; opacity: 0 !important; }
        .mbr-radio-player.playing .mbr-play-btn .mbr-icon-play { display: none !important; opacity: 0 !important; }
        .mbr-radio-player.playing .mbr-play-btn .mbr-icon-pause { display: block !important; opacity: 1 !important; }
        .mbr-radio-player.loading .mbr-play-btn .mbr-icon-play,
        .mbr-radio-player.loading .mbr-play-btn .mbr-icon-pause { display: none !important; opacity: 0 !important; }
        .mbr-radio-player.loading .mbr-play-btn .mbr-loading-spinner { display: block !important; opacity: 1 !important; }
        .mbr-loading-spinner { display: none; opacity: 0; }
        .mbr-volume-btn .mbr-icon-volume-muted { display: none !important; opacity: 0 !important; }
        .mbr-radio-player.muted .mbr-volume-btn .mbr-icon-volume-high { display: none !important; opacity: 0 !important; }
        .mbr-radio-player.muted .mbr-volume-btn .mbr-icon-volume-muted { display: block !important; opacity: 1 !important; }
        
        /* Popup-specific adjustments */
        .mbr-metadata-marquee {
            bottom: 30px !important; /* Move marquee up to leave 10px space below text */
        }
        
        .mbr-player-inner {
            padding-top: 9px !important; /* Reduce top padding by 15px (from 24px to 9px) */
            padding-bottom: 63px !important; /* Increase bottom padding by 15px (from 48px to 63px) to maintain marquee position */
        }
    </style>
</head>
<body>

<div class="mbr-radio-player<?php echo esc_attr( $popout_skin_class . $dark_mode_class . $glassmorphism_class ); ?>"<?php echo $custom_gradient; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already escaped via sprintf and esc_attr ?> 
     id="<?php echo esc_attr( $player_id ); ?>" 
     data-stream="<?php echo esc_url( $stream_url ); ?>"
     data-proxy-url="<?php echo esc_url( $proxy_url ); ?>"
     data-proxy-enabled="<?php echo $proxy_enabled ? '1' : '0'; ?>"
     data-station-id="<?php echo esc_attr( $station_id ); ?>">
    <div class="mbr-player-inner">
        <?php if ( $station_art ) : ?>
            <div class="mbr-player-artwork">
                <img src="<?php echo esc_url( $station_art ); ?>" alt="<?php echo esc_attr( $station_title ); ?>" class="mbr-station-art" />
                <img src="" alt="Track artwork" class="mbr-track-art" style="display:none;" />
            </div>
        <?php endif; ?>
        
        <div class="mbr-player-info">
            <h3 class="mbr-player-title"><?php echo esc_html( $station_title ); ?></h3>
            <p class="mbr-player-status">
                <span class="mbr-status-dot"></span>
                <span class="mbr-status-text"><?php esc_html_e( 'Ready to play', 'mbr-live-radio-player' ); ?></span>
            </p>
        </div>
        
        <div class="mbr-player-controls">
            <button class="mbr-play-btn" aria-label="<?php esc_attr_e( 'Play', 'mbr-live-radio-player' ); ?>">
                <svg class="mbr-icon mbr-icon-play" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="24" height="24">
                    <path d="M8 5v14l11-7z"/>
                </svg>
                <svg class="mbr-icon mbr-icon-pause" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="24" height="24">
                    <path d="M6 4h4v16H6V4zm8 0h4v16h-4V4z"/>
                </svg>
                <div class="mbr-loading-spinner"></div>
            </button>
            
            <div class="mbr-volume-control">
                <button class="mbr-volume-btn" aria-label="<?php esc_attr_e( 'Mute', 'mbr-live-radio-player' ); ?>">
                    <svg class="mbr-icon mbr-icon-volume-high" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="28" height="28">
                        <path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/>
                    </svg>
                    <svg class="mbr-icon mbr-icon-volume-muted" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="28" height="28">
                        <path d="M16.5 12c0-1.77-1.02-3.29-2.5-4.03v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51C20.63 14.91 21 13.5 21 12c0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71zM4.27 3L3 4.27 7.73 9H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06c1.38-.31 2.63-.95 3.69-1.81L19.73 21 21 19.73l-9-9L4.27 3zM12 4L9.91 6.09 12 8.18V4z"/>
                    </svg>
                </button>
                <input 
                    type="range" 
                    class="mbr-volume-slider" 
                    min="0" 
                    max="100" 
                    value="70"
                    aria-label="<?php esc_attr_e( 'Volume', 'mbr-live-radio-player' ); ?>"
                />
            </div>
        </div>
        
        <!-- Metadata Marquee -->
        <div class="mbr-metadata-marquee">
            <div class="mbr-marquee-content">
                <span class="mbr-now-playing"></span>
            </div>
        </div>
    </div>
</div>

<!-- Player JS -->
<script>
    // Define AJAX URL for the popup
    var mbrPlayerData = {
        ajaxUrl: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>'
    };
</script>
<!-- phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Standalone popup page, not a WordPress page -->
<script src="<?php echo esc_url( MBR_LRP_PLUGIN_URL . 'assets/js/player.js' ); ?>?ver=<?php echo esc_attr( MBR_LRP_VERSION ); ?>"></script>

</body>
</html>
