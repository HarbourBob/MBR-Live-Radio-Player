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
        
        // Live preview meta box — sits in the normal column, directly below Station Details
        add_meta_box(
            'mbr_lrp_live_preview',
            __( 'Player Preview', 'mbr-live-radio-player' ),
            array( $this, 'render_live_preview' ),
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
        
        // Shortcode meta box
        add_meta_box(
            'mbr_lrp_shortcode',
            __( 'Shortcode', 'mbr-live-radio-player' ),
            array( $this, 'render_shortcode' ),
            'mbr_radio_station',
            'side',
            'default'
        );
        
        // Popout Player Settings — below Player Appearance
        add_meta_box(
            'mbr_lrp_popout_settings',
            __( 'Popout Player Settings', 'mbr-live-radio-player' ),
            array( $this, 'render_popout_settings' ),
            'mbr_radio_station',
            'normal',
            'default'
        );
        
        // Station Group — multi-station selector
        add_meta_box(
            'mbr_lrp_station_group',
            __( 'Station Group (Multi-Station)', 'mbr-live-radio-player' ),
            array( $this, 'render_station_group' ),
            'mbr_radio_station',
            'normal',
            'default'
        );
    }
    
    /**
     * Render station details meta box
     */
    public function render_station_details( $post ) {
        wp_nonce_field( 'mbr_lrp_save_meta', 'mbr_lrp_meta_nonce' );
        
        $stream_url = get_post_meta( $post->ID, '_mbr_lrp_stream_url', true );
        $mode       = get_post_meta( $post->ID, '_mbr_lrp_mode', true );
        if ( empty( $mode ) ) $mode = 'stream';
        
        $tracks = get_post_meta( $post->ID, '_mbr_lrp_tracks', true );
        if ( ! is_array( $tracks ) ) $tracks = array();
        ?>
        <div class="mbr-lrp-meta-box">
        
            <!-- Mode toggle -->
            <p style="margin-bottom:16px;">
                <strong><?php esc_html_e( 'Player Mode', 'mbr-live-radio-player' ); ?></strong><br>
                <label style="margin-right:20px;">
                    <input type="radio" name="mbr_lrp_mode" value="stream" <?php checked( $mode, 'stream' ); ?> id="mbr_mode_stream" />
                    <?php esc_html_e( 'Live Stream', 'mbr-live-radio-player' ); ?>
                </label>
                <label>
                    <input type="radio" name="mbr_lrp_mode" value="files" <?php checked( $mode, 'files' ); ?> id="mbr_mode_files" />
                    <?php esc_html_e( 'File Player', 'mbr-live-radio-player' ); ?>
                </label>
            </p>
            
            <!-- Stream URL (visible in stream mode) -->
            <div id="mbr-stream-url-row" <?php echo $mode === 'files' ? 'style="display:none;"' : ''; ?>>
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
                        <?php esc_html_e( 'Live stream URL. Supports HLS (.m3u8), MP3, AAC, and Shoutcast/Icecast (.m3u).', 'mbr-live-radio-player' ); ?>
                    </span>
                </p>
            </div>
            
            <!-- Track list (visible in files mode) -->
            <div id="mbr-tracks-row" <?php echo $mode === 'stream' ? 'style="display:none;"' : ''; ?>>
                <p>
                    <strong><?php esc_html_e( 'Tracks', 'mbr-live-radio-player' ); ?></strong>
                    <span class="description" style="margin-left:8px;">
                        <?php esc_html_e( 'Add MP3, AAC, OGG or other audio files. Drag rows to reorder.', 'mbr-live-radio-player' ); ?>
                    </span>
                </p>
                <div id="mbr-tracks-list" style="margin-bottom:10px;">
                    <?php foreach ( $tracks as $i => $track ) :
                        $track_title = isset( $track['title'] ) ? $track['title'] : '';
                        $track_url   = isset( $track['url'] )   ? $track['url']   : '';
                    ?>
                    <div class="mbr-track-row" style="display:flex;align-items:center;gap:8px;margin-bottom:6px;background:#f9f9f9;border:1px solid #ddd;border-radius:4px;padding:8px;">
                        <span class="mbr-track-handle dashicons dashicons-menu" style="cursor:move;color:#999;flex-shrink:0;" title="Drag to reorder"></span>
                        <input type="text"
                               name="mbr_lrp_tracks[<?php echo esc_attr( $i ); ?>][title]"
                               value="<?php echo esc_attr( $track_title ); ?>"
                               placeholder="<?php esc_attr_e( 'Track title', 'mbr-live-radio-player' ); ?>"
                               style="flex:1;min-width:0;"
                               class="regular-text"
                        />
                        <input type="url"
                               name="mbr_lrp_tracks[<?php echo esc_attr( $i ); ?>][url]"
                               value="<?php echo esc_url( $track_url ); ?>"
                               placeholder="<?php esc_attr_e( 'File URL', 'mbr-live-radio-player' ); ?>"
                               style="flex:2;min-width:0;"
                               class="regular-text mbr-track-url"
                        />
                        <button type="button" class="button mbr-pick-file" style="flex-shrink:0;">
                            <?php esc_html_e( 'Choose', 'mbr-live-radio-player' ); ?>
                        </button>
                        <button type="button" class="button mbr-remove-track" style="flex-shrink:0;color:#a00;">
                            &times;
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="button" id="mbr-add-track">
                    + <?php esc_html_e( 'Add Track', 'mbr-live-radio-player' ); ?>
                </button>
            </div>
            
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
        $skin = get_post_meta( $post->ID, '_mbr_lrp_skin', true );
        if ( empty( $skin ) ) $skin = 'default';
        $dark_mode = get_post_meta( $post->ID, '_mbr_lrp_dark_mode', true );
        $glassmorphism = get_post_meta( $post->ID, '_mbr_lrp_glassmorphism', true );
        $gradient_color_1 = get_post_meta( $post->ID, '_mbr_lrp_gradient_color_1', true );
        $gradient_color_2 = get_post_meta( $post->ID, '_mbr_lrp_gradient_color_2', true );
        
        // Set defaults
        if ( empty( $gradient_color_1 ) ) $gradient_color_1 = '#667eea';
        if ( empty( $gradient_color_2 ) ) $gradient_color_2 = '#764ba2';
        
        $skins = array(
            'default'       => __( 'Default (Gradient)', 'mbr-live-radio-player' ),
            'classic'       => __( 'Classic (Vertical Card)', 'mbr-live-radio-player' ),
            'gradient-dark' => __( 'Dark Flat', 'mbr-live-radio-player' ),
            'minimal'       => __( 'Ghost Bar', 'mbr-live-radio-player' ),
            'retro'         => __( 'Retro Boombox', 'mbr-live-radio-player' ),
            'slim-bar'      => __( 'Slim Bar', 'mbr-live-radio-player' ),
        );
        ?>
        
        <div class="mbr-appearance-settings">
            <p class="description"><?php esc_html_e( 'Customize how this player looks on your site.', 'mbr-live-radio-player' ); ?></p>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Player Skin', 'mbr-live-radio-player' ); ?></th>
                    <td>
                        <select name="mbr_lrp_skin" id="mbr_lrp_skin">
                            <?php foreach ( $skins as $skin_key => $skin_label ) : ?>
                                <option value="<?php echo esc_attr( $skin_key ); ?>" <?php selected( $skin, $skin_key ); ?>>
                                    <?php echo esc_html( $skin_label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e( 'Choose the visual style for this player. Note: Dark Mode, Glassmorphism, and custom gradients only apply when skin is set to Default.', 'mbr-live-radio-player' ); ?>
                        </p>
                    </td>
                </tr>
            </table>
            
            <div id="mbr-default-skin-options"><?php /* greyed out by JS when non-default skin selected */ ?>
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
            </div><!-- /#mbr-default-skin-options -->
        </div>
        <?php
    }
    
    /**
     * Render shortcode meta box
     */
    public function render_shortcode( $post ) {
        if ( $post->post_status === 'publish' ) {
            $skin = get_post_meta( $post->ID, '_mbr_lrp_skin', true );
            if ( empty( $skin ) || $skin === 'default' ) {
                $shortcode = '[mbr_radio_player id="' . $post->ID . '"]';
            } else {
                $shortcode = '[mbr_radio_player id="' . $post->ID . '" skin="' . esc_attr( $skin ) . '"]';
            }
            ?>
            <p><?php esc_html_e( 'Use this shortcode to display the player:', 'mbr-live-radio-player' ); ?></p>
            <input 
                type="text" 
                id="mbr-lrp-shortcode-display"
                value="<?php echo esc_attr( $shortcode ); ?>" 
                readonly 
                class="widefat"
                onclick="this.select();"
            />
            <p class="description">
                <?php esc_html_e( 'Click to select and copy. Updates automatically when you change the skin.', 'mbr-live-radio-player' ); ?>
            </p>
            <input type="hidden" id="mbr-lrp-post-id" value="<?php echo esc_attr( $post->ID ); ?>" />
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
     * Render popout player settings meta box
     */
    public function render_popout_settings( $post ) {
        wp_nonce_field( 'mbr_lrp_popout_meta', 'mbr_lrp_popout_nonce' );
        
        $popout_skin = get_post_meta( $post->ID, '_mbr_lrp_popout_skin', true );
        if ( empty( $popout_skin ) ) $popout_skin = 'default';
        
        $main_skin = get_post_meta( $post->ID, '_mbr_lrp_skin', true );
        if ( empty( $main_skin ) ) $main_skin = 'default';
        
        $skins = array(
            'default'       => __( 'Default (Gradient)', 'mbr-live-radio-player' ),
            'classic'       => __( 'Classic (Vertical Card)', 'mbr-live-radio-player' ),
            'gradient-dark' => __( 'Dark Flat', 'mbr-live-radio-player' ),
            'minimal'       => __( 'Ghost Bar', 'mbr-live-radio-player' ),
            'retro'         => __( 'Retro Boombox', 'mbr-live-radio-player' ),
            'slim-bar'      => __( 'Slim Bar', 'mbr-live-radio-player' ),
        );
        
        $disabled_class = ( $main_skin === 'default' ) ? ' mbr-options-disabled' : '';
        ?>
        <div id="mbr-popout-settings-wrapper" class="<?php echo esc_attr( ltrim( $disabled_class ) ); ?>">
            <p class="description">
                <?php esc_html_e( 'Choose the skin for the popout (pop-out) player window. Only available when a non-default skin is selected above.', 'mbr-live-radio-player' ); ?>
            </p>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Popout Skin', 'mbr-live-radio-player' ); ?></th>
                    <td>
                        <select name="mbr_lrp_popout_skin">
                            <?php foreach ( $skins as $skin_key => $skin_label ) : ?>
                                <option value="<?php echo esc_attr( $skin_key ); ?>" <?php selected( $popout_skin, $skin_key ); ?>>
                                    <?php echo esc_html( $skin_label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">
                            <?php esc_html_e( 'The popout window will render this skin. The popout button is hidden when the Default skin is selected on the main player.', 'mbr-live-radio-player' ); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }
    
    /**
     * Render station group meta box
     */
    public function render_station_group( $post ) {
        wp_nonce_field( 'mbr_lrp_group_meta', 'mbr_lrp_group_nonce' );
        
        $saved_group = get_post_meta( $post->ID, '_mbr_lrp_station_group', true );
        if ( ! is_array( $saved_group ) ) $saved_group = array();
        
        // Get all other published stations
        $all_stations = get_posts( array(
            'post_type'      => 'mbr_radio_station',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'exclude'        => array( $post->ID ),
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );
        ?>
        <div class="mbr-station-group-wrapper">
            <p class="description">
                <?php esc_html_e( 'Tick the stations you want to appear in this player\'s station list. A "Stations" button will appear on the player when at least one station is selected.', 'mbr-live-radio-player' ); ?>
            </p>
            <?php if ( empty( $all_stations ) ) : ?>
                <p class="description" style="margin-top:10px;color:#888;">
                    <?php esc_html_e( 'No other published stations found. Create more stations to build a group.', 'mbr-live-radio-player' ); ?>
                </p>
            <?php else : ?>
                <div class="mbr-station-checklist" style="margin-top:12px;max-height:240px;overflow-y:auto;border:1px solid #ddd;border-radius:4px;padding:8px 12px;">
                    <?php foreach ( $all_stations as $station ) :
                        $checked = in_array( $station->ID, array_map( 'intval', $saved_group ), true );
                        $thumb   = get_the_post_thumbnail_url( $station->ID, 'thumbnail' );
                    ?>
                    <label style="display:flex;align-items:center;gap:10px;padding:6px 0;border-bottom:1px solid #f0f0f0;cursor:pointer;">
                        <input type="checkbox"
                               name="mbr_lrp_station_group[]"
                               value="<?php echo esc_attr( $station->ID ); ?>"
                               <?php checked( $checked ); ?>
                               style="flex-shrink:0;"
                        />
                        <?php if ( $thumb ) : ?>
                            <img src="<?php echo esc_url( $thumb ); ?>" width="36" height="36" style="border-radius:4px;object-fit:cover;flex-shrink:0;" alt="" />
                        <?php else : ?>
                            <span style="width:36px;height:36px;background:#ddd;border-radius:4px;flex-shrink:0;display:inline-block;"></span>
                        <?php endif; ?>
                        <span><?php echo esc_html( get_the_title( $station->ID ) ); ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <p class="description" style="margin-top:8px;">
                    <?php printf(
                        esc_html__( '%d other station(s) available.', 'mbr-live-radio-player' ),
                        count( $all_stations )
                    ); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
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
        
        // Save player mode
        $mode = isset( $_POST['mbr_lrp_mode'] ) && $_POST['mbr_lrp_mode'] === 'files' ? 'files' : 'stream';
        update_post_meta( $post_id, '_mbr_lrp_mode', $mode );
        
        // Save tracks (files mode)
        if ( isset( $_POST['mbr_lrp_tracks'] ) && is_array( $_POST['mbr_lrp_tracks'] ) ) {
            $raw_tracks   = array_values( wp_unslash( $_POST['mbr_lrp_tracks'] ) );
            $clean_tracks = array();
            foreach ( $raw_tracks as $track ) {
                $url   = isset( $track['url'] )   ? esc_url_raw( $track['url'] )           : '';
                $title = isset( $track['title'] ) ? sanitize_text_field( $track['title'] ) : '';
                if ( ! empty( $url ) ) {
                    $clean_tracks[] = array( 'title' => $title, 'url' => $url );
                }
            }
            update_post_meta( $post_id, '_mbr_lrp_tracks', $clean_tracks );
        } else {
            update_post_meta( $post_id, '_mbr_lrp_tracks', array() );
        }
        
        // Save appearance settings
        if ( isset( $_POST['mbr_lrp_appearance_nonce'] ) && wp_verify_nonce( $_POST['mbr_lrp_appearance_nonce'], 'mbr_lrp_appearance_meta' ) ) {
            // Skin
            $allowed_skins = array( 'default', 'classic', 'gradient-dark', 'minimal', 'retro', 'slim-bar' );
            $skin = isset( $_POST['mbr_lrp_skin'] ) ? sanitize_text_field( $_POST['mbr_lrp_skin'] ) : 'default';
            if ( ! in_array( $skin, $allowed_skins, true ) ) {
                $skin = 'default';
            }
            update_post_meta( $post_id, '_mbr_lrp_skin', $skin );
            
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
        
        // Save popout skin
        if ( isset( $_POST['mbr_lrp_popout_nonce'] ) && wp_verify_nonce( $_POST['mbr_lrp_popout_nonce'], 'mbr_lrp_popout_meta' ) ) {
            $allowed_skins = array( 'default', 'classic', 'gradient-dark', 'minimal', 'retro', 'slim-bar' );
            $popout_skin   = isset( $_POST['mbr_lrp_popout_skin'] ) ? sanitize_text_field( $_POST['mbr_lrp_popout_skin'] ) : 'default';
            if ( ! in_array( $popout_skin, $allowed_skins, true ) ) {
                $popout_skin = 'default';
            }
            update_post_meta( $post_id, '_mbr_lrp_popout_skin', $popout_skin );
        }
        
        // Save station group
        if ( isset( $_POST['mbr_lrp_group_nonce'] ) && wp_verify_nonce( $_POST['mbr_lrp_group_nonce'], 'mbr_lrp_group_meta' ) ) {
            $raw_group = isset( $_POST['mbr_lrp_station_group'] ) ? (array) $_POST['mbr_lrp_station_group'] : array();
            $clean_group = array_map( 'absint', $raw_group );
            $clean_group = array_filter( $clean_group ); // remove zeros
            // Validate each ID is a published mbr_radio_station
            $valid_group = array();
            foreach ( $clean_group as $sid ) {
                $sp = get_post( $sid );
                if ( $sp && 'mbr_radio_station' === $sp->post_type && 'publish' === $sp->post_status ) {
                    $valid_group[] = $sid;
                }
            }
            update_post_meta( $post_id, '_mbr_lrp_station_group', $valid_group );
        }
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets( $hook ) {
        global $post_type;
        
        if ( ( 'post.php' === $hook || 'post-new.php' === $hook ) && 'mbr_radio_station' === $post_type ) {
            // WordPress media library (needed for file picker)
            wp_enqueue_media();
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
                array( 'jquery', 'wp-color-picker', 'jquery-ui-sortable' ),
                MBR_LRP_VERSION,
                true
            );
            
            // Get authentication token for secure proxy access
            $proxy_token = get_option( 'mbr_lrp_proxy_token', '' );
            if ( empty( $proxy_token ) ) {
                $proxy_token = wp_generate_password( 32, false, false );
                update_option( 'mbr_lrp_proxy_token', $proxy_token, false );
            }
            
            // Pass proxy URL and ajax URL to admin JavaScript
            wp_localize_script(
                'mbr-lrp-admin',
                'mbrLrpAdmin',
                array(
                    'proxyUrl'     => admin_url( 'admin-ajax.php?action=mbr_proxy_stream&token=' . urlencode( $proxy_token ) . '&' ),
                    'proxyEnabled' => get_option( 'mbr_lrp_proxy_enabled', '1' ) === '1',
                    'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
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
