<?php
/**
 * Custom Post Type for Radio Stations
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MBR_LRP_Post_Type {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'init', array( $this, 'register_post_type' ) );
    }
    
    /**
     * Register the radio station post type
     */
    public function register_post_type() {
        $labels = array(
            'name'                  => _x( 'Radio Stations', 'Post Type General Name', 'mbr-live-radio-player' ),
            'singular_name'         => _x( 'Radio Station', 'Post Type Singular Name', 'mbr-live-radio-player' ),
            'menu_name'             => __( 'Radio Stations', 'mbr-live-radio-player' ),
            'name_admin_bar'        => __( 'Radio Station', 'mbr-live-radio-player' ),
            'archives'              => __( 'Radio Station Archives', 'mbr-live-radio-player' ),
            'attributes'            => __( 'Radio Station Attributes', 'mbr-live-radio-player' ),
            'parent_item_colon'     => __( 'Parent Radio Station:', 'mbr-live-radio-player' ),
            'all_items'             => __( 'All Stations', 'mbr-live-radio-player' ),
            'add_new_item'          => __( 'Add New Station', 'mbr-live-radio-player' ),
            'add_new'               => __( 'Add New', 'mbr-live-radio-player' ),
            'new_item'              => __( 'New Radio Station', 'mbr-live-radio-player' ),
            'edit_item'             => __( 'Edit Radio Station', 'mbr-live-radio-player' ),
            'update_item'           => __( 'Update Radio Station', 'mbr-live-radio-player' ),
            'view_item'             => __( 'View Radio Station', 'mbr-live-radio-player' ),
            'view_items'            => __( 'View Radio Stations', 'mbr-live-radio-player' ),
            'search_items'          => __( 'Search Radio Station', 'mbr-live-radio-player' ),
            'not_found'             => __( 'Not found', 'mbr-live-radio-player' ),
            'not_found_in_trash'    => __( 'Not found in Trash', 'mbr-live-radio-player' ),
            'featured_image'        => __( 'Station Artwork', 'mbr-live-radio-player' ),
            'set_featured_image'    => __( 'Set station artwork', 'mbr-live-radio-player' ),
            'remove_featured_image' => __( 'Remove station artwork', 'mbr-live-radio-player' ),
            'use_featured_image'    => __( 'Use as station artwork', 'mbr-live-radio-player' ),
            'insert_into_item'      => __( 'Insert into radio station', 'mbr-live-radio-player' ),
            'uploaded_to_this_item' => __( 'Uploaded to this radio station', 'mbr-live-radio-player' ),
            'items_list'            => __( 'Radio Stations list', 'mbr-live-radio-player' ),
            'items_list_navigation' => __( 'Radio Stations list navigation', 'mbr-live-radio-player' ),
            'filter_items_list'     => __( 'Filter radio stations list', 'mbr-live-radio-player' ),
        );
        
        $args = array(
            'label'                 => __( 'Radio Station', 'mbr-live-radio-player' ),
            'description'           => __( 'Live radio stations', 'mbr-live-radio-player' ),
            'labels'                => $labels,
            'supports'              => array( 'title', 'thumbnail' ),
            'hierarchical'          => false,
            'public'                => false,
            'show_ui'               => true,
            'show_in_menu'          => true,
            'menu_position'         => 20,
            'menu_icon'             => 'dashicons-controls-play',
            'show_in_admin_bar'     => true,
            'show_in_nav_menus'     => false,
            'can_export'            => true,
            'has_archive'           => false,
            'exclude_from_search'   => true,
            'publicly_queryable'    => false,
            'capability_type'       => 'post',
            'show_in_rest'          => false,
        );
        
        register_post_type( 'mbr_radio_station', $args );
    }
}
