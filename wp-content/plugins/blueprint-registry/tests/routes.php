<?php

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this file through wp eval-file.\n" );
	exit( 1 );
}

$assert = static function ( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$posts = array();
$users = array();
try {
	wp_set_current_user( 1 );
	$rules = get_option( 'rewrite_rules' );
	$assert( isset( $rules['^blueprints/manage/?$'] ), 'The front-end Blueprint dashboard route must be registered.' );
	$assert( isset( $rules['^blueprints/manage/new/?$'] ), 'The unsaved front-end Blueprint editor route must be registered.' );
	$assert( isset( $rules['^blueprints/manage/([0-9]+)/?$'] ), 'The front-end Blueprint editor route must be registered.' );
	$assert( isset( $rules['^blueprints/manage/([0-9]+)/review/?$'] ), 'The front-end pre-submit review route must be registered.' );
	$assert( isset( $rules['^blueprints/manage/review/?$'] ), 'The legacy review route must remain available for redirects.' );
	$assert( isset( $rules['^blueprints/([^/]+)/releases/([0-9]+)/files/(.+)/?$'] ), 'Published bundle files must have a browsable immutable route.' );
	$assert( str_starts_with( Blueprint_Registry_Frontend::dashboard_url(), home_url( '/blueprints/manage/' ) ), 'The front-end management URL must use the public website path.' );
	$assert( str_starts_with( Blueprint_Registry_Frontend::new_editor_url(), home_url( '/blueprints/manage/new/' ) ), 'The unsaved front-end editor must use the public website path.' );
	$assert( str_starts_with( Blueprint_Registry_Frontend::submission_review_url( 123 ), home_url( '/blueprints/manage/123/review/' ) ), 'The pre-submit review URL must use the public website path.' );
	$assert( str_starts_with( Blueprint_Registry_Admin::review_queue_url(), admin_url( 'admin.php?page=blueprint-review-queue' ) ), 'Reviewers must use the wp-admin review queue.' );
	$archive_template = (string) file_get_contents( BLUEPRINT_REGISTRY_DIR . 'templates/archive-blueprint.php' );
	$single_template  = (string) file_get_contents( BLUEPRINT_REGISTRY_DIR . 'templates/single-blueprint.php' );
	$assert( str_contains( $archive_template, 'Propose a new Blueprint' ) && str_contains( $archive_template, 'Blueprint_Registry_Frontend::new_editor_url' ), 'The gallery must offer a direct route into the new Blueprint contribution flow.' );
	$assert( str_contains( $archive_template, "'Details'" ) && str_contains( $archive_template, "'Run'" ), 'Public gallery entries must expose separate Details and Run actions.' );
	$assert( ! str_contains( $archive_template, 'bpv__card-media" href' ), 'A gallery card must not repeat its link on the thumbnail; the title link covers the card.' );
	$assert( str_contains( $archive_template, 'bpv__grid' ) && str_contains( $archive_template, 'bpv__table' ), 'The gallery must offer both a grid and a table layout.' );
	$assert( str_contains( $archive_template, 'bp_q' ) && str_contains( $archive_template, 'bp_category' ), 'The gallery must be searchable and filterable by category.' );
	$assert( str_contains( $single_template, 'bp-detail__preview' ) && str_contains( $single_template, 'bp-row-actions' ), 'Blueprint detail pages must show the preview and aligned file actions.' );
	$assert( str_contains( $single_template, "'Download'" ) && ! str_contains( $single_template, 'Download bundle' ), 'Blueprint pages must label the bundle download action simply Download.' );
	$assert( str_contains( $single_template, "'Edit'" ) && str_contains( $single_template, '$can_edit' ), 'Blueprint pages must offer Edit only to those allowed to edit.' );
	$assert( str_starts_with( Blueprint_Registry_Admin::review_url( 123 ), admin_url( 'edit.php?post_type=blueprint' ) ), 'Reviewers must reach a proposal through the dedicated review screen.' );
	$change = Blueprint_Registry_Workflow::create_change( array( 'title' => 'Route test ' . wp_generate_password( 8, false ), 'author_id' => 1 ) );
	$posts[] = $change;
	$assert( true === Blueprint_Registry_Workflow::submit( $change, 1 ), 'The route test change must submit.' );
	$release = Blueprint_Registry_Workflow::review( $change, 'approved', 1 );
	$posts[] = $release;
	$blueprint = (int) get_post_meta( $change, '_bp_target_blueprint_id', true );
	$posts[] = $blueprint;
	$number = (int) get_post_meta( $release, '_bp_release_number', true );
	$bundle_id = (int) get_post_meta( $release, '_bp_bundle_attachment_id', true );
	$other_contributor = wp_insert_user(
		array(
			'user_login' => 'route-visitor-' . wp_generate_password( 8, false ),
			'user_pass'  => 'password',
			'user_email' => wp_generate_password( 8, false ) . '@example.test',
			'role'       => 'subscriber',
		)
	);
	$assert( ! is_wp_error( $other_contributor ), 'The non-author contributor must be created.' );
	$users[] = $other_contributor;
	$previous_get     = $_GET;
	$previous_request = $_REQUEST;
	$redirect         = '';
	$stop_redirect    = static function ( $location ) use ( &$redirect ) {
		$redirect = $location;
		throw new RuntimeException( 'stop-update-redirect' );
	};
	wp_set_current_user( $other_contributor );
	$_GET = array(
		'blueprint_id' => $blueprint,
		'mode'         => 'update',
		'_wpnonce'      => wp_create_nonce( 'bp_front_create_from_release_' . $blueprint ),
	);
	$_REQUEST = $_GET;
	$create_from_release = new ReflectionMethod( Blueprint_Registry_Frontend::class, 'create_from_release' );
	$create_from_release->setAccessible( true );
	add_filter( 'wp_redirect', $stop_redirect, -PHP_INT_MAX );
	try {
		$create_from_release->invoke( new Blueprint_Registry_Frontend() );
	} catch ( RuntimeException $error ) {
		if ( 'stop-update-redirect' !== $error->getMessage() ) {
			throw $error;
		}
	} finally {
		remove_filter( 'wp_redirect', $stop_redirect, -PHP_INT_MAX );
		$_GET     = $previous_get;
		$_REQUEST = $previous_request;
	}
	$assert( str_contains( $redirect, 'bp_notice=error' ), 'A contributor who did not create the Blueprint must not start an edit proposal.' );

	// A reviewer may edit any Blueprint; an ordinary contributor may only edit
	// their own, and sees nothing on someone else's.
	$assert( ! Blueprint_Registry_Capabilities::can_edit_blueprint( $blueprint ), 'A contributor must not be offered Edit on a Blueprint they did not create.' );
	wp_set_current_user( 1 );
	$assert( Blueprint_Registry_Capabilities::can_edit_blueprint( $blueprint ), 'The Blueprint author must be offered Edit.' );
	$reviewer = wp_insert_user(
		array(
			'user_login' => 'route-reviewer-' . wp_generate_password( 8, false ),
			'user_pass'  => 'password',
			'user_email' => wp_generate_password( 8, false ) . '@example.test',
			'role'       => 'administrator',
		)
	);
	$assert( ! is_wp_error( $reviewer ), 'The reviewer must be created.' );
	$users[] = $reviewer;
	wp_set_current_user( $reviewer );
	$assert( Blueprint_Registry_Capabilities::can_edit_blueprint( $blueprint ), 'A reviewer must be offered Edit on any Blueprint.' );
	wp_set_current_user( 1 );

	do_action( 'rest_api_init' );
	$server = rest_get_server();
	$list_response = $server->dispatch( new WP_REST_Request( 'GET', '/blueprints/v1/blueprints' ) );
	$assert( 200 === $list_response->get_status(), 'The public gallery REST route must respond.' );
	$list = $list_response->get_data();
	$assert( ! empty( array_filter( $list, static fn( $item ) => $item['id'] === $blueprint ) ), 'The public gallery REST route must include released Blueprints.' );

	$single_response = $server->dispatch( new WP_REST_Request( 'GET', '/blueprints/v1/blueprints/' . get_post_field( 'post_name', $blueprint ) ) );
	$single = $single_response->get_data();
	$assert( 200 === $single_response->get_status(), 'The public single Blueprint REST route must respond.' );
	$assert( $number === $single['current_release'], 'The REST route must expose the current immutable release number.' );
	$assert( 1 === count( $single['releases'] ), 'The REST route must expose releases.' );
	$assert( Blueprint_Registry_Routes::release_url( $blueprint, $number, 'bundle' ) === $single['bundle_url'], 'The REST route must use the permanent release bundle URL.' );
	$assert( is_readable( get_attached_file( $bundle_id ) ), 'The route test release must retain its bundle.' );

	fwrite( STDOUT, "PASS routes: public gallery and immutable release metadata.\n" );
} catch ( Throwable $error ) {
	fwrite( STDERR, "FAIL routes: " . $error->getMessage() . "\n" );
	throw $error;
} finally {
	$submission_posts = array();
	foreach ( array_unique( $posts ) as $post_id ) {
		if ( 'blueprint_change' !== get_post_type( $post_id ) ) {
			continue;
		}
		$submissions = get_posts(
			array(
				'post_type'      => 'blueprint_submission',
				'post_parent'    => $post_id,
				'post_status'    => 'any',
				'posts_per_page' => -1,
			)
		);
		foreach ( $submissions as $submission ) {
			foreach ( Blueprint_Registry_Bundles::get_submission_files( $submission->ID ) as $file ) {
				wp_delete_attachment( (int) $file['attachment_id'], true );
			}
			$submission_posts[] = $submission->ID;
		}
	}
	if ( isset( $bundle_id ) ) {
		wp_delete_attachment( $bundle_id, true );
	}
	foreach ( array_unique( $submission_posts ) as $submission_id ) {
		wp_delete_post( $submission_id, true );
	}
	foreach ( array_unique( $posts ) as $post_id ) {
		if ( $post_id ) {
			wp_delete_post( $post_id, true );
		}
	}
	foreach ( array_unique( $users ) as $user_id ) {
		if ( $user_id && ! is_wp_error( $user_id ) ) {
			wp_delete_user( $user_id );
		}
	}
}
