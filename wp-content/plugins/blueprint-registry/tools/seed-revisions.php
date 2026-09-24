<?php
/**
 * Builds extra revisions for one Blueprint so the revision history has
 * something to browse.
 *
 * Each revision goes through the real workflow — propose, submit, review — so
 * the resulting bundles, diffs, and review messages are the same ones a
 * contributor would produce.
 *
 * Run with:
 * BLUEPRINT_SEED_SLUG=personal-blog BLUEPRINT_SEED_COUNT=4 \
 * wp eval-file wp-content/plugins/blueprint-registry/tools/seed-revisions.php
 */

defined( 'ABSPATH' ) || exit;

$slug  = getenv( 'BLUEPRINT_SEED_SLUG' ) ?: 'personal-blog';
$count = max( 1, (int) ( getenv( 'BLUEPRINT_SEED_COUNT' ) ?: 4 ) );

$blueprint = get_page_by_path( $slug, OBJECT, 'blueprint' );
if ( ! $blueprint ) {
	throw new RuntimeException( sprintf( 'No published Blueprint at %s.', $slug ) );
}

wp_set_current_user( 1 );

/**
 * Each revision makes one legible change, so the diff between any two reads
 * as a decision somebody made rather than noise.
 */
$edits = array(
	array(
		'note'  => 'Reads well. Publishing.',
		'apply' => static function ( array $data ) {
			$data['siteOptions']['blogdescription'] = 'Notes on design, typography, and the web';
			return $data;
		},
	),
	array(
		'note'  => 'Good — the front page was too long.',
		'apply' => static function ( array $data ) {
			$data['siteOptions']['posts_per_page'] = '8';
			return $data;
		},
	),
	array(
		'note'  => 'Comments off makes sense for this one.',
		'apply' => static function ( array $data ) {
			$data['siteOptions']['default_comment_status'] = 'closed';
			$data['siteOptions']['default_ping_status']    = 'closed';
			return $data;
		},
	),
	array(
		'note'  => 'Pinning the versions is the right call.',
		'apply' => static function ( array $data ) {
			$data['preferredVersions'] = array( 'php' => '8.3', 'wp' => 'latest' );
			return $data;
		},
	),
	array(
		'note'  => 'Nice, the date format matches the theme now.',
		'apply' => static function ( array $data ) {
			$data['siteOptions']['date_format'] = 'j F Y';
			$data['siteOptions']['start_of_week'] = '1';
			return $data;
		},
	),
);

for ( $i = 0; $i < $count; $i++ ) {
	$edit       = $edits[ $i % count( $edits ) ];
	$release_id = (int) get_post_meta( $blueprint->ID, '_bp_current_release_id', true );
	$number     = (int) get_post_meta( $release_id, '_bp_release_number', true );

	$change = Blueprint_Registry_Workflow::create_change(
		array(
			'title'      => get_the_title( $blueprint ),
			'author_id'  => 1,
			'target_id'  => $blueprint->ID,
			'release_id' => $release_id,
		)
	);

	if ( is_wp_error( $change ) ) {
		throw new RuntimeException( 'Could not start a proposal: ' . $change->get_error_message() );
	}

	$data = json_decode( Blueprint_Registry_Workflow::source( $change ), true );
	if ( ! is_array( $data ) ) {
		throw new RuntimeException( 'The current revision is not readable JSON.' );
	}

	Blueprint_Registry_Workflow::set_source(
		$change,
		wp_json_encode( call_user_func( $edit['apply'], $data ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"
	);

	$submitted = Blueprint_Registry_Workflow::submit( $change, 1 );
	if ( is_wp_error( $submitted ) ) {
		throw new RuntimeException( 'Could not submit: ' . $submitted->get_error_message() );
	}

	$released = Blueprint_Registry_Workflow::review( $change, 'approved', 1, $edit['note'] );
	if ( is_wp_error( $released ) ) {
		throw new RuntimeException( 'Could not publish: ' . $released->get_error_message() );
	}

	fwrite( STDOUT, sprintf( "Published revision %d of %s.\n", $number + 1, $slug ) );
}
