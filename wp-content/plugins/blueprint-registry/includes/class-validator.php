<?php

defined( 'ABSPATH' ) || exit;

final class Blueprint_Registry_Validator {
	const SCHEMA_URL = 'https://playground.wordpress.net/blueprint-schema.json';

	public static function validate_change( $change_id ) {
		$source = Blueprint_Registry_Workflow::source( $change_id );
		$files  = Blueprint_Registry_Bundles::get_change_files( $change_id );
		$errors = self::validate( $source, $files, $change_id );

		update_post_meta( $change_id, '_bp_validation_errors', $errors );
		update_post_meta( $change_id, '_bp_validated_at', time() );

		return $errors;
	}

	/**
	 * @param int $owner_id Post the files are stored against, so their presence
	 *                      on disk can be checked. Zero skips that check.
	 */
	public static function validate( $source, array $files, $owner_id = 0 ) {
		$errors = array();
		$data   = json_decode( $source, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return array( sprintf( __( 'The Blueprint JSON is invalid: %s', 'blueprint-registry' ), json_last_error_msg() ) );
		}

		if ( ! is_array( $data ) || array_is_list( $data ) ) {
			return array( __( 'A Blueprint must be a JSON object.', 'blueprint-registry' ) );
		}

		if ( empty( $data['$schema'] ) || self::SCHEMA_URL !== $data['$schema'] ) {
			$errors[] = sprintf( __( 'Set $schema to %s.', 'blueprint-registry' ), self::SCHEMA_URL );
		}

		if ( isset( $data['steps'] ) && ! is_array( $data['steps'] ) ) {
			$errors[] = __( 'The steps property must be an array.', 'blueprint-registry' );
		}

		if ( isset( $data['meta'] ) && ! is_array( $data['meta'] ) ) {
			$errors[] = __( 'The meta property must be an object.', 'blueprint-registry' );
		}

		if ( isset( $data['preferredVersions'] ) && ! is_array( $data['preferredVersions'] ) ) {
			$errors[] = __( 'The preferredVersions property must be an object.', 'blueprint-registry' );
		}

		$file_paths = array();
		foreach ( $files as $file ) {
			if ( empty( $file['path'] ) || empty( $file['key'] ) ) {
				$errors[] = __( 'Each bundle file needs a path and stored contents.', 'blueprint-registry' );
				continue;
			}

			$path = self::normalise_bundle_path( $file['path'] );
			if ( is_wp_error( $path ) ) {
				$errors[] = $path->get_error_message();
				continue;
			}

			if ( isset( $file_paths[ $path ] ) ) {
				$errors[] = sprintf( __( 'The bundle path %s is used more than once.', 'blueprint-registry' ), $path );
			}
			$file_paths[ $path ] = true;

			if ( $owner_id ) {
				$disk = Blueprint_Registry_Storage::file_path( $owner_id, $file['key'] );
				if ( is_wp_error( $disk ) || ! file_exists( $disk ) ) {
					$errors[] = sprintf( __( 'The file at %s is no longer available.', 'blueprint-registry' ), $path );
				}
			}
		}

		self::validate_resources( $data, $file_paths, $errors );

		return array_values( array_unique( $errors ) );
	}

	public static function normalise_bundle_path( $path ) {
		$path = str_replace( '\\', '/', trim( (string) $path ) );
		while ( str_starts_with( $path, './' ) ) {
			$path = substr( $path, 2 );
		}
		$path = ltrim( $path, '/' );

		if ( '' === $path || 'blueprint.json' === $path || str_contains( $path, "\0" ) ) {
			return new WP_Error( 'blueprint_invalid_path', __( 'A bundle path is empty or reserved.', 'blueprint-registry' ) );
		}

		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return new WP_Error( 'blueprint_invalid_path', sprintf( __( 'The bundle path %s is unsafe.', 'blueprint-registry' ), $path ) );
			}
		}

		return $path;
	}

	public static function canonical_json( $source ) {
		$data = json_decode( $source, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'blueprint_invalid_json', json_last_error_msg() );
		}

		return wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
	}

	private static function validate_resources( $value, array $file_paths, array &$errors ) {
		if ( ! is_array( $value ) ) {
			return;
		}

		if ( isset( $value['resource'] ) && 'bundled' === $value['resource'] ) {
			$path = isset( $value['path'] ) ? self::normalise_bundle_path( $value['path'] ) : new WP_Error( 'blueprint_missing_path', __( 'A bundled resource needs a path.', 'blueprint-registry' ) );
			if ( is_wp_error( $path ) ) {
				$errors[] = $path->get_error_message();
			} elseif ( ! isset( $file_paths[ $path ] ) ) {
				$errors[] = sprintf( __( 'Bundled resource %s was not uploaded.', 'blueprint-registry' ), $path );
			}
		}

		foreach ( $value as $child ) {
			self::validate_resources( $child, $file_paths, $errors );
		}
	}
}
