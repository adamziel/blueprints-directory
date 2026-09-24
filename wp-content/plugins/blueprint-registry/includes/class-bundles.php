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
		$files = array();

		foreach ( self::get_change_files( $change_id ) as $file ) {
			$copied = Blueprint_Registry_Storage::copy( $change_id, $file, $submission_id, $files );
			if ( is_wp_error( $copied ) ) {
				foreach ( $files as $stored ) {
					Blueprint_Registry_Storage::delete( $submission_id, $stored );
				}
				return $copied;
			}
			$files[] = $copied;
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
			Blueprint_Registry_Storage::delete( $target_change_id, $file );
		}
		update_post_meta( $target_change_id, '_bp_change_files', array() );

		$files = array();
		foreach ( self::get_change_files( $source_change_id ) as $file ) {
			$copied = Blueprint_Registry_Storage::copy( $source_change_id, $file, $target_change_id, $files );
			if ( is_wp_error( $copied ) ) {
				foreach ( $files as $stored ) {
					Blueprint_Registry_Storage::delete( $target_change_id, $stored );
				}
				return $copied;
			}
			$files[] = $copied;
		}

		update_post_meta( $target_change_id, '_bp_change_files', $files );
		return true;
	}

	/**
	 * Stores an uploaded file against a proposal and records it in the bundle.
	 */
	public static function add_change_file( $change_id, $contents, $path ) {
		$files  = self::get_change_files( $change_id );
		$record = Blueprint_Registry_Storage::store( $change_id, $contents, $path, $files );
		if ( is_wp_error( $record ) ) {
			return $record;
		}

		$files[] = $record;
		update_post_meta( $change_id, '_bp_change_files', $files );

		return $record;
	}

	/**
	 * Same, for a file already on disk.
	 */
	public static function add_change_file_from_disk( $change_id, $source_path, $path ) {
		$files  = self::get_change_files( $change_id );
		$record = Blueprint_Registry_Storage::store_file( $change_id, $source_path, $path, $files );
		if ( is_wp_error( $record ) ) {
			return $record;
		}

		$files[] = $record;
		update_post_meta( $change_id, '_bp_change_files', $files );

		return $record;
	}

	/**
	 * Adds or replaces draft files from one browser-created ZIP archive.
	 *
	 * The archive is the only thing submitted through the upload endpoint. Its
	 * entries are unpacked only after WordPress has accepted that ZIP through its
	 * ordinary upload checks.
	 */
	public static function import_change_archive( $change_id, $archive_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'blueprint_zip_missing', __( 'The server needs the PHP ZipArchive extension.', 'blueprint-registry' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $archive_path ) ) {
			return new WP_Error( 'blueprint_zip_unreadable', __( 'The uploaded bundle is not a readable ZIP archive.', 'blueprint-registry' ) );
		}
		if ( $zip->numFiles > Blueprint_Registry_Storage::MAX_FILES ) {
			$zip->close();
			return new WP_Error( 'blueprint_too_many_files', sprintf( __( 'A bundle may hold at most %d files.', 'blueprint-registry' ), Blueprint_Registry_Storage::MAX_FILES ) );
		}

		$incoming      = array();
		$incoming_size = 0;
		for ( $index = 0; $index < $zip->numFiles; $index++ ) {
			$name = $zip->getNameIndex( $index );
			if ( false === $name || str_ends_with( $name, '/' ) ) {
				continue;
			}

			$path = Blueprint_Registry_Validator::normalise_bundle_path( $name );
			if ( is_wp_error( $path ) ) {
				$zip->close();
				return $path;
			}
			if ( isset( $incoming[ $path ] ) ) {
				$zip->close();
				return new WP_Error( 'blueprint_duplicate_bundle_path', sprintf( __( 'The uploaded bundle contains %s more than once.', 'blueprint-registry' ), $path ) );
			}

			$stat = $zip->statIndex( $index );
			if ( ! is_array( $stat ) ) {
				$zip->close();
				return new WP_Error( 'blueprint_zip_unreadable', __( 'The uploaded bundle could not be inspected.', 'blueprint-registry' ) );
			}
			$size = (int) ( $stat['size'] ?? -1 );
			if ( $size < 0 || $size > Blueprint_Registry_Storage::MAX_FILE_BYTES ) {
				$zip->close();
				return new WP_Error( 'blueprint_file_too_large', sprintf( __( 'The file at %s is too large.', 'blueprint-registry' ), $path ) );
			}

			$incoming_size += $size;
			if ( $incoming_size > Blueprint_Registry_Storage::MAX_BUNDLE_BYTES ) {
				$zip->close();
				return new WP_Error( 'blueprint_bundle_too_large', __( 'The uploaded bundle is too large.', 'blueprint-registry' ) );
			}

			$contents = $zip->getFromIndex( $index );
			if ( false === $contents || strlen( $contents ) !== $size ) {
				$zip->close();
				return new WP_Error( 'blueprint_zip_unreadable', sprintf( __( 'The file at %s could not be read from the uploaded bundle.', 'blueprint-registry' ), $path ) );
			}
			$incoming[ $path ] = $contents;
		}
		$zip->close();

		if ( ! $incoming ) {
			return new WP_Error( 'blueprint_empty_bundle', __( 'The uploaded bundle does not contain any files.', 'blueprint-registry' ) );
		}

		$replaced = array();
		$kept     = array();
		foreach ( self::get_change_files( $change_id ) as $file ) {
			if ( isset( $incoming[ $file['path'] ?? '' ] ) ) {
				$replaced[] = $file;
				continue;
			}
			$kept[] = $file;
		}

		$stored = array();
		foreach ( $incoming as $path => $contents ) {
			$record = Blueprint_Registry_Storage::store( $change_id, $contents, $path, array_merge( $kept, $stored ) );
			if ( is_wp_error( $record ) ) {
				foreach ( $stored as $created ) {
					Blueprint_Registry_Storage::delete( $change_id, $created );
				}
				return $record;
			}
			$stored[] = $record;
		}

		update_post_meta( $change_id, '_bp_change_files', array_merge( $kept, $stored ) );
		foreach ( $replaced as $file ) {
			Blueprint_Registry_Storage::delete( $change_id, $file );
		}

		return $stored;
	}

	public static function remove_change_file( $change_id, $key ) {
		$kept = array();
		foreach ( self::get_change_files( $change_id ) as $file ) {
			if ( ( $file['key'] ?? '' ) === (string) $key ) {
				Blueprint_Registry_Storage::delete( $change_id, $file );
				continue;
			}
			$kept[] = $file;
		}

		update_post_meta( $change_id, '_bp_change_files', $kept );
	}

	public static function build_release_bundle( $release_id, $source, array $files, $owner_id ) {
		$path = self::build_zip( $source, $files, 'blueprint-release-' . $release_id . '.zip', false, $owner_id );
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
		$path   = self::build_zip( $source, self::get_change_files( $change_id ), 'blueprint-preview-' . $change_id . '.zip', true, $change_id );
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
		$errors = Blueprint_Registry_Validator::validate( $source, self::get_submission_files( $submission_id ), $submission_id );
		if ( $errors ) {
			return new WP_Error( 'blueprint_invalid_submission', implode( ' ', $errors ) );
		}

		$path = self::build_zip( $source, self::get_submission_files( $submission_id ), 'blueprint-preview-' . $submission_id . '.zip', true, $submission_id );
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

	/**
	 * Checksums every file in a bundle. The stored record already carries one,
	 * so this is a lookup rather than a re-read of the whole bundle.
	 */
	public static function release_manifest( $source, array $files ) {
		$manifest = array(
			'blueprint.json' => hash( 'sha256', $source ),
		);

		foreach ( $files as $file ) {
			$path = Blueprint_Registry_Validator::normalise_bundle_path( $file['path'] ?? '' );
			if ( is_wp_error( $path ) || empty( $file['sha256'] ) ) {
				continue;
			}
			$manifest[ $path ] = $file['sha256'];
		}

		ksort( $manifest );
		return $manifest;
	}

	public static function copy_release_to_change( $release_id, $change_id ) {
		$bundle_id = (int) get_post_meta( $release_id, '_bp_bundle_attachment_id', true );
		$bundle    = get_attached_file( $bundle_id );
		if ( ! class_exists( 'ZipArchive' ) || ! is_readable( $bundle ) ) {
			return new WP_Error( 'blueprint_bundle_unavailable', __( 'The revision bundle is unavailable.', 'blueprint-registry' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $bundle ) ) {
			return new WP_Error( 'blueprint_bundle_unreadable', __( 'The revision bundle could not be opened.', 'blueprint-registry' ) );
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
				return new WP_Error( 'blueprint_bundle_unreadable', __( 'A revision file could not be copied.', 'blueprint-registry' ) );
			}

			$stored = self::add_change_file( $change_id, $contents, $path );
			if ( is_wp_error( $stored ) ) {
				$zip->close();
				return $stored;
			}
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
			return new WP_Error( 'blueprint_missing_release', __( 'The Blueprint revision does not exist.', 'blueprint-registry' ) );
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
			return new WP_Error( 'blueprint_bundle_unreadable', __( 'The revision bundle could not be opened.', 'blueprint-registry' ) );
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
			return new WP_Error( 'blueprint_bundle_unavailable', __( 'The revision bundle is unavailable.', 'blueprint-registry' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $bundle ) ) {
			return new WP_Error( 'blueprint_bundle_unreadable', __( 'The revision bundle could not be opened.', 'blueprint-registry' ) );
		}
		$stat = $zip->statName( $path );
		if ( ! is_array( $stat ) ) {
			$zip->close();
			return new WP_Error( 'blueprint_file_missing', __( 'The revision file does not exist.', 'blueprint-registry' ) );
		}
		if ( (int) $stat['size'] > $maximum_size ) {
			$zip->close();
			return new WP_Error( 'blueprint_file_too_large', __( 'This file is too large to show in the browser.', 'blueprint-registry' ) );
		}
		$contents = $zip->getFromName( $path );
		$zip->close();

		return false === $contents ? new WP_Error( 'blueprint_bundle_unreadable', __( 'The revision file could not be read.', 'blueprint-registry' ) ) : $contents;
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
			wp_die( esc_html__( 'The revision bundle is unavailable.', 'blueprint-registry' ), esc_html__( 'Not found', 'blueprint-registry' ), array( 'response' => 404 ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $bundle ) || ! ( $stream = $zip->getStream( $path ) ) ) {
			wp_die( esc_html__( 'The revision file does not exist.', 'blueprint-registry' ), esc_html__( 'Not found', 'blueprint-registry' ), array( 'response' => 404 ) );
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
				$proposal_files[ $path ] = (string) ( $file['key'] ?? '' );
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
				'proposal_key'           => $proposal_files[ $path ] ?? '',
			);
		}

		return $diff;
	}

	/**
	 * Compares a published release with the fixed copy currently in review.
	 */
	/**
	 * Compares two published revisions.
	 *
	 * Both sides are immutable and both already carry a checksum manifest, so
	 * this is a straight comparison of the two manifests.
	 */
	public static function diff_release_to_release( $base_release_id, $target_release_id ) {
		$base_manifest   = self::stored_release_manifest( $base_release_id );
		$target_manifest = self::stored_release_manifest( $target_release_id );

		$paths = array_unique( array_merge( array_keys( $base_manifest ), array_keys( $target_manifest ) ) );
		sort( $paths, SORT_NATURAL | SORT_FLAG_CASE );

		$diff = array();
		foreach ( $paths as $path ) {
			$in_base   = array_key_exists( $path, $base_manifest );
			$in_target = array_key_exists( $path, $target_manifest );
			$diff[]    = array(
				'path'                   => $path,
				'status'                 => ! $in_base ? 'added' : ( ! $in_target ? 'removed' : ( $base_manifest[ $path ] === $target_manifest[ $path ] ? 'unchanged' : 'changed' ) ),
				'base_checksum'          => $base_manifest[ $path ] ?? '',
				'proposal_checksum'      => $target_manifest[ $path ] ?? '',
				'proposal_key'           => '',
			);
		}

		return $diff;
	}

	/**
	 * Reads one file from two revisions, for a side-by-side text comparison.
	 */
	public static function text_file_pair_releases( $base_release_id, $target_release_id, $path, $maximum_size = 262144 ) {
		$base   = isset( self::stored_release_manifest( $base_release_id )[ $path ] ) ? self::release_file_contents( $base_release_id, $path, $maximum_size ) : '';
		$target = isset( self::stored_release_manifest( $target_release_id )[ $path ] ) ? self::release_file_contents( $target_release_id, $path, $maximum_size ) : '';

		if ( is_wp_error( $base ) || is_wp_error( $target ) || ! self::is_text( $base ) || ! self::is_text( $target ) ) {
			return null;
		}

		return array(
			'base'     => $base,
			'proposal' => $target,
		);
	}

	public static function diff_release_to_submission( $release_id, $submission_id ) {
		$base_manifest     = self::stored_release_manifest( $release_id );
		$proposal_manifest = self::submission_manifest( $submission_id );
		$proposal_files    = array();
		foreach ( self::get_submission_files( $submission_id ) as $file ) {
			$path = Blueprint_Registry_Validator::normalise_bundle_path( $file['path'] ?? '' );
			if ( ! is_wp_error( $path ) ) {
				$proposal_files[ $path ] = (string) ( $file['key'] ?? '' );
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
				'proposal_key'           => $proposal_files[ $path ] ?? '',
			);
		}

		return $diff;
	}

	/**
	 * Returns the two text values needed for a native WordPress text diff.
	 */
	public static function text_file_pair( $release_id, $change_id, $path, $maximum_size = 262144 ) {
		$base = isset( self::stored_release_manifest( $release_id )[ $path ] ) ? self::release_file_contents( $release_id, $path, $maximum_size ) : '';
		if ( is_wp_error( $base ) || ! self::is_text( $base ) ) {
			return null;
		}

		if ( 'blueprint.json' === $path ) {
			$proposal = Blueprint_Registry_Workflow::source( $change_id );
		} else {
			$proposal = '';
			$file     = Blueprint_Registry_Storage::find( self::get_change_files( $change_id ), $path );
			if ( $file ) {
				$read     = Blueprint_Registry_Storage::read( $change_id, $file, $maximum_size );
				$proposal = is_wp_error( $read ) ? null : $read;
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
		$base = isset( self::stored_release_manifest( $release_id )[ $path ] ) ? self::release_file_contents( $release_id, $path, $maximum_size ) : '';
		if ( is_wp_error( $base ) || ! self::is_text( $base ) ) {
			return null;
		}

		if ( 'blueprint.json' === $path ) {
			$proposal = (string) get_post_field( 'post_content', $submission_id );
		} else {
			$proposal = Blueprint_Registry_Storage::find( self::get_submission_files( $submission_id ), $path ) ? self::submitted_file_contents( $submission_id, $path, $maximum_size ) : '';
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
		$file = Blueprint_Registry_Storage::find( self::get_submission_files( $submission_id ), $path );
		if ( ! $file ) {
			return new WP_Error( 'blueprint_file_missing', __( 'The submitted bundle file does not exist.', 'blueprint-registry' ) );
		}

		return Blueprint_Registry_Storage::read( $submission_id, $file, $maximum_size );
	}

	private static function is_text( $contents ) {
		return is_string( $contents ) && ! str_contains( $contents, "\0" );
	}

	private static function build_zip( $source, array $files, $name, $preview = false, $owner_id = 0 ) {
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
			$bundle_path = Blueprint_Registry_Validator::normalise_bundle_path( $file['path'] ?? '' );
			$disk_path   = Blueprint_Registry_Storage::file_path( $owner_id, $file['key'] ?? '' );
			if ( is_wp_error( $bundle_path ) || is_wp_error( $disk_path ) || ! is_readable( $disk_path ) || ! $zip->addFile( $disk_path, $bundle_path ) ) {
				$zip->close();
				@unlink( $path );
				return new WP_Error( 'blueprint_zip_failed', __( 'A bundle file could not be added to the archive.', 'blueprint-registry' ) );
			}
		}
		$zip->close();

		return $path;
	}

	/**
	 * Attaches the public release bundle. This one is meant to be fetchable —
	 * it is the URL handed out for `?blueprint-url=` — so it stays an
	 * attachment, unlike the proposal files it was built from.
	 */
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
