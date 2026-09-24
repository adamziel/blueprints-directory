<?php

defined( 'ABSPATH' ) || exit;

final class Blueprint_Registry_Post_Types {
	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
	}

	public function register() {
		register_post_type(
			'blueprint',
			array(
				'labels'       => array(
					'name'          => __( 'Blueprints', 'blueprint-registry' ),
					'singular_name' => __( 'Blueprint', 'blueprint-registry' ),
				),
				'public'       => true,
				'has_archive'  => 'blueprints',
				'rewrite'      => array( 'slug' => 'blueprints', 'with_front' => false ),
				'show_in_rest' => true,
				'supports'     => array( 'title', 'editor', 'author', 'thumbnail', 'revisions' ),
				'menu_icon'    => 'dashicons-admin-site-alt3',
				)
		);

		register_post_type(
			'blueprint_change',
			array(
				'labels'              => array(
					'name'          => __( 'Blueprint changes', 'blueprint-registry' ),
					'singular_name' => __( 'Blueprint change', 'blueprint-registry' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => false,
				'show_in_rest'        => false,
				'capability_type'     => array( 'blueprint_change', 'blueprint_changes' ),
				'map_meta_cap'        => true,
				'supports'            => array( 'title', 'author', 'revisions' ),
				'exclude_from_search' => true,
				)
		);

		register_post_type(
			'blueprint_release',
			array(
				'labels'              => array(
					'name'          => __( 'Blueprint revisions', 'blueprint-registry' ),
					'singular_name' => __( 'Blueprint revision', 'blueprint-registry' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => false,
				'capability_type'     => array( 'blueprint_release', 'blueprint_releases' ),
				'map_meta_cap'        => true,
				'supports'            => array( 'title', 'author' ),
				'exclude_from_search' => true,
				)
		);

		register_taxonomy(
			'blueprint_category',
			array( 'blueprint' ),
			array(
				'label'        => __( 'Blueprint categories', 'blueprint-registry' ),
				'public'       => true,
				'show_in_rest' => true,
				'hierarchical' => false,
				'rewrite'      => array( 'slug' => 'blueprint-category' ),
			)
		);

		register_post_type(
			'blueprint_submission',
			array(
				'labels'              => array(
					'name'          => __( 'Blueprint submitted versions', 'blueprint-registry' ),
					'singular_name' => __( 'Blueprint submitted version', 'blueprint-registry' ),
				),
				'public'              => false,
				'show_ui'             => false,
				'capability_type'     => array( 'blueprint_submission', 'blueprint_submissions' ),
				'map_meta_cap'        => true,
				'supports'            => array( 'title', 'editor', 'author' ),
				'exclude_from_search' => true,
			)
		);

		foreach ( array( 'blueprint', 'blueprint_change', 'blueprint_release', 'blueprint_submission' ) as $post_type ) {
			register_post_meta( $post_type, '_bp_status', array( 'single' => true, 'show_in_rest' => false, 'type' => 'string', 'auth_callback' => '__return_true' ) );
		}
	}
}
