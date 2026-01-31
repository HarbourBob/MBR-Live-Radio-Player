<?php
/**
 * Assets Management
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MBR_LRP_Assets {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
        add_filter( 'body_class', array( $this, 'add_body_classes' ) );
    }
    
    /**
     * Enqueue frontend assets
     */
    public function enqueue_frontend_assets() {
        // Check multiple ways for shortcode presence to support page builders like Elementor
        global $post;
        
        $has_regular_player = false;
        $has_sticky_player = false;
        
        // Method 1: Check post content directly
        if ( is_a( $post, 'WP_Post' ) ) {
            $has_regular_player = has_shortcode( $post->post_content, 'mbr_radio_player' );
            $has_sticky_player = has_shortcode( $post->post_content, 'mbr_radio_player_sticky' );
        }
        
        // Method 2: Check Elementor data (for Elementor compatibility)
        if ( ! $has_regular_player && ! $has_sticky_player && is_a( $post, 'WP_Post' ) ) {
            $elementor_data = get_post_meta( $post->ID, '_elementor_data', true );
            if ( $elementor_data ) {
                $has_regular_player = strpos( $elementor_data, 'mbr_radio_player' ) !== false;
                $has_sticky_player = strpos( $elementor_data, 'mbr_radio_player_sticky' ) !== false;
            }
        }
        
        // Method 3: Always load on radio station pages or if we're unsure
        if ( ! $has_regular_player && ! $has_sticky_player ) {
            if ( is_singular( 'mbr_radio_station' ) || is_post_type_archive( 'mbr_radio_station' ) ) {
                $has_regular_player = true;
            }
        }
        
        if ( $has_regular_player || $has_sticky_player ) {
            // Player CSS
            wp_enqueue_style(
                'mbr-lrp-player',
                MBR_LRP_PLUGIN_URL . 'assets/css/player.css',
                array(),
                MBR_LRP_VERSION . '-' . time() // Add timestamp to force cache refresh
            );
            
            // Add critical inline CSS to force icon visibility
            $critical_css = '
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
                
                /* Sticky Player Icon States */
                .mbr-radio-player-sticky .mbr-icon-pause { display: none !important; opacity: 0 !important; }
                .mbr-radio-player-sticky.playing .mbr-icon-play { display: none !important; opacity: 0 !important; }
                .mbr-radio-player-sticky.playing .mbr-icon-pause { display: block !important; opacity: 1 !important; visibility: visible !important; }
                .mbr-radio-player-sticky.loading .mbr-icon-play,
                .mbr-radio-player-sticky.loading .mbr-icon-pause { display: none !important; opacity: 0 !important; }
                .mbr-radio-player-sticky.loading .mbr-loading-spinner { display: block !important; opacity: 1 !important; }
                .mbr-radio-player-sticky .mbr-icon-volume-muted { display: none !important; opacity: 0 !important; }
                .mbr-radio-player-sticky.muted .mbr-icon-volume-high { display: none !important; opacity: 0 !important; }
                .mbr-radio-player-sticky.muted .mbr-icon-volume-muted { display: block !important; opacity: 1 !important; }
            ';
            wp_add_inline_style( 'mbr-lrp-player', $critical_css );
            
            // HLS.js for HLS streams (bundled locally)
            wp_enqueue_script(
                'hls-js',
                MBR_LRP_PLUGIN_URL . 'assets/js/hls.min.js',
                array(),
                '1.4.12',
                true
            );
            
            // Player JS
            wp_enqueue_script(
                'mbr-lrp-player',
                MBR_LRP_PLUGIN_URL . 'assets/js/player.js',
                array( 'hls-js' ),
                MBR_LRP_VERSION . '-' . time(), // Add timestamp to force cache refresh
                true
            );
            
            // Localize script with AJAX URL and popup URL
            wp_localize_script(
                'mbr-lrp-player',
                'mbrPlayerData',
                array(
                    'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                    'ajaxProxyUrl' => admin_url( 'admin-ajax.php?action=mbr_proxy_stream' ),
                    'useAjaxProxy' => true, // Force AJAX proxy since rewrite rules don't work
                )
            );
            
            wp_localize_script(
                'mbr-lrp-player',
                'mbrRadioPlayer',
                array(
                    'popupUrl' => home_url( '/mbr-radio-popup/' ),
                )
            );
        }
    }
    
    /**
     * Add body classes for sticky player mobile hide
     */
    public function add_body_classes( $classes ) {
        // Check if hide on mobile option is enabled
        $hide_mobile = get_option( 'mbr_lrp_sticky_hide_mobile', '0' );
        
        if ( $hide_mobile === '1' ) {
            $classes[] = 'mbr-hide-sticky-mobile';
        }
        
        return $classes;
    }
}
