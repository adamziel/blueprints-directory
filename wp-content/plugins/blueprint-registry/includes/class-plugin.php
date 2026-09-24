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
		add_action( 'init', array( __CLASS__, 'maybe_move_files_to_private_storage' ), 100 );
		add_action( 'before_delete_post', array( __CLASS__, 'delete_private_files' ) );
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

	/**
	 * Moves bundle files off the Media Library and into private storage.
	 *
	 * Proposal files used to be attachments, which forced them through the
	 * site's upload allow-list and put arbitrary bundle contents in the Media
	 * Library. Installs that predate the change carry attachment-shaped records;
	 * this rewrites them once.
	 */
	public static function maybe_move_files_to_private_storage() {
		if ( get_option( 'blueprint_registry_storage_migrated' ) ) {
			return;
		}

		$moved = 0;
		$pairs = array(
			'blueprint_change'     => '_bp_change_files',
			'blueprint_submission' => '_bp_submission_files',
		);

		foreach ( $pairs as $post_type => $meta_key ) {
			$posts = get_posts(
				array(
					'post_type'      => $post_type,
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			);

			foreach ( $posts as $post_id ) {
				$files = get_post_meta( $post_id, $meta_key, true );
				if ( ! is_array( $files ) || ! $files ) {
					continue;
				}

				$migrated = array();
				foreach ( $files as $file ) {
					if ( ! empty( $file['key'] ) ) {
						$migrated[] = $file;
						continue;
					}

					$attachment_id = (int) ( $file['attachment_id'] ?? 0 );
					$disk          = $attachment_id ? get_attached_file( $attachment_id ) : '';
					if ( ! $disk || ! is_readable( $disk ) ) {
						continue;
					}

					$record = Blueprint_Registry_Storage::store_file( $post_id, $disk, $file['path'] ?? '', $migrated );
					if ( is_wp_error( $record ) ) {
						continue;
					}

					$migrated[] = $record;
					wp_delete_attachment( $attachment_id, true );
					$moved++;
				}

				update_post_meta( $post_id, $meta_key, $migrated );
			}
		}

		update_option( 'blueprint_registry_storage_migrated', time(), false );

		if ( $moved && defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::log( sprintf( 'Blueprint Registry: moved %d bundle files into private storage.', $moved ) );
		}
	}

	/**
	 * Removes a proposal's private files with the post that names them.
	 */
	public static function delete_private_files( $post_id ) {
		if ( ! in_array( get_post_type( $post_id ), array( 'blueprint_change', 'blueprint_submission' ), true ) ) {
			return;
		}

		Blueprint_Registry_Storage::delete_owner( $post_id );
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}
}
