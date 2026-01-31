<?php
/**
 * Shortcode Handler
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MBR_LRP_Shortcode {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_shortcode( 'mbr_radio_player', array( $this, 'render_player' ) );
        add_shortcode( 'mbr_radio_player_sticky', array( $this, 'render_sticky_player' ) );
    }
    
    /**
     * Render the radio player
     */
    public function render_player( $atts ) {
        $atts = shortcode_atts( array(
            'id' => 0,
        ), $atts, 'mbr_radio_player' );
        
        $station_id = intval( $atts['id'] );
        
        if ( ! $station_id ) {
            return '<p>' . esc_html__( 'Please provide a valid radio station ID.', 'mbr-live-radio-player' ) . '</p>';
        }
        
        // Get station details
        $station = get_post( $station_id );
        
        if ( ! $station || 'mbr_radio_station' !== $station->post_type || 'publish' !== $station->post_status ) {
            return '<p>' . esc_html__( 'Radio station not found.', 'mbr-live-radio-player' ) . '</p>';
        }
        
        $stream_url = get_post_meta( $station_id, '_mbr_lrp_stream_url', true );
        
        if ( ! $stream_url ) {
            return '<p>' . esc_html__( 'No stream URL configured for this station.', 'mbr-live-radio-player' ) . '</p>';
        }
        
        $station_title = get_the_title( $station_id );
        $station_art = get_the_post_thumbnail_url( $station_id, 'medium' );
        
        // Generate unique player ID
        $player_id = 'mbr-radio-player-' . $station_id . '-' . wp_rand();
        
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
        
        $glassmorphism = get_post_meta( $station_id, '_mbr_lrp_glassmorphism', true ) === '1';
        $glassmorphism_class = $glassmorphism ? ' mbr-glassmorphism' : '';
        
        // Get custom gradient colors from post meta
        $gradient_color_1 = get_post_meta( $station_id, '_mbr_lrp_gradient_color_1', true );
        $gradient_color_2 = get_post_meta( $station_id, '_mbr_lrp_gradient_color_2', true );
        
        // Validate colors at output time to prevent XSS (defense in depth)
        // Even though colors are validated on save, this protects against direct DB manipulation
        if ( empty( $gradient_color_1 ) || ! preg_match( '/^#[a-f0-9]{6}$/i', $gradient_color_1 ) ) {
            $gradient_color_1 = '#667eea'; // Force safe default
        }
        if ( empty( $gradient_color_2 ) || ! preg_match( '/^#[a-f0-9]{6}$/i', $gradient_color_2 ) ) {
            $gradient_color_2 = '#764ba2'; // Force safe default
        }
        
        // Build gradient style - colors are now guaranteed to be safe hex values
        $custom_gradient = '';
        if ( ! $glassmorphism && ! $dark_mode ) {
            $custom_gradient = sprintf(
                ' style="--mbr-gradient-color-1: %s; --mbr-gradient-color-2: %s;"',
                esc_attr( $gradient_color_1 ),
                esc_attr( $gradient_color_2 )
            );
        }
        
        // Build player HTML
        ob_start();
        ?>
        <div class="mbr-radio-player<?php echo esc_attr( $dark_mode_class . $glassmorphism_class ); ?>"<?php echo $custom_gradient; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already escaped via sprintf and esc_attr ?> 
             id="<?php echo esc_attr( $player_id ); ?>" 
             data-stream="<?php echo esc_url( $stream_url ); ?>"
             data-proxy-url="<?php echo esc_url( $proxy_url ); ?>"
             data-proxy-enabled="<?php echo $proxy_enabled ? '1' : '0'; ?>"
             data-station-id="<?php echo esc_attr( $station_id ); ?>">
            <div class="mbr-player-inner">
                <!-- Pop-out Button -->
                <button class="mbr-popout-btn" aria-label="<?php esc_attr_e( 'Open in popup window', 'mbr-live-radio-player' ); ?>" title="<?php esc_attr_e( 'Pop-out player', 'mbr-live-radio-player' ); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
                        <path d="M19 19H5V5h7V3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2v-7h-2v7zM14 3v2h3.59l-9.83 9.83 1.41 1.41L19 6.41V10h2V3h-7z"/>
                    </svg>
                </button>
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
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render the sticky radio player
     */
    public function render_sticky_player( $atts ) {
        // Log that shortcode was called
        error_log( 'MBR Sticky Player: Shortcode called with attributes: ' . print_r( $atts, true ) );
        
        $atts = shortcode_atts( array(
            'id' => 0,
        ), $atts, 'mbr_radio_player_sticky' );
        
        $station_id = intval( $atts['id'] );
        
        error_log( 'MBR Sticky Player: Station ID = ' . $station_id );
        
        if ( ! $station_id ) {
            $error_msg = '<p style="color: red; background: #fff3cd; padding: 10px; border: 2px solid red;">' . esc_html__( 'MBR Sticky Player Error: Please provide a valid radio station ID. Example: [mbr_radio_player_sticky id="123"]', 'mbr-live-radio-player' ) . '</p>';
            error_log( 'MBR Sticky Player: No station ID provided' );
            return $error_msg;
        }
        
        // Get station details
        $station = get_post( $station_id );
        
        if ( ! $station || 'mbr_radio_station' !== $station->post_type || 'publish' !== $station->post_status ) {
            $error_msg = '<p style="color: red; background: #fff3cd; padding: 10px; border: 2px solid red;">' . sprintf( esc_html__( 'MBR Sticky Player Error: Radio station ID %d not found or not published.', 'mbr-live-radio-player' ), $station_id ) . '</p>';
            error_log( 'MBR Sticky Player: Station not found or not published. ID: ' . $station_id );
            return $error_msg;
        }
        
        $stream_url = get_post_meta( $station_id, '_mbr_lrp_stream_url', true );
        
        if ( ! $stream_url ) {
            return '<p>' . esc_html__( 'No stream URL configured for this station.', 'mbr-live-radio-player' ) . '</p>';
        }
        
        $station_title = get_the_title( $station_id );
        $station_art = get_the_post_thumbnail_url( $station_id, 'thumbnail' );
        
        // Generate unique player ID
        $player_id = 'mbr-radio-player-sticky-' . $station_id;
        
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
        
        $proxy_url = $proxy_enabled ? admin_url( 'admin-ajax.php?action=mbr_proxy_stream&token=' . urlencode( $proxy_token ) . '&' ) : '';
        
        // Get sticky position setting (default to bottom)
        $sticky_position = get_option( 'mbr_lrp_sticky_position', 'bottom' );
        
        // Get appearance settings - BUT don't apply dark mode or glassmorphism to sticky player
        // Sticky player always uses gradient for best visibility
        $dark_mode = false;
        $dark_mode_class = '';
        
        $glassmorphism = false;
        $glassmorphism_class = '';
        
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
        
        // Build gradient style - ALWAYS output for sticky player with validated colors
        $custom_gradient = sprintf(
            ' style="--mbr-gradient-color-1: %s; --mbr-gradient-color-2: %s;"',
            esc_attr( $gradient_color_1 ),
            esc_attr( $gradient_color_2 )
        );
        
        error_log( 'MBR Sticky: Gradient Color 1: ' . $gradient_color_1 );
        error_log( 'MBR Sticky: Gradient Color 2: ' . $gradient_color_2 );
        error_log( 'MBR Sticky: Dark Mode: ' . ( $dark_mode ? 'yes' : 'no' ) );
        error_log( 'MBR Sticky: Glassmorphism: ' . ( $glassmorphism ? 'yes' : 'no' ) );
        error_log( 'MBR Sticky: Custom Gradient: ' . $custom_gradient );
        
        // Build sticky player HTML
        ob_start();
        
        error_log( 'MBR Sticky Player: Rendering player for station: ' . $station_title );
        
        ?>
        <div class="mbr-radio-player-sticky mbr-sticky-<?php echo esc_attr( $sticky_position ); ?><?php echo esc_attr( $dark_mode_class . $glassmorphism_class ); ?>"<?php echo $custom_gradient; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already escaped via sprintf and esc_attr ?> 
             id="<?php echo esc_attr( $player_id ); ?>" 
             data-stream="<?php echo esc_url( $stream_url ); ?>"
             data-proxy-url="<?php echo esc_url( $proxy_url ); ?>"
             data-proxy-enabled="<?php echo $proxy_enabled ? '1' : '0'; ?>"
             data-station-id="<?php echo esc_attr( $station_id ); ?>"
             data-sticky-position="<?php echo esc_attr( $sticky_position ); ?>">
            <div class="mbr-sticky-inner">
                <!-- Close button -->
                <button class="mbr-sticky-close" aria-label="<?php esc_attr_e( 'Close player', 'mbr-live-radio-player' ); ?>" title="<?php esc_attr_e( 'Close', 'mbr-live-radio-player' ); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="20" height="20">
                        <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                    </svg>
                </button>
                
                <?php if ( $station_art ) : ?>
                    <div class="mbr-sticky-artwork">
                        <img src="<?php echo esc_url( $station_art ); ?>" alt="<?php echo esc_attr( $station_title ); ?>" class="mbr-station-art" />
                    </div>
                <?php endif; ?>
                
                <div class="mbr-sticky-info">
                    <h4 class="mbr-sticky-title"><?php echo esc_html( $station_title ); ?></h4>
                    <div class="mbr-sticky-metadata">
                        <span class="mbr-now-playing-text"></span>
                    </div>
                </div>
                
                <div class="mbr-sticky-controls">
                    <button class="mbr-play-btn" aria-label="<?php esc_attr_e( 'Play', 'mbr-live-radio-player' ); ?>">
                        <svg class="mbr-icon mbr-icon-play" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="24" height="24">
                            <path d="M8 5v14l11-7z"/>
                        </svg>
                        <svg class="mbr-icon mbr-icon-pause" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="24" height="24">
                            <path d="M6 4h4v16H6V4zm8 0h4v16h-4V4z"/>
                        </svg>
                        <div class="mbr-loading-spinner"></div>
                    </button>
                    
                    <button class="mbr-volume-btn" aria-label="<?php esc_attr_e( 'Mute', 'mbr-live-radio-player' ); ?>">
                        <svg class="mbr-icon mbr-icon-volume-high" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="24" height="24">
                            <path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02z"/>
                        </svg>
                        <svg class="mbr-icon mbr-icon-volume-muted" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="24" height="24">
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
                    
                    <!-- Pop-out Button -->
                    <button class="mbr-popout-btn" aria-label="<?php esc_attr_e( 'Open in popup window', 'mbr-live-radio-player' ); ?>" title="<?php esc_attr_e( 'Pop-out player', 'mbr-live-radio-player' ); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
                            <path d="M19 19H5V5h7V3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2v-7h-2v7zM14 3v2h3.59l-9.83 9.83 1.41 1.41L19 6.41V10h2V3h-7z"/>
                        </svg>
                    </button>
                </div>
                
                <p class="mbr-sticky-status">
                    <span class="mbr-status-dot"></span>
                    <span class="mbr-status-text"><?php esc_html_e( 'Ready', 'mbr-live-radio-player' ); ?></span>
                </p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
