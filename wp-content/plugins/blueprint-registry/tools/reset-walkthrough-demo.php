<?php
/**
 * Removes everything the walkthrough recording creates, so it can be re-run.
 *
 * Run with:
 * docker compose run --rm wpcli eval-file \
 *   wp-content/plugins/blueprint-registry/tools/reset-walkthrough-demo.php
 */

$purge_change = static function ( $change_id ) {
	foreach ( Blueprint_Registry_Bundles::get_change_files( $change_id ) as $file ) {
		wp_delete_attachment( (int) $file['attachment_id'], true );
	}
	foreach ( get_posts( array( 'post_type' => 'blueprint_submission', 'post_parent' => $change_id, 'post_status' => 'any', 'posts_per_page' => -1 ) ) as $submission ) {
		foreach ( Blueprint_Registry_Bundles::get_submission_files( $submission->ID ) as $file ) {
			wp_delete_attachment( (int) $file['attachment_id'], true );
		}
		wp_delete_post( $submission->ID, true );
	}
	foreach ( Blueprint_Registry_Workflow::review_messages( $change_id ) as $message ) {
		wp_delete_comment( (int) $message->comment_ID, true );
	}
	wp_delete_post( $change_id, true );
};

$removed = 0;

$fork = get_page_by_path( 'nordic-art-gallery', OBJECT, 'blueprint' );
if ( $fork ) {
	foreach ( Blueprint_Registry_Routes::releases( $fork->ID ) as $release ) {
		wp_delete_attachment( (int) get_post_meta( $release->ID, '_bp_bundle_attachment_id', true ), true );
		wp_delete_post( $release->ID, true );
	}
	foreach ( get_posts( array( 'post_type' => 'blueprint_change', 'post_status' => 'any', 'posts_per_page' => -1, 'meta_key' => '_bp_target_blueprint_id', 'meta_value' => $fork->ID ) ) as $change ) {
		$purge_change( $change->ID );
	}
	wp_delete_post( $fork->ID, true );
	$removed++;
}

foreach ( get_posts( array( 'post_type' => 'blueprint_change', 'post_status' => 'any', 'posts_per_page' => -1 ) ) as $change ) {
	$title  = get_the_title( $change );
	$status = get_post_meta( $change->ID, '_bp_status', true );
	$is_demo = 'Scratch idea' === $title
		|| 'Nordic Art Gallery' === $title
		|| ( 'rejected' === $status && 'Gaming News' === $title );

	if ( $is_demo ) {
		$purge_change( $change->ID );
		$removed++;
	}
}

fwrite( STDOUT, "Removed {$removed} walkthrough artefacts.\n" );
