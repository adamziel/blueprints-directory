<?php
/**
 * Plugin Name: Blueprint Registry
 * Description: Reviewable WordPress Playground Blueprints with immutable published releases.
 * Version: 0.3.12
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 * Text Domain: blueprint-registry
 */

defined( 'ABSPATH' ) || exit;

define( 'BLUEPRINT_REGISTRY_FILE', __FILE__ );
define( 'BLUEPRINT_REGISTRY_DIR', plugin_dir_path( __FILE__ ) );
define( 'BLUEPRINT_REGISTRY_URL', plugin_dir_url( __FILE__ ) );
define( 'BLUEPRINT_REGISTRY_VERSION', '0.3.12' );

spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'Blueprint_Registry_' ) ) {
			return;
		}

		$file = BLUEPRINT_REGISTRY_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', substr( $class, 19 ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

function blueprint_registry() {
	return Blueprint_Registry_Plugin::instance();
}

register_activation_hook( __FILE__, array( 'Blueprint_Registry_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Blueprint_Registry_Plugin', 'deactivate' ) );

blueprint_registry();
