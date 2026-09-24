<?php
/**
 * Central asset registration so every screen loads the same tokens first.
 *
 * @package BlueprintRegistry
 */

defined( 'ABSPATH' ) || exit;

final class Blueprint_Registry_Assets {
	public static function enqueue_front() {
		self::register();
		wp_enqueue_style( 'blueprint-registry' );
		wp_enqueue_script( 'blueprint-registry' );
	}

	public static function enqueue_workspace() {
		self::register();
		wp_enqueue_style( 'blueprint-registry-workspace' );
		wp_enqueue_script( 'blueprint-registry' );
	}

	public static function enqueue_admin() {
		self::register();
		wp_enqueue_style( 'blueprint-registry-admin' );
		wp_enqueue_script( 'blueprint-registry' );
	}

	/**
	 * Adds the core code editor to the Blueprint JSON textarea.
	 */
	public static function enqueue_code_editor() {
		$settings = wp_enqueue_code_editor(
			array(
				'type'       => 'application/json',
				'codemirror' => array(
					'lineNumbers'    => true,
					'lineWrapping'   => true,
					'indentUnit'     => 2,
					'tabSize'        => 2,
					'styleActiveLine' => true,
				),
			)
		);

		if ( false === $settings ) {
			return;
		}

		wp_add_inline_script(
			'blueprint-registry',
			'window.BlueprintRegistryEditor = ' . wp_json_encode( $settings ) . ';',
			'before'
		);
	}

	/**
	 * Versions an asset by its own modification time.
	 *
	 * The plugin version alone is not enough: editing a stylesheet without
	 * releasing leaves every browser that already fetched it on the old copy,
	 * which is invisible to anyone testing in a fresh profile.
	 */
	private static function version( $relative_path ) {
		$modified = @filemtime( BLUEPRINT_REGISTRY_DIR . $relative_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return $modified ? BLUEPRINT_REGISTRY_VERSION . '.' . $modified : BLUEPRINT_REGISTRY_VERSION;
	}

	private static function register() {
		if ( wp_style_is( 'blueprint-registry-tokens', 'registered' ) ) {
			return;
		}

		// WordPress.org self-hosts Inter and IBM Plex Mono; a production deploy
		// should do the same rather than depend on a third party.
		wp_register_style(
			'blueprint-registry-fonts',
			'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;600&display=swap',
			array(),
			null
		);
		wp_register_style( 'blueprint-registry-tokens', BLUEPRINT_REGISTRY_URL . 'assets/tokens.css', array( 'blueprint-registry-fonts' ), self::version( 'assets/tokens.css' ) );
		wp_register_style( 'blueprint-registry-dataviews', BLUEPRINT_REGISTRY_URL . 'assets/dataviews.css', array( 'blueprint-registry-tokens' ), self::version( 'assets/dataviews.css' ) );
		wp_register_style( 'blueprint-registry', BLUEPRINT_REGISTRY_URL . 'assets/registry.css', array( 'blueprint-registry-dataviews' ), self::version( 'assets/registry.css' ) );
		wp_register_style( 'blueprint-registry-workspace', BLUEPRINT_REGISTRY_URL . 'assets/workspace.css', array( 'blueprint-registry' ), self::version( 'assets/workspace.css' ) );
		wp_register_style( 'blueprint-registry-admin', BLUEPRINT_REGISTRY_URL . 'assets/admin.css', array( 'blueprint-registry-workspace' ), self::version( 'assets/admin.css' ) );

		wp_register_script( 'blueprint-registry', BLUEPRINT_REGISTRY_URL . 'assets/registry.js', array( 'jquery' ), self::version( 'assets/registry.js' ), true );
	}
}
