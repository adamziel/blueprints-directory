<?php

defined( 'ABSPATH' ) || exit;

final class Blueprint_Registry_Plugin {
	private static $instance;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		new Blueprint_Registry_Post_Types();
		new Blueprint_Registry_Admin();
		new Blueprint_Registry_Frontend();
		new Blueprint_Registry_Routes();
		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrite_rules' ), 99 );
	}

	public static function activate() {
		Blueprint_Registry_Capabilities::register();
		$post_types = new Blueprint_Registry_Post_Types();
		$post_types->register();
		Blueprint_Registry_Frontend::register_rewrite_rules();
		Blueprint_Registry_Routes::register_rewrite_rules();
		flush_rewrite_rules();
		update_option( 'blueprint_registry_rewrite_version', BLUEPRINT_REGISTRY_VERSION );
	}

	public static function maybe_flush_rewrite_rules() {
		if ( BLUEPRINT_REGISTRY_VERSION === get_option( 'blueprint_registry_rewrite_version' ) ) {
			return;
		}

		Blueprint_Registry_Frontend::register_rewrite_rules();
		Blueprint_Registry_Routes::register_rewrite_rules();
		flush_rewrite_rules( false );
		update_option( 'blueprint_registry_rewrite_version', BLUEPRINT_REGISTRY_VERSION );
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}
}
