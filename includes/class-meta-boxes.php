<?php
/**
 * Meta Boxes for Radio Station Admin
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MBR_LRP_Meta_Boxes {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
        add_action( 'save_post_mbr_radio_station', array( $this, 'save_meta_boxes' ), 10, 2 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
    }
    
    /**
     * Add meta boxes
     */
    public function add_meta_boxes() {
        // Station details meta box
        add_meta_box(
            'mbr_lrp_station_details',
            __( 'Station Details', 'mbr-live-radio-player' ),
            array( $this, 'render_station_details' ),
            'mbr_radio_station',
            'normal',
            'high'
        );
        
        // Appearance settings meta box
        add_meta_box(
            'mbr_lrp_appearance',
            __( 'Player Appearance', 'mbr-live-radio-player' ),
            array( $this, 'render_appearance_settings' ),
            'mbr_radio_station',
            'normal',
            'high'
        );
        
        // Live preview meta box
        add_meta_box(
            'mbr_lrp_live_preview',
            __( 'Live Preview', 'mbr-live-radio-player' ),
            array( $this, 'render_live_preview' ),
            'mbr_radio_station',
            'side',
            'high'
        );
        
        // Shortcode meta box
        add_meta_box(
            'mbr_lrp_shortcode',
            __( 'Shortcode', 'mbr-live-radio-player' ),
            array( $this, 'render_shortcode' ),
            'mbr_radio_station',
            'side',
            'default'
        );
    }
    
    /**
     * Render station details meta box
     */
    public function render_station_details( $post ) {
        wp_nonce_field( 'mbr_lrp_save_meta', 'mbr_lrp_meta_nonce' );
        
        $stream_url = get_post_meta( $post->ID, '_mbr_lrp_stream_url', true );
        ?>
        <div class="mbr-lrp-meta-box">
            <p>
                <label for="mbr_lrp_stream_url">
                    <strong><?php esc_html_e( 'Stream URL', 'mbr-live-radio-player' ); ?></strong>
                </label>
                <input 
                    type="url" 
                    id="mbr_lrp_stream_url" 
                    name="mbr_lrp_stream_url" 
                    value="<?php echo esc_url( $stream_url ); ?>" 
                    class="widefat"
                    placeholder="https://example.com/stream.m3u8"
                />
                <span class="description">
                    <?php esc_html_e( 'Enter the live stream URL. Supports HLS (.m3u8), MP3, AAC, and other formats.', 'mbr-live-radio-player' ); ?>
                </span>
            </p>
        </div>
        <?php
    }
    
    /**
     * Render live preview meta box
     */
    public function render_live_preview( $post ) {
        ?>
        <div class="mbr-lrp-preview-wrapper">
            <div id="mbr-lrp-preview-container">
                <p class="mbr-lrp-preview-notice">
                    <?php esc_html_e( 'Enter a stream URL and station title to see the preview.', 'mbr-live-radio-player' ); ?>
                </p>
            </div>
            <div class="mbr-lrp-preview-controls">
                <label>
                    <input type="radio" name="mbr_lrp_preview_mode" value="desktop" checked />
                    <span class="dashicons dashicons-desktop"></span>
                    <?php esc_html_e( 'Desktop', 'mbr-live-radio-player' ); ?>
                </label>
                <label>
                    <input type="radio" name="mbr_lrp_preview_mode" value="mobile" />
                    <span class="dashicons dashicons-smartphone"></span>
                    <?php esc_html_e( 'Mobile', 'mbr-live-radio-player' ); ?>
                </label>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render appearance settings meta box
     */
    public function render_appearance_settings( $post ) {
        wp_nonce_field( 'mbr_lrp_appearance_meta', 'mbr_lrp_appearance_nonce' );
        
        // Get saved values
        $dark_mode = get_post_meta( $post->ID, '_mbr_lrp_dark_mode', true );
        $glassmorphism = get_post_meta( $post->ID, '_mbr_lrp_glassmorphism', true );
        $gradient_color_1 = get_post_meta( $post->ID, '_mbr_lrp_gradient_color_1', true );
        $gradient_color_2 = get_post_meta( $post->ID, '_mbr_lrp_gradient_color_2', true );
        
        // Set defaults
        if ( empty( $gradient_color_1 ) ) $gradient_color_1 = '#667eea';
        if ( empty( $gradient_color_2 ) ) $gradient_color_2 = '#764ba2';
        ?>
        
        <div class="mbr-appearance-settings">
            <p class="description"><?php esc_html_e( 'Customize how this player looks on your site.', 'mbr-live-radio-player' ); ?></p>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Dark Mode', 'mbr-live-radio-player' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="mbr_lrp_dark_mode" value="1" <?php checked( $dark_mode, '1' ); ?> />
                            <?php esc_html_e( 'Enable dark mode for this player', 'mbr-live-radio-player' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'Switches the player to a dark color scheme with darker backgrounds and lighter text.', 'mbr-live-radio-player' ); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php esc_html_e( 'Glassmorphism', 'mbr-live-radio-player' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="mbr_lrp_glassmorphism" value="1" <?php checked( $glassmorphism, '1' ); ?> />
                            <?php esc_html_e( 'Enable glassmorphism effect', 'mbr-live-radio-player' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'Applies a modern frosted glass effect with blur, transparency, and subtle borders.', 'mbr-live-radio-player' ); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php esc_html_e( 'Gradient Colors', 'mbr-live-radio-player' ); ?></th>
                    <td>
                        <div style="margin-bottom: 15px;">
                            <label style="display: inline-block; width: 120px;">
                                <?php esc_html_e( 'Start Color:', 'mbr-live-radio-player' ); ?>
                            </label>
                            <input 
                                type="text" 
                                name="mbr_lrp_gradient_color_1" 
                                value="<?php echo esc_attr( $gradient_color_1 ); ?>" 
                                class="mbr-color-picker"
                                data-default-color="#667eea"
                            />
                            <span class="description"><?php esc_html_e( '(Top-left)', 'mbr-live-radio-player' ); ?></span>
                        </div>
                        
                        <div style="margin-bottom: 15px;">
                            <label style="display: inline-block; width: 120px;">
                                <?php esc_html_e( 'End Color:', 'mbr-live-radio-player' ); ?>
                            </label>
                            <input 
                                type="text" 
                                name="mbr_lrp_gradient_color_2" 
                                value="<?php echo esc_attr( $gradient_color_2 ); ?>" 
                                class="mbr-color-picker"
                                data-default-color="#764ba2"
                            />
                            <span class="description"><?php esc_html_e( '(Bottom-right)', 'mbr-live-radio-player' ); ?></span>
                        </div>
                        
                        <p class="description">
                            <?php esc_html_e( 'Custom gradients only apply when Dark Mode and Glassmorphism are disabled.', 'mbr-live-radio-player' ); ?>
                        </p>
                        
                        <p style="margin-top: 10px;"><strong><?php esc_html_e( 'Preset Gradients:', 'mbr-live-radio-player' ); ?></strong></p>
                        <div class="mbr-gradient-presets" style="display: flex; gap: 10px; margin-top: 10px; flex-wrap: wrap;">
                            <button type="button" class="button mbr-preset-btn" data-color1="#667eea" data-color2="#764ba2" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none;">
                                <?php esc_html_e( 'Purple', 'mbr-live-radio-player' ); ?>
                            </button>
                            <button type="button" class="button mbr-preset-btn" data-color1="#1a1a2e" data-color2="#16213e" style="background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%); color: white; border: none;">
                                <?php esc_html_e( 'Dark Navy', 'mbr-live-radio-player' ); ?>
                            </button>
                            <button type="button" class="button mbr-preset-btn" data-color1="#f093fb" data-color2="#f5576c" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; border: none;">
                                <?php esc_html_e( 'Pink Sunset', 'mbr-live-radio-player' ); ?>
                            </button>
                            <button type="button" class="button mbr-preset-btn" data-color1="#4facfe" data-color2="#00f2fe" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); color: white; border: none;">
                                <?php esc_html_e( 'Ocean Blue', 'mbr-live-radio-player' ); ?>
                            </button>
                            <button type="button" class="button mbr-preset-btn" data-color1="#43e97b" data-color2="#38f9d7" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); color: white; border: none;">
                                <?php esc_html_e( 'Mint Green', 'mbr-live-radio-player' ); ?>
                            </button>
                            <button type="button" class="button mbr-preset-btn" data-color1="#fa709a" data-color2="#fee140" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); color: white; border: none;">
                                <?php esc_html_e( 'Warm Flame', 'mbr-live-radio-player' ); ?>
                            </button>
                            <button type="button" class="button mbr-preset-btn" data-color1="#30cfd0" data-color2="#330867" style="background: linear-gradient(135deg, #30cfd0 0%, #330867 100%); color: white; border: none;">
                                <?php esc_html_e( 'Cosmic', 'mbr-live-radio-player' ); ?>
                            </button>
                            <button type="button" class="button mbr-preset-btn" data-color1="#ff6e7f" data-color2="#bfe9ff" style="background: linear-gradient(135deg, #ff6e7f 0%, #bfe9ff 100%); color: white; border: none;">
                                <?php esc_html_e( 'Cotton Candy', 'mbr-live-radio-player' ); ?>
                            </button>
                        </div>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }
    
    /**
     * Render shortcode meta box
     */
    public function render_shortcode( $post ) {
        if ( $post->post_status === 'publish' ) {
            $shortcode = '[mbr_radio_player id="' . $post->ID . '"]';
            ?>
            <p><?php esc_html_e( 'Use this shortcode to display the player:', 'mbr-live-radio-player' ); ?></p>
            <input 
                type="text" 
                value="<?php echo esc_attr( $shortcode ); ?>" 
                readonly 
                class="widefat"
                onclick="this.select();"
            />
            <p class="description">
                <?php esc_html_e( 'Click to select and copy.', 'mbr-live-radio-player' ); ?>
            </p>
            <?php
        } else {
            ?>
            <p class="description">
                <?php esc_html_e( 'Publish this station to get the shortcode.', 'mbr-live-radio-player' ); ?>
            </p>
            <?php
        }
    }
    
    /**
     * Save meta box data
     */
    public function save_meta_boxes( $post_id, $post ) {
        // Verify nonce
        if ( ! isset( $_POST['mbr_lrp_meta_nonce'] ) || ! wp_verify_nonce( $_POST['mbr_lrp_meta_nonce'], 'mbr_lrp_save_meta' ) ) {
            return;
        }
        
        // Check autosave
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        
        // Check permissions
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        
        // Save stream URL
        if ( isset( $_POST['mbr_lrp_stream_url'] ) ) {
            $stream_url = esc_url_raw( wp_unslash( $_POST['mbr_lrp_stream_url'] ) );
            update_post_meta( $post_id, '_mbr_lrp_stream_url', $stream_url );
        }
        
        // Save appearance settings
        if ( isset( $_POST['mbr_lrp_appearance_nonce'] ) && wp_verify_nonce( $_POST['mbr_lrp_appearance_nonce'], 'mbr_lrp_appearance_meta' ) ) {
            // Dark mode
            $dark_mode = isset( $_POST['mbr_lrp_dark_mode'] ) ? '1' : '0';
            update_post_meta( $post_id, '_mbr_lrp_dark_mode', $dark_mode );
            
            // Glassmorphism
            $glassmorphism = isset( $_POST['mbr_lrp_glassmorphism'] ) ? '1' : '0';
            update_post_meta( $post_id, '_mbr_lrp_glassmorphism', $glassmorphism );
            
            // Gradient color 1
            if ( isset( $_POST['mbr_lrp_gradient_color_1'] ) ) {
                $color1 = sanitize_text_field( $_POST['mbr_lrp_gradient_color_1'] );
                if ( preg_match( '/^#[a-f0-9]{6}$/i', $color1 ) ) {
                    update_post_meta( $post_id, '_mbr_lrp_gradient_color_1', $color1 );
                }
            }
            
            // Gradient color 2
            if ( isset( $_POST['mbr_lrp_gradient_color_2'] ) ) {
                $color2 = sanitize_text_field( $_POST['mbr_lrp_gradient_color_2'] );
                if ( preg_match( '/^#[a-f0-9]{6}$/i', $color2 ) ) {
                    update_post_meta( $post_id, '_mbr_lrp_gradient_color_2', $color2 );
                }
            }
        }
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets( $hook ) {
        global $post_type;
        
        if ( ( 'post.php' === $hook || 'post-new.php' === $hook ) && 'mbr_radio_station' === $post_type ) {
            // Enqueue color picker
            wp_enqueue_style( 'wp-color-picker' );
            wp_enqueue_script( 'wp-color-picker' );
            
            // Player CSS (needed for preview)
            wp_enqueue_style(
                'mbr-lrp-player',
                MBR_LRP_PLUGIN_URL . 'assets/css/player.css',
                array(),
                MBR_LRP_VERSION
            );
            
            // Admin CSS
            wp_enqueue_style(
                'mbr-lrp-admin',
                MBR_LRP_PLUGIN_URL . 'assets/css/admin.css',
                array( 'wp-color-picker' ),
                MBR_LRP_VERSION
            );
            
            // Admin JS
            wp_enqueue_script(
                'mbr-lrp-admin',
                MBR_LRP_PLUGIN_URL . 'assets/js/admin.js',
                array( 'jquery', 'wp-color-picker' ),
                MBR_LRP_VERSION,
                true
            );
            
            // Get authentication token for secure proxy access
            $proxy_token = get_option( 'mbr_lrp_proxy_token', '' );
            if ( empty( $proxy_token ) ) {
                $proxy_token = wp_generate_password( 32, false, false );
                update_option( 'mbr_lrp_proxy_token', $proxy_token, false );
            }
            
            // Pass proxy URL to admin JavaScript - use AJAX endpoint with trailing & and include token
            wp_localize_script(
                'mbr-lrp-admin',
                'mbrLrpAdmin',
                array(
                    'proxyUrl' => admin_url( 'admin-ajax.php?action=mbr_proxy_stream&token=' . urlencode( $proxy_token ) . '&' ),
                    'proxyEnabled' => get_option( 'mbr_lrp_proxy_enabled', '1' ) === '1'
                )
            );
            
            // HLS.js for preview (bundled locally)
            wp_enqueue_script(
                'hls-js',
                MBR_LRP_PLUGIN_URL . 'assets/js/hls.min.js',
                array(),
                '1.4.12',
                true
            );
        }
    }
}
