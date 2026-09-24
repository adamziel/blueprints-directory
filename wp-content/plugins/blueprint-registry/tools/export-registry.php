<?php
/**
 * Exports the whole registry to a portable directory.
 *
 * Moves the registry between installs without moving database rows. Rows carry
 * post IDs inside meta — current revision, base revision, bundle attachment,
 * per-file attachment lists — and none of that survives an ID remap, so this
 * writes what the registry means rather than how it is stored: for each
 * Blueprint, every published revision as its bundle, plus any proposal still
 * in flight. tools/import-registry.php replays it through the workflow.
 *
 * Run with:
 * BLUEPRINT_EXPORT_DIR=/source wp eval-file \
 *   wp-content/plugins/blueprint-registry/tools/export-registry.php
 */

defined( 'ABSPATH' ) || exit;

$directory = getenv( 'BLUEPRINT_EXPORT_DIR' ) ?: '/source/registry-export';

if ( ! wp_mkdir_p( $directory ) ) {
	throw new RuntimeException( sprintf( 'Could not create %s.', $directory ) );
}

$manifest = array();

foreach ( get_posts( array( 'post_type' => 'blueprint', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) ) as $blueprint ) {
	$slug = $blueprint->post_name;
	$home = trailingslashit( $directory ) . $slug;
	wp_mkdir_p( $home );

	$record = array(
		'slug'        => $slug,
		'title'       => $blueprint->post_title,
		'description' => $blueprint->post_content,
		'categories'  => wp_get_post_terms( $blueprint->ID, 'blueprint_category', array( 'fields' => 'names' ) ),
		'order'       => get_post_meta( $blueprint->ID, '_bp_gallery_order', true ),
		'forked_from' => (int) get_post_meta( $blueprint->ID, '_bp_forked_from_blueprint_id', true )
			? get_post_field( 'post_name', (int) get_post_meta( $blueprint->ID, '_bp_forked_from_blueprint_id', true ) )
			: '',
		'thumbnail'   => '',
		'revisions'   => array(),
		'proposals'   => array(),
	);

	$thumbnail = get_attached_file( get_post_thumbnail_id( $blueprint->ID ) );
	if ( $thumbnail && file_exists( $thumbnail ) ) {
		$record['thumbnail'] = basename( $thumbnail );
		copy( $thumbnail, trailingslashit( $home ) . basename( $thumbnail ) );
	}

	// Oldest first: the importer replays them in publication order.
	foreach ( Blueprint_Registry_Routes::releases( $blueprint->ID, 'ASC' ) as $release ) {
		$number = (int) get_post_meta( $release->ID, '_bp_release_number', true );
		$bundle = get_attached_file( (int) get_post_meta( $release->ID, '_bp_bundle_attachment_id', true ) );
		if ( ! $bundle || ! file_exists( $bundle ) ) {
			continue;
		}

		$name = sprintf( 'revision-%d.zip', $number );
		copy( $bundle, trailingslashit( $home ) . $name );
		$record['revisions'][] = array(
			'number' => $number,
			'bundle' => $name,
			'note'   => '',
		);
	}

	$manifest[] = $record;
}

// Proposals that have not been published yet, so the review queue is populated.
$blueprints_by_id = array();
foreach ( $manifest as $record ) {
	$post = get_page_by_path( $record['slug'], OBJECT, 'blueprint' );
	if ( $post ) {
		$blueprints_by_id[ $post->ID ] = $record['slug'];
	}
}

foreach ( get_posts( array( 'post_type' => 'blueprint_change', 'post_status' => 'any', 'posts_per_page' => -1 ) ) as $change ) {
	$status = get_post_meta( $change->ID, '_bp_status', true ) ?: 'draft';
	if ( ! in_array( $status, array( 'draft', 'pending_review', 'changes_requested' ), true ) ) {
		continue;
	}

	$target = (int) get_post_meta( $change->ID, '_bp_target_blueprint_id', true );
	if ( ! $target || ! isset( $blueprints_by_id[ $target ] ) ) {
		continue;
	}

	$messages = array();
	foreach ( Blueprint_Registry_Workflow::review_messages( $change->ID ) as $message ) {
		$messages[] = array(
			'decision' => get_comment_meta( $message->comment_ID, '_bp_review_decision', true ),
			'note'     => $message->comment_content,
		);
	}

	foreach ( $manifest as $index => $record ) {
		if ( $record['slug'] === $blueprints_by_id[ $target ] ) {
			$manifest[ $index ]['proposals'][] = array(
				'title'    => $change->post_title,
				'status'   => $status,
				'source'   => Blueprint_Registry_Workflow::source( $change->ID ),
				'messages' => $messages,
			);
		}
	}
}

file_put_contents(
	trailingslashit( $directory ) . 'manifest.json',
	wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
);

fwrite( STDOUT, sprintf( "Exported %d Blueprints to %s.\n", count( $manifest ), $directory ) );
foreach ( $manifest as $record ) {
	fwrite( STDOUT, sprintf( "  %-34s %d revision(s), %d proposal(s)\n", $record['slug'], count( $record['revisions'] ), count( $record['proposals'] ) ) );
}
