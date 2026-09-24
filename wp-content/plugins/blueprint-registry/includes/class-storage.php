<?php
/**
 * Private storage for the files attached to a proposal.
 *
 * A Blueprint bundle may legitimately contain any file type — a SQL seed, a PHP
 * helper, a font, an archive. The browser first packs them into one ZIP, which
 * WordPress accepts through its ordinary ZIP upload rules. Only after that does
 * the plugin unpack the archive into this storage.
 *
 * So proposal files live here instead — outside the Media Library, in a
 * directory that is not meant to be fetched directly, reachable only through the
 * plugin's permission-checked download route. Only the accepted release is
 * packaged as a public bundle.zip.
 *
 * @package BlueprintRegistry
 */

defined( 'ABSPATH' ) || exit;

final class Blueprint_Registry_Storage {
	/**
	 * Ceilings, so a proposal cannot fill the disk. Generous enough for the
	 * theme and content archives real Blueprints carry.
	 */
	const MAX_FILE_BYTES   = 67108864;  // 64 MB.
	const MAX_BUNDLE_BYTES = 268435456; // 256 MB.
	const MAX_FILES        = 50;

	const DIRECTORY_OPTION = 'blueprint_registry_storage_directory';

	/**
	 * The private root, created on first use.
	 *
	 * The directory name carries a per-site random suffix. Apache is told to
	 * refuse it and IIS likewise, but neither applies under nginx, so the
	 * unguessable name is what actually keeps a direct fetch from working.
	 */
	public static function base_directory() {
		$name = get_option( self::DIRECTORY_OPTION );
		if ( ! $name ) {
			$name = 'blueprint-registry-' . wp_generate_password( 20, false, false );
			update_option( self::DIRECTORY_OPTION, $name, false );
		}

		$directory = trailingslashit( wp_upload_dir()['basedir'] ) . $name;
		if ( ! is_dir( $directory ) ) {
			wp_mkdir_p( $directory );
		}

		self::protect( $directory );

		return trailingslashit( $directory );
	}

	private static function protect( $directory ) {
		$guards = array(
			'.htaccess'  => "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n",
			'web.config' => "<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
		);

		foreach ( $guards as $name => $contents ) {
			$file = trailingslashit( $directory ) . $name;
			if ( ! file_exists( $file ) ) {
				file_put_contents( $file, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents
			}
		}
	}

	private static function owner_directory( $owner_id ) {
		$directory = self::base_directory() . (int) $owner_id;
		if ( ! is_dir( $directory ) ) {
			wp_mkdir_p( $directory );
		}

		return trailingslashit( $directory );
	}

	/**
	 * Resolves a stored file, refusing anything that escapes its own directory.
	 */
	public static function file_path( $owner_id, $key ) {
		$key = (string) $key;
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
			return new WP_Error( 'blueprint_storage_key', __( 'That bundle file reference is not valid.', 'blueprint-registry' ) );
		}

		return self::owner_directory( $owner_id ) . $key . '.bin';
	}

