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
            'id'   => 0,
            'skin' => '',
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
        $mode       = get_post_meta( $station_id, '_mbr_lrp_mode', true );
        if ( empty( $mode ) ) $mode = 'stream';
        
        // File player mode — entirely different render path
        if ( $mode === 'files' ) {
            return $this->render_file_player( $station_id, $atts );
        }
        
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
        $skin = ! empty( $atts['skin'] ) ? sanitize_text_field( $atts['skin'] ) : get_post_meta( $station_id, '_mbr_lrp_skin', true );
        $allowed_skins = array( 'default', 'classic', 'gradient-dark', 'minimal', 'retro', 'slim-bar' );
        if ( empty( $skin ) || ! in_array( $skin, $allowed_skins, true ) ) {
            $skin = 'default';
        }
        $skin_class = ( $skin !== 'default' ) ? ' mbr-skin-' . $skin : '';
        
        $dark_mode = get_post_meta( $station_id, '_mbr_lrp_dark_mode', true ) === '1';
        $dark_mode_class = ( $skin === 'default' && $dark_mode ) ? ' mbr-dark-mode' : '';
        
        $glassmorphism = get_post_meta( $station_id, '_mbr_lrp_glassmorphism', true ) === '1';
        $glassmorphism_class = ( $skin === 'default' && $glassmorphism ) ? ' mbr-glassmorphism' : '';
        
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
        if ( $skin === 'default' && ! $glassmorphism && ! $dark_mode ) {
            $custom_gradient = sprintf(
                ' style="--mbr-gradient-color-1: %s; --mbr-gradient-color-2: %s;"',
                esc_attr( $gradient_color_1 ),
                esc_attr( $gradient_color_2 )
            );
        }
        
        // Build station group data attribute
        $station_group_attr = '';
        $saved_group = get_post_meta( $station_id, '_mbr_lrp_station_group', true );
        if ( is_array( $saved_group ) && ! empty( $saved_group ) ) {
            $group_data = array();
            foreach ( $saved_group as $sid ) {
                $sid = absint( $sid );
                $sp  = get_post( $sid );
                if ( ! $sp || 'mbr_radio_station' !== $sp->post_type || 'publish' !== $sp->post_status ) continue;
                $s_stream = get_post_meta( $sid, '_mbr_lrp_stream_url', true );
                if ( ! $s_stream ) continue;
                $group_data[] = array(
                    'id'    => $sid,
                    'title' => get_the_title( $sid ),
                    'art'   => get_the_post_thumbnail_url( $sid, 'thumbnail' ) ?: '',
                    'stream'=> $s_stream,
                );
            }
            if ( ! empty( $group_data ) ) {
                $station_group_attr = ' data-station-group="' . esc_attr( wp_json_encode( $group_data ) ) . '"';
            }
        }
        
        // Build player HTML
        ob_start();
        ?>
        <div class="mbr-radio-player<?php echo esc_attr( $skin_class . $dark_mode_class . $glassmorphism_class ); ?>"<?php echo $custom_gradient; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already escaped via sprintf and esc_attr ?> 
             id="<?php echo esc_attr( $player_id ); ?>" 
             data-stream="<?php echo esc_url( $stream_url ); ?>"
             data-proxy-url="<?php echo esc_url( $proxy_url ); ?>"
             data-proxy-enabled="<?php echo $proxy_enabled ? '1' : '0'; ?>"
             data-station-id="<?php echo esc_attr( $station_id ); ?>"<?php echo $station_group_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped via esc_attr above ?>>
            <div class="mbr-player-inner">
                <!-- Pop-out Button -->
                <button class="mbr-popout-btn" aria-label="<?php esc_attr_e( 'Open in popup window', 'mbr-live-radio-player' ); ?>" title="<?php esc_attr_e( 'Pop-out player', 'mbr-live-radio-player' ); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
                        <path d="M19 19H5V5h7V3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2v-7h-2v7zM14 3v2h3.59l-9.83 9.83 1.41 1.41L19 6.41V10h2V3h-7z"/>
                    </svg>
                </button>
                <?php if ( ! empty( $station_group_attr ) ) : ?>
                <!-- Stations Toggle Button -->
                <button class="mbr-stations-btn" aria-label="<?php esc_attr_e( 'Show station list', 'mbr-live-radio-player' ); ?>" title="<?php esc_attr_e( 'Stations', 'mbr-live-radio-player' ); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="18" height="18"><path d="M4 6h16v2H4zm0 5h16v2H4zm0 5h16v2H4z"/></svg>
                </button>
                <?php endif; ?>
                <?php if ( $station_art ) : ?>
                    <div class="mbr-player-artwork">
                        <img src="<?php echo esc_url( $station_art ); ?>" alt="<?php echo esc_attr( $station_title ); ?>" class="mbr-station-art" />
                    </div>
                <?php else : ?>
                    <div class="mbr-player-artwork" style="display:none;">
                        <img src="" alt="" class="mbr-station-art" />
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
                
                <?php if ( ! empty( $station_group_attr ) ) : ?>
                <!-- Station List Panel -->
                <div class="mbr-station-list" aria-hidden="true">
                    <div class="mbr-station-list-header">
                        <span><?php esc_html_e( 'Stations', 'mbr-live-radio-player' ); ?></span>
                        <button class="mbr-station-list-close" aria-label="<?php esc_attr_e( 'Close station list', 'mbr-live-radio-player' ); ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="18" height="18"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
                        </button>
                    </div>
                    <div class="mbr-station-list-items">
                        <!-- Current station first -->
                        <div class="mbr-station-item mbr-station-item--current"
                             data-stream="<?php echo esc_attr( $stream_url ); ?>"
                             data-title="<?php echo esc_attr( $station_title ); ?>"
                             data-art="<?php echo esc_attr( $station_art ?: '' ); ?>">
                            <?php if ( $station_art ) : ?>
                                <img src="<?php echo esc_url( $station_art ); ?>" alt="" class="mbr-station-item-art" />
                            <?php else : ?>
                                <span class="mbr-station-item-art mbr-station-item-art--placeholder"></span>
                            <?php endif; ?>
                            <span class="mbr-station-item-title"><?php echo esc_html( $station_title ); ?></span>
                            <span class="mbr-station-item-playing-indicator">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="14" height="14"><path d="M8 5v14l11-7z"/></svg>
                            </span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render the file player (local/hosted audio files with progress bar + track list)
     */
    public function render_file_player( $station_id, $atts ) {
        $tracks = get_post_meta( $station_id, '_mbr_lrp_tracks', true );
        if ( ! is_array( $tracks ) || empty( $tracks ) ) {
            return '<p>' . esc_html__( 'No tracks configured for this station.', 'mbr-live-radio-player' ) . '</p>';
        }
        
        $station_title = get_the_title( $station_id );
        $station_art   = get_the_post_thumbnail_url( $station_id, 'medium' );
        $player_id     = 'mbr-radio-player-' . $station_id . '-' . wp_rand();
        
        // ── Appearance — identical logic to stream player ──────────────────
        $skin = ! empty( $atts['skin'] ) ? sanitize_text_field( $atts['skin'] ) : get_post_meta( $station_id, '_mbr_lrp_skin', true );
        $allowed_skins = array( 'default', 'classic', 'gradient-dark', 'minimal', 'retro', 'slim-bar' );
        if ( empty( $skin ) || ! in_array( $skin, $allowed_skins, true ) ) $skin = 'default';
        $skin_class = ( $skin !== 'default' ) ? ' mbr-skin-' . $skin : '';
        
        $dark_mode        = get_post_meta( $station_id, '_mbr_lrp_dark_mode', true ) === '1';
        $dark_mode_class  = ( $skin === 'default' && $dark_mode ) ? ' mbr-dark-mode' : '';
        
        $glassmorphism       = get_post_meta( $station_id, '_mbr_lrp_glassmorphism', true ) === '1';
        $glassmorphism_class = ( $skin === 'default' && $glassmorphism ) ? ' mbr-glassmorphism' : '';
        
        $gradient_color_1 = get_post_meta( $station_id, '_mbr_lrp_gradient_color_1', true );
        $gradient_color_2 = get_post_meta( $station_id, '_mbr_lrp_gradient_color_2', true );
        if ( empty( $gradient_color_1 ) || ! preg_match( '/^#[a-f0-9]{6}$/i', $gradient_color_1 ) ) $gradient_color_1 = '#667eea';
        if ( empty( $gradient_color_2 ) || ! preg_match( '/^#[a-f0-9]{6}$/i', $gradient_color_2 ) ) $gradient_color_2 = '#764ba2';
        
        $custom_gradient = '';
        if ( $skin === 'default' && ! $glassmorphism && ! $dark_mode ) {
            $custom_gradient = sprintf(
                ' style="--mbr-gradient-color-1: %s; --mbr-gradient-color-2: %s;"',
                esc_attr( $gradient_color_1 ),
                esc_attr( $gradient_color_2 )
            );
        }
        
        // Encode tracks as JSON for JS
        $tracks_json = wp_json_encode( array_values( $tracks ) );
        
        ob_start();
        ?>
        <div id="<?php echo esc_attr( $player_id ); ?>"
             class="mbr-radio-player<?php echo esc_attr( $skin_class . $dark_mode_class . $glassmorphism_class ); ?> mbr-mode-files"
             data-tracks="<?php echo esc_attr( $tracks_json ); ?>"
             data-track-index="0">
            
            <div class="mbr-player-inner"<?php echo $custom_gradient; // phpcs:ignore -- escaped via sprintf/esc_attr ?>>
            
            <audio class="mbr-audio" preload="none"></audio>
            
            <?php if ( $station_art ) : ?>
                <div class="mbr-player-artwork">
                    <img src="<?php echo esc_url( $station_art ); ?>" alt="<?php echo esc_attr( $station_title ); ?>" class="mbr-station-art" />
                </div>
            <?php endif; ?>
            
            <div class="mbr-player-info">
                <h3 class="mbr-player-title"><?php echo esc_html( $station_title ); ?></h3>
                <p class="mbr-player-status">
                    <span class="mbr-status-dot"></span>
                    <span class="mbr-status-text"><?php esc_html_e( 'Ready to play', 'mbr-live-radio-player' ); ?></span>
                </p>
            </div>
            
            <!-- Track title marquee -->
            <div class="mbr-metadata-marquee mbr-file-track-name-bar">
                <div class="mbr-marquee-content">
                    <span class="mbr-now-playing mbr-file-track-name"></span>
                </div>
            </div>
            
            <!-- Progress bar -->
            <div class="mbr-progress-bar-wrapper">
                <span class="mbr-time-current">0:00</span>
                <div class="mbr-progress-bar" role="slider" aria-label="<?php esc_attr_e( 'Seek', 'mbr-live-radio-player' ); ?>">
                    <div class="mbr-progress-fill"></div>
                    <div class="mbr-progress-handle"></div>
                </div>
                <span class="mbr-time-duration">0:00</span>
            </div>
            
            <!-- Controls -->
            <div class="mbr-player-controls mbr-file-controls">
                <button class="mbr-rewind-btn" aria-label="<?php esc_attr_e( 'Rewind 15 seconds', 'mbr-live-radio-player' ); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="22" height="22">
                        <path d="M11.99 5V1l-5 5 5 5V7c3.31 0 6 2.69 6 6s-2.69 6-6 6-6-2.69-6-6h-2c0 4.42 3.58 8 8 8s8-3.58 8-8-3.58-8-8-8z"/>
                        <text x="8.5" y="14.5" font-size="5.5" font-family="sans-serif" font-weight="bold" fill="currentColor">15</text>
                    </svg>
                </button>
                
                <button class="mbr-play-btn" aria-label="<?php esc_attr_e( 'Play', 'mbr-live-radio-player' ); ?>">
                    <svg class="mbr-icon mbr-icon-play" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="24" height="24">
                        <path d="M8 5v14l11-7z"/>
                    </svg>
                    <svg class="mbr-icon mbr-icon-pause" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="24" height="24">
                        <path d="M6 4h4v16H6V4zm8 0h4v16h-4V4z"/>
                    </svg>
                    <div class="mbr-loading-spinner"></div>
                </button>
                
                <button class="mbr-forward-btn" aria-label="<?php esc_attr_e( 'Forward 15 seconds', 'mbr-live-radio-player' ); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="22" height="22">
                        <path d="M12.01 5V1l5 5-5 5V7c-3.31 0-6 2.69-6 6s2.69 6 6 6 6-2.69 6-6h2c0 4.42-3.58 8-8 8s-8-3.58-8-8 3.58-8 8-8z"/>
                        <text x="8.5" y="14.5" font-size="5.5" font-family="sans-serif" font-weight="bold" fill="currentColor">15</text>
                    </svg>
                </button>
                
                <div class="mbr-volume-control">
                    <button class="mbr-volume-btn" aria-label="<?php esc_attr_e( 'Mute', 'mbr-live-radio-player' ); ?>">
                        <svg class="mbr-icon mbr-icon-volume-high" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="24" height="24">
                            <path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/>
                        </svg>
                        <svg class="mbr-icon mbr-icon-volume-muted" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="24" height="24">
                            <path d="M16.5 12c0-1.77-1.02-3.29-2.5-4.03v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51C20.63 14.91 21 13.5 21 12c0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71zM4.27 3L3 4.27 7.73 9H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06c1.38-.31 2.63-.95 3.69-1.81L19.73 21 21 19.73l-9-9L4.27 3zM12 4L9.91 6.09 12 8.18V4z"/>
                        </svg>
                    </button>
                    <input type="range" class="mbr-volume-slider" min="0" max="100" value="70"
                           aria-label="<?php esc_attr_e( 'Volume', 'mbr-live-radio-player' ); ?>" />
                </div>
                
                <?php if ( count( $tracks ) > 1 ) : ?>
                <button class="mbr-tracklist-btn" aria-label="<?php esc_attr_e( 'Track list', 'mbr-live-radio-player' ); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
                        <path d="M3 13h2v-2H3v2zm0 4h2v-2H3v2zm0-8h2V7H3v2zm4 4h14v-2H7v2zm0 4h14v-2H7v2zM7 7v2h14V7H7z"/>
                    </svg>
                </button>
                <?php endif; ?>
            </div>
            
            <?php if ( count( $tracks ) > 1 ) : ?>
            <!-- Track List Panel -->
            <div class="mbr-station-list mbr-tracklist-panel" aria-hidden="true">
                <div class="mbr-station-list-header">
                    <span><?php esc_html_e( 'Tracks', 'mbr-live-radio-player' ); ?></span>
                    <button class="mbr-station-list-close" aria-label="<?php esc_attr_e( 'Close track list', 'mbr-live-radio-player' ); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="18" height="18">
                            <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                        </svg>
                    </button>
                </div>
                <div class="mbr-station-list-items mbr-tracklist-items">
                    <?php foreach ( $tracks as $i => $track ) :
                        $t_title = ! empty( $track['title'] ) ? $track['title'] : basename( $track['url'] );
                    ?>
                    <div class="mbr-station-item mbr-track-item<?php echo $i === 0 ? ' mbr-station-item--current' : ''; ?>"
                         data-track-index="<?php echo esc_attr( $i ); ?>"
                         data-url="<?php echo esc_url( $track['url'] ); ?>"
                         data-title="<?php echo esc_attr( $t_title ); ?>">
                        <span class="mbr-track-item-number"><?php echo esc_html( $i + 1 ); ?></span>
                        <span class="mbr-station-item-title"><?php echo esc_html( $t_title ); ?></span>
                        <span class="mbr-station-item-playing-indicator">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="14" height="14">
                                <path d="M8 5v14l11-7z"/>
                            </svg>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            </div><!-- /.mbr-player-inner -->
        </div><!-- /.mbr-radio-player -->
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
