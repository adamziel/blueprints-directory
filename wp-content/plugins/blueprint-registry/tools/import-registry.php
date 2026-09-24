<?php
/**
 * Replays a directory written by tools/export-registry.php.
 *
 * Every Blueprint is rebuilt through the real workflow — propose, submit,
 * review — so post IDs, meta pointers, and bundles are consistent by
 * construction rather than remapped after the fact.
 *
 * Run with:
 * BLUEPRINT_IMPORT_DIR=/source/registry-export wp eval-file \
 *   wp-content/plugins/blueprint-registry/tools/import-registry.php
 */

defined( 'ABSPATH' ) || exit;

$directory = getenv( 'BLUEPRINT_IMPORT_DIR' ) ?: '/source/registry-export';
$manifest  = json_decode( (string) file_get_contents( trailingslashit( $directory ) . 'manifest.json' ), true );

if ( ! is_array( $manifest ) ) {
	throw new RuntimeException( sprintf( 'No readable manifest in %s.', $directory ) );
}

wp_set_current_user( 1 );
Blueprint_Registry_Capabilities::register();

/**
 * Copies a screenshot into the Media Library. Only images go there — bundle
 * files use the plugin's private storage, which has no allow-list to satisfy.
 */
$attach = static function ( $source_path, $filename, $parent_id ) {
	$uploads = wp_upload_dir();
	$target  = trailingslashit( $uploads['path'] ) . wp_unique_filename( $uploads['path'], $filename );

	if ( ! copy( $source_path, $target ) ) {
		throw new RuntimeException( sprintf( 'Could not copy %s.', $filename ) );
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';
	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => wp_check_filetype( $target )['type'] ?: 'application/octet-stream',
			'post_title'     => sanitize_file_name( basename( $target ) ),
			'post_status'    => 'inherit',
		),
		$target,
		(int) $parent_id
	);

	if ( is_wp_error( $attachment_id ) ) {
		throw new RuntimeException( $attachment_id->get_error_message() );
	}

	wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $target ) );

	return (int) $attachment_id;
};

/**
 * Unpacks a revision bundle into the change: blueprint.json becomes the source,
 * everything else becomes a bundle file.
 */
$load_bundle = static function ( $change_id, $bundle_path ) {
	$zip = new ZipArchive();
	if ( true !== $zip->open( $bundle_path ) ) {
		throw new RuntimeException( sprintf( 'Could not open %s.', $bundle_path ) );
	}

	update_post_meta( $change_id, '_bp_change_files', array() );

	for ( $index = 0; $index < $zip->numFiles; $index++ ) {
		$name = $zip->getNameIndex( $index );
		if ( str_ends_with( $name, '/' ) ) {
			continue;
		}

		$contents = $zip->getFromIndex( $index );
		if ( false === $contents ) {
			continue;
		}

		if ( 'blueprint.json' === $name ) {
			Blueprint_Registry_Workflow::set_source( $change_id, $contents );
			continue;
		}

		$stored = Blueprint_Registry_Bundles::add_change_file( $change_id, $contents, $name );
		if ( is_wp_error( $stored ) ) {
			throw new RuntimeException( sprintf( 'Could not store %s: %s', $name, $stored->get_error_message() ) );
		}
	}

	$zip->close();
};

$forks = array();

foreach ( $manifest as $record ) {
	$home       = trailingslashit( $directory ) . $record['slug'];
	$blueprint  = 0;

	foreach ( $record['revisions'] as $position => $revision ) {
		$bundle = trailingslashit( $home ) . $revision['bundle'];
		if ( ! file_exists( $bundle ) ) {
			continue;
		}

		$change = Blueprint_Registry_Workflow::create_change(
			$blueprint
				? array( 'title' => $record['title'], 'author_id' => 1, 'target_id' => $blueprint, 'release_id' => (int) get_post_meta( $blueprint, '_bp_current_release_id', true ) )
				: array( 'title' => $record['title'], 'author_id' => 1 )
		);

		if ( is_wp_error( $change ) ) {
			throw new RuntimeException( $change->get_error_message() );
		}

		$load_bundle( $change, $bundle );

		$submitted = Blueprint_Registry_Workflow::submit( $change, 1 );
		if ( is_wp_error( $submitted ) ) {
			throw new RuntimeException( 'Submit failed: ' . $submitted->get_error_message() );
		}

		$released = Blueprint_Registry_Workflow::review( $change, 'approved', 1, $revision['note'] ?: '' );
		if ( is_wp_error( $released ) ) {
			throw new RuntimeException( 'Publish failed: ' . $released->get_error_message() );
		}

		if ( ! $blueprint ) {
			$blueprint = (int) get_post_meta( $change, '_bp_target_blueprint_id', true );
		}
	}

	if ( ! $blueprint ) {
		continue;
	}

	// Presentation the workflow does not carry: slug, description, taxonomy,
	// gallery position, and the screenshot the gallery leads with.
	wp_update_post(
		array(
			'ID'           => $blueprint,
			'post_name'    => $record['slug'],
			'post_content' => wp_slash( $record['description'] ),
		)
	);

	if ( $record['categories'] ) {
		wp_set_object_terms( $blueprint, $record['categories'], 'blueprint_category' );
	}
	if ( '' !== (string) $record['order'] ) {
		update_post_meta( $blueprint, '_bp_gallery_order', $record['order'] );
	}
	if ( $record['thumbnail'] && file_exists( trailingslashit( $home ) . $record['thumbnail'] ) ) {
		set_post_thumbnail( $blueprint, $attach( trailingslashit( $home ) . $record['thumbnail'], $record['thumbnail'], $blueprint ) );
	}
	if ( $record['forked_from'] ) {
		$forks[ $blueprint ] = $record['forked_from'];
	}

	foreach ( $record['proposals'] as $proposal ) {
		$change = Blueprint_Registry_Workflow::create_change(
			array(
				'title'      => $proposal['title'],
				'author_id'  => 1,
				'target_id'  => $blueprint,
				'release_id' => (int) get_post_meta( $blueprint, '_bp_current_release_id', true ),
			)
		);

		if ( is_wp_error( $change ) ) {
			continue;
		}

		Blueprint_Registry_Workflow::set_source( $change, $proposal['source'] );

		if ( 'draft' === $proposal['status'] ) {
			continue;
		}

		Blueprint_Registry_Workflow::submit( $change, 1 );

		if ( 'changes_requested' === $proposal['status'] ) {
			$note = '';
			foreach ( $proposal['messages'] as $message ) {
				if ( 'changes_requested' === $message['decision'] ) {
					$note = $message['note'];
				}
			}
			Blueprint_Registry_Workflow::review( $change, 'changes_requested', 1, $note ?: 'Please take another look.' );
		}
	}

	fwrite( STDOUT, sprintf( "Imported %-34s %d revision(s), %d proposal(s)\n", $record['slug'], count( $record['revisions'] ), count( $record['proposals'] ) ) );
}

// Fork links can only be resolved once every Blueprint exists.
foreach ( $forks as $blueprint => $origin_slug ) {
	$origin = get_page_by_path( $origin_slug, OBJECT, 'blueprint' );
	if ( $origin ) {
		update_post_meta( $blueprint, '_bp_forked_from_blueprint_id', $origin->ID );
	}
}

fwrite( STDOUT, "Done.\n" );