	/**
	 * Stores a file's contents against a proposal and returns its record.
	 *
	 * Files are written with a generated name and a neutral extension: nothing
	 * about the stored name is taken from the upload, so a `.php` in the bundle
	 * is inert on disk whatever the server is configured to execute.
	 */
	public static function store( $owner_id, $contents, $bundle_path, array $existing = array() ) {
		$path = Blueprint_Registry_Validator::normalise_bundle_path( $bundle_path );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$size = strlen( $contents );
		if ( $size > self::MAX_FILE_BYTES ) {
			return new WP_Error(
				'blueprint_file_too_large',
				sprintf(
					/* translators: %s: the largest allowed file size. */
					__( 'That file is larger than %s.', 'blueprint-registry' ),
					size_format( self::MAX_FILE_BYTES )
				)
			);
		}

		if ( count( $existing ) >= self::MAX_FILES ) {
			return new WP_Error(
				'blueprint_too_many_files',
				sprintf(
					/* translators: %d: the largest allowed number of files. */
					__( 'A bundle may hold at most %d files.', 'blueprint-registry' ),
					self::MAX_FILES
				)
			);
		}

		$total = $size;
		foreach ( $existing as $file ) {
			$total += (int) ( $file['size'] ?? 0 );
		}
		if ( $total > self::MAX_BUNDLE_BYTES ) {
			return new WP_Error(
				'blueprint_bundle_too_large',
				sprintf(
					/* translators: %s: the largest allowed bundle size. */
					__( 'The whole bundle may not exceed %s.', 'blueprint-registry' ),
					size_format( self::MAX_BUNDLE_BYTES )
				)
			);
		}

		$key  = md5( wp_generate_password( 32, false, false ) . microtime() );
		$file = self::file_path( $owner_id, $key );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		if ( false === file_put_contents( $file, $contents ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents
			return new WP_Error( 'blueprint_storage_failed', __( 'That bundle file could not be stored.', 'blueprint-registry' ) );
		}

		return array(
			'key'    => $key,
			'path'   => $path,
			'size'   => $size,
			'sha256' => hash( 'sha256', $contents ),
		);
	}

	/**
	 * Stores a file already on disk, without loading it all into memory.
	 */
	public static function store_file( $owner_id, $source_path, $bundle_path, array $existing = array() ) {
		if ( ! is_readable( $source_path ) ) {
			return new WP_Error( 'blueprint_storage_unreadable', __( 'That file could not be read.', 'blueprint-registry' ) );
		}

		$path = Blueprint_Registry_Validator::normalise_bundle_path( $bundle_path );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$size = (int) filesize( $source_path );
		if ( $size > self::MAX_FILE_BYTES ) {
			return new WP_Error(
				'blueprint_file_too_large',
				sprintf(
					/* translators: %s: the largest allowed file size. */
					__( 'That file is larger than %s.', 'blueprint-registry' ),
					size_format( self::MAX_FILE_BYTES )
				)
			);
		}

		if ( count( $existing ) >= self::MAX_FILES ) {
			return new WP_Error(
				'blueprint_too_many_files',
				sprintf(
					/* translators: %d: the largest allowed number of files. */
					__( 'A bundle may hold at most %d files.', 'blueprint-registry' ),
					self::MAX_FILES
				)
			);
		}

		$total = $size;
		foreach ( $existing as $file ) {
			$total += (int) ( $file['size'] ?? 0 );
		}
		if ( $total > self::MAX_BUNDLE_BYTES ) {
			return new WP_Error(
				'blueprint_bundle_too_large',
				sprintf(
					/* translators: %s: the largest allowed bundle size. */
					__( 'The whole bundle may not exceed %s.', 'blueprint-registry' ),
					size_format( self::MAX_BUNDLE_BYTES )
				)
			);
		}

		$key    = md5( wp_generate_password( 32, false, false ) . microtime() );
		$target = self::file_path( $owner_id, $key );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		if ( ! copy( $source_path, $target ) ) {
			return new WP_Error( 'blueprint_storage_failed', __( 'That bundle file could not be stored.', 'blueprint-registry' ) );
		}

		return array(
			'key'    => $key,
			'path'   => $path,
			'size'   => $size,
			'sha256' => hash_file( 'sha256', $target ),
		);
	}

	/**
	 * Copies a stored file to another proposal, keeping its bundle path.
	 */
	public static function copy( $from_owner_id, array $record, $to_owner_id, array $existing = array() ) {
		$source = self::file_path( $from_owner_id, $record['key'] ?? '' );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		return self::store_file( $to_owner_id, $source, $record['path'] ?? '', $existing );
	}

	public static function read( $owner_id, array $record, $maximum_size = 0 ) {
		$file = self::file_path( $owner_id, $record['key'] ?? '' );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		if ( ! is_readable( $file ) ) {
			return new WP_Error( 'blueprint_file_missing', __( 'That bundle file is no longer stored.', 'blueprint-registry' ) );
		}

		if ( $maximum_size && filesize( $file ) > $maximum_size ) {
			return new WP_Error( 'blueprint_file_too_large', __( 'This file is too large to show in the browser.', 'blueprint-registry' ) );
		}

		return file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	public static function delete( $owner_id, array $record ) {
		$file = self::file_path( $owner_id, $record['key'] ?? '' );
		if ( ! is_wp_error( $file ) && file_exists( $file ) ) {
			unlink( $file );
		}
	}

	/**
	 * Removes every file belonging to one proposal.
	 */
	public static function delete_owner( $owner_id ) {
		$directory = self::owner_directory( $owner_id );
		foreach ( (array) glob( $directory . '*.bin' ) as $file ) {
			unlink( $file );
		}
		@rmdir( $directory ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Finds the record for one bundle path within a file list.
	 */
	public static function find( array $files, $bundle_path ) {
		foreach ( $files as $file ) {
			$candidate = Blueprint_Registry_Validator::normalise_bundle_path( $file['path'] ?? '' );
			if ( ! is_wp_error( $candidate ) && $candidate === $bundle_path ) {
				return $file;
			}
		}

		return null;
	}
}
