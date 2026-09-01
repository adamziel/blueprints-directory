<?php

defined( 'ABSPATH' ) || exit;

final class Blueprint_Registry_Bundles {
	public static function get_change_files( $change_id ) {
		$files = get_post_meta( $change_id, '_bp_change_files', true );
		return is_array( $files ) ? array_values( $files ) : array();
	}

	/**
	 * Gets the private attachment copies made when a proposal is submitted.
	 */
	public static function get_submission_files( $submission_id ) {
		$files = get_post_meta( $submission_id, '_bp_submission_files', true );
		return is_array( $files ) ? array_values( $files ) : array();
	}

	/**
	 * Copies the contributor's current bundle into the submitted copy. This keeps
	 * a reviewer and a later release independent from any further draft edits.
	 */
	public static function copy_change_files_to_submission( $change_id, $submission_id ) {
		$files       = array();
		$attachments = array();
		foreach ( self::get_change_files( $change_id ) as $file ) {
			$path = Blueprint_Registry_Validator::normalise_bundle_path( $file['path'] ?? '' );
			$disk = get_attached_file( (int) ( $file['attachment_id'] ?? 0 ) );
			if ( is_wp_error( $path ) || ! is_readable( $disk ) ) {
				foreach ( $attachments as $attachment_id ) {
					wp_delete_attachment( $attachment_id, true );
				}
				return is_wp_error( $path ) ? $path : new WP_Error( 'blueprint_submission_file_unavailable', __( 'A bundle file could not be copied for review.', 'blueprint-registry' ) );
			}

			$uploaded = wp_upload_bits( basename( $path ), null, file_get_contents( $disk ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( ! empty( $uploaded['error'] ) ) {
				foreach ( $attachments as $attachment_id ) {
					wp_delete_attachment( $attachment_id, true );
				}
				return new WP_Error( 'blueprint_upload_failed', $uploaded['error'] );
			}

			$attachment_id = self::attach_uploaded_file( $uploaded['file'], $submission_id );
			if ( is_wp_error( $attachment_id ) ) {
				foreach ( $attachments as $created_attachment_id ) {
					wp_delete_attachment( $created_attachment_id, true );
				}
				return $attachment_id;
			}

			$attachments[] = $attachment_id;
			$files[]       = array(
				'attachment_id' => $attachment_id,
				'path'          => $path,
			);
		}

		update_post_meta( $submission_id, '_bp_submission_files', $files );
		return $files;
	}

	/**
	 * Replaces a follow-up draft's starting files with the later work that was
	 * still in the contributor's editor when an earlier submitted copy was accepted.
	 */
	public static function replace_change_files_from_change( $source_change_id, $target_change_id ) {
		foreach ( self::get_change_files( $target_change_id ) as $file ) {
			wp_delete_attachment( (int) $file['attachment_id'], true );
		}
		update_post_meta( $target_change_id, '_bp_change_files', array() );

		$attachments = array();
		foreach ( self::get_change_files( $source_change_id ) as $file ) {
			$path = Blueprint_Registry_Validator::normalise_bundle_path( $file['path'] ?? '' );
			$disk = get_attached_file( (int) ( $file['attachment_id'] ?? 0 ) );
			if ( is_wp_error( $path ) || ! is_readable( $disk ) ) {
				foreach ( $attachments as $attachment_id ) {
					wp_delete_attachment( $attachment_id, true );
				}
				return is_wp_error( $path ) ? $path : new WP_Error( 'blueprint_follow_up_file_unavailable', __( 'A later draft file could not be copied.', 'blueprint-registry' ) );
			}

			$uploaded = wp_upload_bits( basename( $path ), null, file_get_contents( $disk ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( ! empty( $uploaded['error'] ) ) {
				foreach ( $attachments as $attachment_id ) {
					wp_delete_attachment( $attachment_id, true );
				}
				return new WP_Error( 'blueprint_upload_failed', $uploaded['error'] );
			}
			$attachment_id = self::attach_uploaded_file( $uploaded['file'], $target_change_id );
			if ( is_wp_error( $attachment_id ) ) {
				foreach ( $attachments as $created_attachment_id ) {
					wp_delete_attachment( $created_attachment_id, true );
				}
				return $attachment_id;
			}
			$attachments[] = $attachment_id;
			self::add_change_file( $target_change_id, $attachment_id, $path );
		}

		return true;
	}

	public static function add_change_file( $change_id, $attachment_id, $path ) {
		$path = Blueprint_Registry_Validator::normalise_bundle_path( $path );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$files   = self::get_change_files( $change_id );
		$files[] = array(
			'attachment_id' => (int) $attachment_id,
			'path'          => $path,
		);
		update_post_meta( $change_id, '_bp_change_files', $files );

		return true;
	}

	public static function remove_change_file( $change_id, $attachment_id ) {
		$files = array_filter(
			self::get_change_files( $change_id ),
			static function ( $file ) use ( $attachment_id ) {
				return (int) $file['attachment_id'] !== (int) $attachment_id;
			}
		);
		update_post_meta( $change_id, '_bp_change_files', array_values( $files ) );
	}

	public static function build_release_bundle( $release_id, $source, array $files ) {
		$path = self::build_zip( $source, $files, 'blueprint-release-' . $release_id . '.zip' );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$attachment_id = self::attach_file( $path, basename( $path ), $release_id, 'application/zip' );
		@unlink( $path );

		return $attachment_id;
	}

	public static function build_preview( $change_id ) {
		$errors = Blueprint_Registry_Validator::validate_change( $change_id );
		if ( $errors ) {
			return new WP_Error( 'blueprint_invalid_change', implode( ' ', $errors ) );
		}

		$source = Blueprint_Registry_Validator::canonical_json( Blueprint_Registry_Workflow::source( $change_id ) );
		$path   = self::build_zip( $source, self::get_change_files( $change_id ), 'blueprint-preview-' . $change_id . '.zip', true );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$token = wp_generate_password( 48, false, false );
		update_post_meta( $change_id, '_bp_preview_path', $path );
		update_post_meta( $change_id, '_bp_preview_token', wp_hash( $token ) );
		update_post_meta( $change_id, '_bp_preview_expires', time() + ( 15 * MINUTE_IN_SECONDS ) );

		return $token;
	}

	/**
	 * Builds a short-lived preview from the same immutable copy shown to a reviewer.
	 */
	public static function build_submission_preview( $submission_id ) {
		$source = (string) get_post_field( 'post_content', $submission_id );
		$errors = Blueprint_Registry_Validator::validate( $source, self::get_submission_files( $submission_id ) );
		if ( $errors ) {
			return new WP_Error( 'blueprint_invalid_submission', implode( ' ', $errors ) );
		}

		$path = self::build_zip( $source, self::get_submission_files( $submission_id ), 'blueprint-preview-' . $submission_id . '.zip', true );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$token = wp_generate_password( 48, false, false );
		update_post_meta( $submission_id, '_bp_preview_path', $path );
		update_post_meta( $submission_id, '_bp_preview_token', wp_hash( $token ) );
		update_post_meta( $submission_id, '_bp_preview_expires', time() + ( 15 * MINUTE_IN_SECONDS ) );

		return $token;
	}

	public static function get_preview_path( $change_id, $token ) {
		$expires = (int) get_post_meta( $change_id, '_bp_preview_expires', true );
		$valid   = hash_equals( (string) get_post_meta( $change_id, '_bp_preview_token', true ), wp_hash( $token ) );
		$path    = (string) get_post_meta( $change_id, '_bp_preview_path', true );

		if ( $expires < time() || ! $valid || ! is_readable( $path ) ) {
			return new WP_Error( 'blueprint_preview_expired', __( 'This draft preview has expired.', 'blueprint-registry' ) );
		}

		return $path;
	}

	public static function release_manifest( $source, array $files ) {
		$manifest = array(
			'blueprint.json' => hash( 'sha256', $source ),
		);

		foreach ( $files as $file ) {
			$path = Blueprint_Registry_Validator::normalise_bundle_path( $file['path'] );
			$disk = get_attached_file( (int) $file['attachment_id'] );
			if ( is_wp_error( $path ) || ! is_readable( $disk ) ) {
				continue;
			}
			$manifest[ $path ] = hash_file( 'sha256', $disk );
		}

		ksort( $manifest );
		return $manifest;
	}

	public static function copy_release_to_change( $release_id, $change_id ) {
		$bundle_id = (int) get_post_meta( $release_id, '_bp_bundle_attachment_id', true );
		$bundle    = get_attached_file( $bundle_id );
		if ( ! class_exists( 'ZipArchive' ) || ! is_readable( $bundle ) ) {
			return new WP_Error( 'blueprint_bundle_unavailable', __( 'The release bundle is unavailable.', 'blueprint-registry' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $bundle ) ) {
			return new WP_Error( 'blueprint_bundle_unreadable', __( 'The release bundle could not be opened.', 'blueprint-registry' ) );
		}

		for ( $index = 0; $index < $zip->numFiles; $index++ ) {
			$name = $zip->getNameIndex( $index );
			if ( 'blueprint.json' === $name || str_ends_with( $name, '/' ) ) {
				continue;
			}

			$path = Blueprint_Registry_Validator::normalise_bundle_path( $name );
			if ( is_wp_error( $path ) ) {
				$zip->close();
				return $path;
			}

			$contents = $zip->getFromIndex( $index );
			if ( false === $contents ) {
				$zip->close();
				return new WP_Error( 'blueprint_bundle_unreadable', __( 'A release file could not be copied.', 'blueprint-registry' ) );
			}

			$uploaded = wp_upload_bits( basename( $path ), null, $contents );
			if ( ! empty( $uploaded['error'] ) ) {
				$zip->close();
				return new WP_Error( 'blueprint_upload_failed', $uploaded['error'] );
			}

			$attachment_id = self::attach_uploaded_file( $uploaded['file'], $change_id );
			if ( is_wp_error( $attachment_id ) ) {
				$zip->close();
				return $attachment_id;
			}
			self::add_change_file( $change_id, $attachment_id, $path );
		}

		$zip->close();
		return true;
	}

	/**
	 * Returns the immutable files in a published release. The declaration is
	 * included even though it is stored as post content rather than an attachment.
	 */
	public static function release_file_entries( $release_id ) {
		$release = get_post( $release_id );
		if ( ! $release || 'blueprint_release' !== $release->post_type ) {
			return new WP_Error( 'blueprint_missing_release', __( 'The Blueprint release does not exist.', 'blueprint-registry' ) );
		}

		$manifest = self::stored_release_manifest( $release_id );
		$entries  = array(
			array(
				'path'     => 'blueprint.json',
				'size'     => strlen( (string) $release->post_content ),
				'checksum' => $manifest['blueprint.json'] ?? hash( 'sha256', (string) $release->post_content ),
			),
		);
		$bundle   = get_attached_file( (int) get_post_meta( $release_id, '_bp_bundle_attachment_id', true ) );
		if ( ! class_exists( 'ZipArchive' ) || ! is_readable( $bundle ) ) {
			return $entries;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $bundle ) ) {
			return new WP_Error( 'blueprint_bundle_unreadable', __( 'The release bundle could not be opened.', 'blueprint-registry' ) );
		}

		for ( $index = 0; $index < $zip->numFiles; $index++ ) {
			$name = $zip->getNameIndex( $index );
			if ( 'blueprint.json' === $name || str_ends_with( $name, '/' ) ) {
				continue;
			}
			$path = Blueprint_Registry_Validator::normalise_bundle_path( $name );
			$stat = $zip->statIndex( $index );
			if ( is_wp_error( $path ) || ! is_array( $stat ) ) {
				continue;
			}
			$entries[] = array(
				'path'     => $path,
				'size'     => (int) $stat['size'],
				'checksum' => $manifest[ $path ] ?? '',
			);
		}
		$zip->close();

		usort(
			$entries,
			static function ( $left, $right ) {
				return strnatcasecmp( $left['path'], $right['path'] );
			}
		);

		return $entries;
	}

	/**
	 * Reads a release resource for the public file browser and for text diffs.
	 */
	public static function release_file_contents( $release_id, $path, $maximum_size = 524288 ) {
		if ( 'blueprint.json' === $path ) {
			$source = (string) get_post_field( 'post_content', $release_id );
			if ( strlen( $source ) > $maximum_size ) {
				return new WP_Error( 'blueprint_file_too_large', __( 'This file is too large to show in the browser.', 'blueprint-registry' ) );
			}
			return $source;
		}

		$path = Blueprint_Registry_Validator::normalise_bundle_path( $path );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		$bundle = get_attached_file( (int) get_post_meta( $release_id, '_bp_bundle_attachment_id', true ) );
		if ( ! class_exists( 'ZipArchive' ) || ! is_readable( $bundle ) ) {
			return new WP_Error( 'blueprint_bundle_unavailable', __( 'The release bundle is unavailable.', 'blueprint-registry' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $bundle ) ) {
			return new WP_Error( 'blueprint_bundle_unreadable', __( 'The release bundle could not be opened.', 'blueprint-registry' ) );
		}
		$stat = $zip->statName( $path );
		if ( ! is_array( $stat ) ) {
			$zip->close();
			return new WP_Error( 'blueprint_file_missing', __( 'The release file does not exist.', 'blueprint-registry' ) );
		}
		if ( (int) $stat['size'] > $maximum_size ) {
			$zip->close();
			return new WP_Error( 'blueprint_file_too_large', __( 'This file is too large to show in the browser.', 'blueprint-registry' ) );
		}
		$contents = $zip->getFromName( $path );
		$zip->close();

		return false === $contents ? new WP_Error( 'blueprint_bundle_unreadable', __( 'The release file could not be read.', 'blueprint-registry' ) ) : $contents;
	}

	/**
	 * Sends one bundled resource as a download without extracting it to a public path.
	 */
	public static function stream_release_file( $release_id, $path ) {
		$path = Blueprint_Registry_Validator::normalise_bundle_path( $path );
		if ( is_wp_error( $path ) ) {
			wp_die( esc_html( $path->get_error_message() ), esc_html__( 'Not found', 'blueprint-registry' ), array( 'response' => 404 ) );
		}
		$bundle = get_attached_file( (int) get_post_meta( $release_id, '_bp_bundle_attachment_id', true ) );
		if ( ! class_exists( 'ZipArchive' ) || ! is_readable( $bundle ) ) {
			wp_die( esc_html__( 'The release bundle is unavailable.', 'blueprint-registry' ), esc_html__( 'Not found', 'blueprint-registry' ), array( 'response' => 404 ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $bundle ) || ! ( $stream = $zip->getStream( $path ) ) ) {
			wp_die( esc_html__( 'The release file does not exist.', 'blueprint-registry' ), esc_html__( 'Not found', 'blueprint-registry' ), array( 'response' => 404 ) );
		}
		$stat = $zip->statName( $path );
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		if ( is_array( $stat ) ) {
			header( 'Content-Length: ' . (int) $stat['size'] );
		}
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( basename( $path ) ) . '"' );
		header( 'Cache-Control: public, max-age=31536000, immutable' );
		while ( ! feof( $stream ) ) {
			echo fread( $stream, 8192 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		fclose( $stream );
		$zip->close();
		exit;
	}

	/**
	 * Compares a contributor's proposal with the immutable release it started from.
	 */
	public static function diff_release_to_change( $release_id, $change_id ) {
		$base_manifest     = self::stored_release_manifest( $release_id );
		$proposal_manifest = self::change_manifest( $change_id );
		$proposal_files    = array();
		foreach ( self::get_change_files( $change_id ) as $file ) {
			$path = Blueprint_Registry_Validator::normalise_bundle_path( $file['path'] ?? '' );
			if ( ! is_wp_error( $path ) ) {
				$proposal_files[ $path ] = (int) $file['attachment_id'];
			}
		}

		$paths = array_unique( array_merge( array_keys( $base_manifest ), array_keys( $proposal_manifest ) ) );
		sort( $paths, SORT_NATURAL | SORT_FLAG_CASE );
		$diff = array();
		foreach ( $paths as $path ) {
			$in_base     = array_key_exists( $path, $base_manifest );
			$in_proposal = array_key_exists( $path, $proposal_manifest );
			$status      = ! $in_base ? 'added' : ( ! $in_proposal ? 'removed' : ( $base_manifest[ $path ] === $proposal_manifest[ $path ] ? 'unchanged' : 'changed' ) );
			$diff[]      = array(
				'path'                 => $path,
				'status'               => $status,
				'base_checksum'        => $base_manifest[ $path ] ?? '',
				'proposal_checksum'    => $proposal_manifest[ $path ] ?? '',
				'proposal_attachment_id' => $proposal_files[ $path ] ?? 0,
			);
		}

		return $diff;
	}

	/**
	 * Compares a published release with the fixed copy currently in review.
	 */
	public static function diff_release_to_submission( $release_id, $submission_id ) {
		$base_manifest     = self::stored_release_manifest( $release_id );
		$proposal_manifest = self::submission_manifest( $submission_id );
		$proposal_files    = array();
		foreach ( self::get_submission_files( $submission_id ) as $file ) {
			$path = Blueprint_Registry_Validator::normalise_bundle_path( $file['path'] ?? '' );
			if ( ! is_wp_error( $path ) ) {
				$proposal_files[ $path ] = (int) $file['attachment_id'];
			}
		}

		$paths = array_unique( array_merge( array_keys( $base_manifest ), array_keys( $proposal_manifest ) ) );
		sort( $paths, SORT_NATURAL | SORT_FLAG_CASE );
		$diff = array();
		foreach ( $paths as $path ) {
			$in_base     = array_key_exists( $path, $base_manifest );
			$in_proposal = array_key_exists( $path, $proposal_manifest );
			$status      = ! $in_base ? 'added' : ( ! $in_proposal ? 'removed' : ( $base_manifest[ $path ] === $proposal_manifest[ $path ] ? 'unchanged' : 'changed' ) );
			$diff[]      = array(
				'path'                   => $path,
				'status'                 => $status,
				'base_checksum'          => $base_manifest[ $path ] ?? '',
				'proposal_checksum'      => $proposal_manifest[ $path ] ?? '',
				'proposal_attachment_id' => $proposal_files[ $path ] ?? 0,
			);
		}

		return $diff;
	}

	/**
	 * Returns the two text values needed for a native WordPress text diff.
	 */
	public static function text_file_pair( $release_id, $change_id, $path, $maximum_size = 262144 ) {
		$base = self::release_file_contents( $release_id, $path, $maximum_size );
		if ( is_wp_error( $base ) || ! self::is_text( $base ) ) {
			return null;
		}

		if ( 'blueprint.json' === $path ) {
			$proposal = Blueprint_Registry_Workflow::source( $change_id );
		} else {
			$proposal = null;
			foreach ( self::get_change_files( $change_id ) as $file ) {
				$candidate = Blueprint_Registry_Validator::normalise_bundle_path( $file['path'] ?? '' );
				if ( ! is_wp_error( $candidate ) && $candidate === $path ) {
					$disk = get_attached_file( (int) $file['attachment_id'] );
					if ( is_readable( $disk ) && filesize( $disk ) <= $maximum_size ) {
						$proposal = file_get_contents( $disk ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					}
					break;
				}
			}
		}

		if ( ! is_string( $proposal ) || ! self::is_text( $proposal ) ) {
			return null;
		}

		return array(
			'base'     => $base,
			'proposal' => $proposal,
		);
	}

	/**
	 * Returns the two text values for a release and a fixed submitted copy.
	 */
	public static function text_file_pair_submission( $release_id, $submission_id, $path, $maximum_size = 262144 ) {
		$base = self::release_file_contents( $release_id, $path, $maximum_size );
		if ( is_wp_error( $base ) || ! self::is_text( $base ) ) {
			return null;
		}

		if ( 'blueprint.json' === $path ) {
			$proposal = (string) get_post_field( 'post_content', $submission_id );
		} else {
			$proposal = self::submitted_file_contents( $submission_id, $path, $maximum_size );
		}

		if ( is_wp_error( $proposal ) || ! is_string( $proposal ) || ! self::is_text( $proposal ) ) {
			return null;
		}

		return array(
			'base'     => $base,
			'proposal' => $proposal,
		);
	}

	private static function stored_release_manifest( $release_id ) {
		$manifest = get_post_meta( $release_id, '_bp_manifest', true );
		if ( is_array( $manifest ) ) {
			return $manifest;
		}

		$manifest = array(
			'blueprint.json' => hash( 'sha256', (string) get_post_field( 'post_content', $release_id ) ),
		);
		$bundle   = get_attached_file( (int) get_post_meta( $release_id, '_bp_bundle_attachment_id', true ) );
		if ( ! class_exists( 'ZipArchive' ) || ! is_readable( $bundle ) ) {
			return $manifest;
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $bundle ) ) {
			return $manifest;
		}
		for ( $index = 0; $index < $zip->numFiles; $index++ ) {
			$name = $zip->getNameIndex( $index );
			if ( 'blueprint.json' === $name || str_ends_with( $name, '/' ) ) {
				continue;
			}
			$path     = Blueprint_Registry_Validator::normalise_bundle_path( $name );
			$contents = $zip->getFromIndex( $index );
			if ( ! is_wp_error( $path ) && false !== $contents ) {
				$manifest[ $path ] = hash( 'sha256', $contents );
			}
		}
		$zip->close();
		ksort( $manifest );
		return $manifest;
	}

	private static function change_manifest( $change_id ) {
		$source = Blueprint_Registry_Validator::canonical_json( Blueprint_Registry_Workflow::source( $change_id ) );
		if ( is_wp_error( $source ) ) {
			$source = Blueprint_Registry_Workflow::source( $change_id );
		}
		return self::release_manifest( $source, self::get_change_files( $change_id ) );
	}

	private static function submission_manifest( $submission_id ) {
		$source = Blueprint_Registry_Validator::canonical_json( (string) get_post_field( 'post_content', $submission_id ) );
		if ( is_wp_error( $source ) ) {
			$source = (string) get_post_field( 'post_content', $submission_id );
		}
		return self::release_manifest( $source, self::get_submission_files( $submission_id ) );
	}

	private static function submitted_file_contents( $submission_id, $path, $maximum_size ) {
		foreach ( self::get_submission_files( $submission_id ) as $file ) {
			$candidate = Blueprint_Registry_Validator::normalise_bundle_path( $file['path'] ?? '' );
			if ( is_wp_error( $candidate ) || $candidate !== $path ) {
				continue;
			}
			$disk = get_attached_file( (int) $file['attachment_id'] );
			if ( ! is_readable( $disk ) ) {
				return new WP_Error( 'blueprint_submission_file_unavailable', __( 'The submitted bundle file is unavailable.', 'blueprint-registry' ) );
			}
			if ( filesize( $disk ) > $maximum_size ) {
				return new WP_Error( 'blueprint_file_too_large', __( 'This file is too large to show in the browser.', 'blueprint-registry' ) );
			}
			return file_get_contents( $disk ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		return new WP_Error( 'blueprint_file_missing', __( 'The submitted bundle file does not exist.', 'blueprint-registry' ) );
	}

	private static function is_text( $contents ) {
		return is_string( $contents ) && ! str_contains( $contents, "\0" );
	}

	private static function build_zip( $source, array $files, $name, $preview = false ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'blueprint_zip_missing', __( 'The server needs the PHP ZipArchive extension.', 'blueprint-registry' ) );
		}

		$directory = $preview ? trailingslashit( wp_upload_dir()['basedir'] ) . 'blueprint-previews' : get_temp_dir();
		wp_mkdir_p( $directory );
		$path = trailingslashit( $directory ) . wp_unique_filename( $directory, $name );
		$zip  = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'blueprint_zip_failed', __( 'The bundle archive could not be created.', 'blueprint-registry' ) );
		}

		$zip->addFromString( 'blueprint.json', $source );
		foreach ( $files as $file ) {
			$bundle_path = Blueprint_Registry_Validator::normalise_bundle_path( $file['path'] );
			$disk_path   = get_attached_file( (int) $file['attachment_id'] );
			if ( is_wp_error( $bundle_path ) || ! is_readable( $disk_path ) || ! $zip->addFile( $disk_path, $bundle_path ) ) {
				$zip->close();
				@unlink( $path );
				return new WP_Error( 'blueprint_zip_failed', __( 'A bundle file could not be added to the archive.', 'blueprint-registry' ) );
			}
		}
		$zip->close();

		return $path;
	}

	private static function attach_file( $source_path, $filename, $parent_id, $mime_type ) {
		$uploaded = wp_upload_bits( $filename, null, file_get_contents( $source_path ) );
		if ( ! empty( $uploaded['error'] ) ) {
			return new WP_Error( 'blueprint_upload_failed', $uploaded['error'] );
		}
		return self::attach_uploaded_file( $uploaded['file'], $parent_id, $mime_type );
	}

	private static function attach_uploaded_file( $path, $parent_id, $mime_type = '' ) {
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime_type ?: wp_check_filetype( $path )['type'],
				'post_title'     => sanitize_file_name( basename( $path ) ),
				'post_status'    => 'inherit',
			),
			$path,
			$parent_id
		);
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $path );
		wp_update_attachment_metadata( $attachment_id, $metadata );
		return (int) $attachment_id;
	}
}
