<?php
/**
 * Sticky Player Settings Page
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MBR_LRP_Settings {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }
    
    /**
     * Add settings page to WordPress admin
     */
    public function add_settings_page() {
        add_submenu_page(
            'edit.php?post_type=mbr_radio_station',
            __( 'Sticky Player', 'mbr-live-radio-player' ),
            __( 'Sticky Player', 'mbr-live-radio-player' ),
            'manage_options',
            'mbr-lrp-settings',
            array( $this, 'render_settings_page' )
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        // Register sticky player position
        register_setting(
            'mbr_lrp_settings',
            'mbr_lrp_sticky_position',
            array(
                'type' => 'string',
                'default' => 'bottom',
                'sanitize_callback' => array( $this, 'sanitize_sticky_position' ),
            )
        );
        
        // Register hide on mobile setting
        register_setting(
            'mbr_lrp_settings',
            'mbr_lrp_sticky_hide_mobile',
            array(
                'type' => 'string',
                'default' => '0',
                'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
            )
        );
        
        // Add sticky player section
        add_settings_section(
            'mbr_lrp_sticky_section',
            __( 'Sticky Player Configuration', 'mbr-live-radio-player' ),
            array( $this, 'sticky_section_callback' ),
            'mbr-lrp-settings'
        );
        
        // Add sticky position field
        add_settings_field(
            'mbr_lrp_sticky_position',
            __( 'Player Position', 'mbr-live-radio-player' ),
            array( $this, 'sticky_position_field_callback' ),
            'mbr-lrp-settings',
            'mbr_lrp_sticky_section'
        );
        
        // Add hide on mobile field
        add_settings_field(
            'mbr_lrp_sticky_hide_mobile',
            __( 'Hide on Mobile', 'mbr-live-radio-player' ),
            array( $this, 'hide_mobile_field_callback' ),
            'mbr-lrp-settings',
            'mbr_lrp_sticky_section'
        );
    }
    
    /**
     * Sanitize sticky position value
     */
    public function sanitize_sticky_position( $value ) {
        $valid_positions = array( 'top', 'bottom' );
        return in_array( $value, $valid_positions, true ) ? $value : 'bottom';
    }
    
    /**
     * Sanitize checkbox value
     */
    public function sanitize_checkbox( $value ) {
        return $value === '1' ? '1' : '0';
    }
    
    /**
     * Sticky section description
     */
    public function sticky_section_callback() {
        ?>
        <p><?php esc_html_e( 'Configure where the sticky player appears on your site.', 'mbr-live-radio-player' ); ?></p>
        <div style="background: #fff3cd; border-left: 4px solid #f0ad4e; padding: 12px; margin: 15px 0;">
            <p style="margin: 0;"><strong><?php esc_html_e( 'How to use the Sticky Player:', 'mbr-live-radio-player' ); ?></strong></p>
            <ol style="margin: 10px 0 0 20px;">
                <li><?php esc_html_e( 'Create and style your radio station (artwork, colors, stream URL)', 'mbr-live-radio-player' ); ?></li>
                <li><?php esc_html_e( 'Choose the position below (top or bottom)', 'mbr-live-radio-player' ); ?></li>
                <li><?php esc_html_e( 'Save settings', 'mbr-live-radio-player' ); ?></li>
                <li><?php esc_html_e( 'Use shortcode: [mbr_radio_player_sticky id="YOUR_STATION_ID"]', 'mbr-live-radio-player' ); ?></li>
            </ol>
            <p style="margin: 10px 0 0 0;"><em><?php esc_html_e( 'The sticky player inherits all appearance settings (colors, artwork) from the station itself.', 'mbr-live-radio-player' ); ?></em></p>
        </div>
        <?php
    }
    
    /**
     * Sticky position field
     */
    public function sticky_position_field_callback() {
        $sticky_position = get_option( 'mbr_lrp_sticky_position', 'bottom' );
        ?>
        <select name="mbr_lrp_sticky_position" style="min-width: 200px;">
            <option value="top" <?php selected( $sticky_position, 'top' ); ?>>
                <?php esc_html_e( 'Top of page', 'mbr-live-radio-player' ); ?>
            </option>
            <option value="bottom" <?php selected( $sticky_position, 'bottom' ); ?>>
                <?php esc_html_e( 'Bottom of page (recommended)', 'mbr-live-radio-player' ); ?>
            </option>
        </select>
        <p class="description">
            <?php esc_html_e( 'Choose whether the sticky player appears at the top or bottom of the browser window.', 'mbr-live-radio-player' ); ?>
        </p>
        <?php
    }
    
    /**
     * Hide on mobile field
     */
    public function hide_mobile_field_callback() {
        $hide_mobile = get_option( 'mbr_lrp_sticky_hide_mobile', '0' );
        ?>
        <label>
            <input type="checkbox" name="mbr_lrp_sticky_hide_mobile" value="1" <?php checked( $hide_mobile, '1' ); ?>>
            <?php esc_html_e( 'Hide sticky player on mobile devices (screens smaller than 768px)', 'mbr-live-radio-player' ); ?>
        </label>
        <p class="description">
            <?php esc_html_e( 'Enable this option if the sticky player looks cramped on mobile devices. The regular player will still work fine on mobile.', 'mbr-live-radio-player' ); ?>
        </p>
        <?php
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        // Check user capabilities
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        
        // Show success message if settings saved
        if ( isset( $_GET['settings-updated'] ) ) {
            add_settings_error(
                'mbr_lrp_messages',
                'mbr_lrp_message',
                __( 'Sticky player settings saved!', 'mbr-live-radio-player' ),
                'success'
            );
        }
        
        settings_errors( 'mbr_lrp_messages' );
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            
            <form action="options.php" method="post">
                <?php
                settings_fields( 'mbr_lrp_settings' );
                do_settings_sections( 'mbr-lrp-settings' );
                submit_button( __( 'Save Settings', 'mbr-live-radio-player' ) );
                ?>
            </form>
        </div>
        <?php
    }
}
