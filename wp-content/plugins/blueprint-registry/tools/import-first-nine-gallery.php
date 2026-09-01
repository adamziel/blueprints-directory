<?php
/**
 * Imports the first nine entries from the checked-out WordPress/blueprints gallery.
 *
 * Run with:
 * BLUEPRINT_IMPORT_SOURCE=/source BLUEPRINT_IMPORT_RESET=1 \
 * wp eval-file wp-content/plugins/blueprint-registry/tools/import-first-nine-gallery.php
 */

defined( 'ABSPATH' ) || exit;

$source_directory = getenv( 'BLUEPRINT_IMPORT_SOURCE' ) ?: '/source';
$reset            = '1' === getenv( 'BLUEPRINT_IMPORT_RESET' );
$entries          = array(
	'portfolio-vueo',
	'brewcommerce',
	'friends-cors',
	'news-spiel',
	'organization-koinonia',
	'personal-substrata',
	'personal-readymade',
	'portfolio-grammer',
	'news-piel',
);

if ( ! is_dir( $source_directory ) ) {
	throw new RuntimeException( sprintf( 'Import source %s is unavailable.', $source_directory ) );
}

$delete_change = static function ( $change_id ) {
	foreach ( Blueprint_Registry_Bundles::get_change_files( $change_id ) as $file ) {
		wp_delete_attachment( (int) $file['attachment_id'], true );
	}
	wp_delete_post( $change_id, true );
};

$delete_blueprint = static function ( $blueprint_id ) use ( $delete_change ) {
	foreach ( get_posts( array( 'post_type' => 'blueprint_change', 'post_parent' => $blueprint_id, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $change_id ) {
		$delete_change( $change_id );
	}
	foreach ( get_posts( array( 'post_type' => 'blueprint_release', 'post_parent' => $blueprint_id, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $release_id ) {
		wp_delete_attachment( (int) get_post_meta( $release_id, '_bp_bundle_attachment_id', true ), true );
		wp_delete_post( $release_id, true );
	}
	$thumbnail_id = get_post_thumbnail_id( $blueprint_id );
	if ( $thumbnail_id ) {
		wp_delete_attachment( $thumbnail_id, true );
	}
	wp_delete_post( $blueprint_id, true );
};

if ( $reset ) {
	foreach ( get_posts( array( 'post_type' => 'blueprint_change', 'post_status' => 'any', 'posts_per_page' => -1, 'meta_key' => '_bp_change_type', 'meta_value' => 'import', 'fields' => 'ids' ) ) as $change_id ) {
		$delete_change( $change_id );
	}
	foreach ( get_posts( array( 'post_type' => 'blueprint', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $blueprint_id ) {
		if ( 'starter-blueprint' === get_post_field( 'post_name', $blueprint_id ) || get_post_meta( $blueprint_id, '_bp_upstream_gallery_slug', true ) ) {
			$delete_blueprint( $blueprint_id );
		}
	}
}

$upload_file = static function ( $file_path, $parent_id ) {
	$uploads = wp_upload_dir();
	$target  = trailingslashit( $uploads['path'] ) . wp_unique_filename( $uploads['path'], basename( $file_path ) );
	if ( ! copy( $file_path, $target ) ) {
		throw new RuntimeException( sprintf( 'Could not copy %s into the media library.', basename( $file_path ) ) );
	}

	$file_type = wp_check_filetype( $target );
	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => $file_type['type'] ?: 'application/octet-stream',
			'post_title'     => sanitize_file_name( basename( $target ) ),
			'post_status'    => 'inherit',
		),
		$target,
		$parent_id
	);
	if ( is_wp_error( $attachment_id ) ) {
		@unlink( $target );
		throw new RuntimeException( $attachment_id->get_error_message() );
	}

	return (int) $attachment_id;
};

wp_set_current_user( 1 );
$imported = array();

foreach ( $entries as $index => $directory_name ) {
	$directory = trailingslashit( $source_directory ) . 'blueprints/' . $directory_name;
	$source    = file_get_contents( $directory . '/blueprint.json' );
	$data      = json_decode( $source, true );
	if ( JSON_ERROR_NONE !== json_last_error() || empty( $data['meta']['title'] ) ) {
		throw new RuntimeException( sprintf( '%s has invalid Blueprint metadata.', $directory_name ) );
	}

	$existing = get_posts(
		array(
			'post_type'      => 'blueprint',
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'meta_key'       => '_bp_upstream_gallery_slug',
			'meta_value'     => $directory_name,
		)
	);
	if ( $existing ) {
		update_post_meta( $existing[0]->ID, '_bp_gallery_order', $index + 1 );
		$imported[] = get_the_title( $existing[0] ) . ' (already present)';
		continue;
	}

	$change_id = Blueprint_Registry_Workflow::create_change(
		array(
			'title'       => $data['meta']['title'],
			'author_id'   => 1,
			'change_type' => 'import',
		)
	);
	if ( is_wp_error( $change_id ) ) {
		throw new RuntimeException( $change_id->get_error_message() );
	}

	Blueprint_Registry_Workflow::set_source( $change_id, $source );

	foreach ( glob( $directory . '/*' ) as $file_path ) {
		$filename = basename( $file_path );
		if ( ! is_file( $file_path ) || in_array( $filename, array( 'blueprint.json', 'screenshot.jpg' ), true ) ) {
			continue;
		}
		$attachment_id = $upload_file( $file_path, $change_id );
		$added = Blueprint_Registry_Bundles::add_change_file( $change_id, $attachment_id, $filename );
		if ( is_wp_error( $added ) ) {
			throw new RuntimeException( $added->get_error_message() );
		}
	}

	$submitted = Blueprint_Registry_Workflow::submit( $change_id, 1 );
	if ( is_wp_error( $submitted ) ) {
		throw new RuntimeException( sprintf( '%s did not validate: %s', $directory_name, $submitted->get_error_message() ) );
	}
	$release_id = Blueprint_Registry_Workflow::review( $change_id, 'approved', 1, 'Imported from the upstream Blueprints gallery.' );
	if ( is_wp_error( $release_id ) ) {
		throw new RuntimeException( $release_id->get_error_message() );
	}

	$blueprint_id = (int) get_post_meta( $change_id, '_bp_target_blueprint_id', true );
	wp_set_object_terms( $blueprint_id, $data['meta']['categories'] ?? array(), 'blueprint_category', false );
	update_post_meta( $blueprint_id, '_bp_upstream_gallery_slug', $directory_name );
	update_post_meta( $blueprint_id, '_bp_upstream_author', $data['meta']['author'] ?? '' );
	update_post_meta( $blueprint_id, '_bp_upstream_commit', getenv( 'BLUEPRINT_IMPORT_COMMIT' ) ?: '' );
	update_post_meta( $blueprint_id, '_bp_gallery_order', $index + 1 );

	$screenshot = $directory . '/screenshot.jpg';
	if ( is_readable( $screenshot ) ) {
			$screenshot_id = $upload_file( $screenshot, $blueprint_id );
			set_post_thumbnail( $blueprint_id, $screenshot_id );
	}

	$imported[] = get_the_title( $blueprint_id );
}

fwrite( STDOUT, 'Imported ' . count( $imported ) . ' gallery Blueprints: ' . implode( ', ', $imported ) . PHP_EOL );
