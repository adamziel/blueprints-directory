<?php

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this file through wp eval-file.\n" );
	exit( 1 );
}

$created_posts       = array();
$created_attachments = array();
$created_comments    = array();
$created_users       = array();

$assert = static function ( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

// Bundle files live in private storage now; write contents straight in.
$add_file = static function ( $change_id, $path, $contents ) {
	$record = Blueprint_Registry_Bundles::add_change_file( $change_id, $contents, $path );
	if ( is_wp_error( $record ) ) {
		throw new RuntimeException( $record->get_error_message() );
	}

	return $record;
};

try {
	$author_id = wp_insert_user(
		array(
			'user_login' => 'contributor-flow-' . wp_generate_password( 8, false ),
			'user_pass'  => 'password',
			'user_email' => wp_generate_password( 8, false ) . '@example.test',
			'role'       => 'subscriber',
		)
	);
	$assert( ! is_wp_error( $author_id ), 'The contributor must be created.' );
	$created_users[] = $author_id;
	wp_set_current_user( $author_id );

	$untitled_change = Blueprint_Registry_Workflow::create_change( array( 'author_id' => $author_id ) );
	$assert( ! is_wp_error( $untitled_change ), 'The one-click create action must create a draft.' );
	$created_posts[] = $untitled_change;
	$assert( 'Untitled Blueprint' === get_the_title( $untitled_change ), 'The one-click create action must open a draft with the default title.' );
	$render_dashboard = new ReflectionMethod( Blueprint_Registry_Frontend::class, 'render_dashboard' );
	$render_dashboard->setAccessible( true );
	ob_start();
	$render_dashboard->invoke( null );
	$dashboard = ob_get_clean();
	$assert( 1 === substr_count( $dashboard, esc_url( Blueprint_Registry_Frontend::new_editor_url() ) ) && ! str_contains( $dashboard, 'bp_new_title' ), 'The dashboard must offer one route to a new Blueprint, with no title field.' );
	$assert( str_contains( $dashboard, 'bpv__table' ) && str_contains( $dashboard, 'bpv__thumb-letter' ), 'The dashboard must list work in a table and fall back to a letter when a draft has no thumbnail.' );
	$assert( str_contains( $dashboard, 'bp-segments' ), 'The dashboard must let a contributor filter by status.' );
	$assert( ! str_contains( $dashboard, '>Preview<' ), 'The dashboard cards must have one edit action instead of a separate preview action.' );
	$assert( strpos( $dashboard, 'bp-btn--quiet-danger' ) < strpos( $dashboard, 'Continue' ), 'A draft row must put Remove before the action that continues it.' );
	$add_file( $untitled_change, 'readme.html', '<script>alert("no");</script>' );
	ob_start();
	Blueprint_Registry_Diff::render_change( $untitled_change );
	$new_contents = ob_get_clean();
	$assert( str_contains( $new_contents, 'blueprint.json' ) && str_contains( $new_contents, 'Untitled Blueprint' ), 'New Blueprint review must show its JSON, not an empty comparison.' );
	$assert( str_contains( $new_contents, '&lt;script&gt;' ) && ! str_contains( $new_contents, '<script>' ), 'Bundled markup must be readable without executing it.' );
	$changes_before_new_editor = get_posts(
		array(
			'post_type'      => 'blueprint_change',
			'post_status'    => 'draft',
			'author'         => $author_id,
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);
	$render_new_editor = new ReflectionMethod( Blueprint_Registry_Frontend::class, 'render_new_editor' );
	$render_new_editor->setAccessible( true );
	global $wp_query;
	$previous_wp_query = $wp_query ?? null;
	if ( ! isset( $wp_query ) || ! $wp_query instanceof WP_Query ) {
		$wp_query = new WP_Query();
	}
	$previous_query_vars = $wp_query->query_vars;
	$wp_query->query_vars['bp_source_blueprint_id'] = 0;
	$wp_query->query_vars['bp_new_mode']            = 'new';
	ob_start();
	$render_new_editor->invoke( null );
	$new_editor = ob_get_clean();
	$wp_query->query_vars = $previous_query_vars;
	$wp_query             = $previous_wp_query;
	$assert( str_contains( $new_editor, 'Review changes' ) && $changes_before_new_editor === get_posts( array( 'post_type' => 'blueprint_change', 'post_status' => 'draft', 'author' => $author_id, 'posts_per_page' => -1, 'fields' => 'ids' ) ), 'Opening the new editor must not create a draft.' );
	$assert(
		str_contains( $new_editor, 'data-bp-bundle-source' )
		&& str_contains( $new_editor, 'data-bp-bundle-archive' )
		&& ! str_contains( $new_editor, 'name="bp_bundle_source"' ),
		'The editor must submit only its browser-created ZIP, never the selected raw files.'
	);

	$previous_post    = $_POST;
	$previous_request = $_REQUEST;
	$previous_files   = $_FILES;
	$previous_method  = $_SERVER['REQUEST_METHOD'] ?? null;
	$redirect         = '';
	$stop_redirect    = static function ( $location ) use ( &$redirect ) {
		$redirect = $location;
		throw new RuntimeException( 'stop-new-editor-redirect' );
	};
	$meaningful_source = Blueprint_Registry_Workflow::default_source( 'Created after review', get_userdata( $author_id )->user_login );
	$_POST = array(
		'bp_source_blueprint_id' => 0,
		'bp_new_mode'            => 'new',
		'bp_blueprint_json'      => $meaningful_source,
		'_wpnonce'               => wp_create_nonce( 'bp_front_create_new_0_new' ),
	);
	$_REQUEST                  = $_POST;
	$_FILES                    = array();
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$create_new_change = new ReflectionMethod( Blueprint_Registry_Frontend::class, 'create_new_change' );
	$create_new_change->setAccessible( true );
	add_filter( 'wp_redirect', $stop_redirect, -PHP_INT_MAX );
	try {
		$create_new_change->invoke( new Blueprint_Registry_Frontend() );
	} catch ( RuntimeException $error ) {
		if ( 'stop-new-editor-redirect' !== $error->getMessage() ) {
			throw $error;
		}
	} finally {
		remove_filter( 'wp_redirect', $stop_redirect, -PHP_INT_MAX );
		$_POST    = $previous_post;
		$_REQUEST = $previous_request;
		$_FILES   = $previous_files;
		if ( null === $previous_method ) {
			unset( $_SERVER['REQUEST_METHOD'] );
		} else {
			$_SERVER['REQUEST_METHOD'] = $previous_method;
		}
	}
	$changes_after_new_editor = get_posts(
		array(
			'post_type'      => 'blueprint_change',
			'post_status'    => 'draft',
			'author'         => $author_id,
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);
	$created_after_review = array_values( array_diff( $changes_after_new_editor, $changes_before_new_editor ) );
	$assert( 1 === count( $created_after_review ) && str_contains( $redirect, '/blueprints/manage/' . $created_after_review[0] . '/review/' ), 'Reviewing an unsaved Blueprint must create one draft and open the pre-submit review step.' );
	$created_posts[] = $created_after_review[0];

	$previous_post    = $_POST;
	$previous_request = $_REQUEST;
	$previous_method  = $_SERVER['REQUEST_METHOD'] ?? null;
	$redirect         = '';
	$_POST = array(
		'bp_action' => 'delete_draft',
		'change_id' => $created_after_review[0],
		'_wpnonce'  => wp_create_nonce( 'bp_front_delete_' . $created_after_review[0] ),
	);
	$_REQUEST                  = $_POST;
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$delete_draft = new ReflectionMethod( Blueprint_Registry_Frontend::class, 'delete_draft' );
	$delete_draft->setAccessible( true );
	add_filter( 'wp_redirect', $stop_redirect, -PHP_INT_MAX );
	try {
		$delete_draft->invoke( new Blueprint_Registry_Frontend() );
	} catch ( RuntimeException $error ) {
		if ( 'stop-new-editor-redirect' !== $error->getMessage() ) {
			throw $error;
		}
	} finally {
		remove_filter( 'wp_redirect', $stop_redirect, -PHP_INT_MAX );
		$_POST    = $previous_post;
		$_REQUEST = $previous_request;
		if ( null === $previous_method ) {
			unset( $_SERVER['REQUEST_METHOD'] );
		} else {
			$_SERVER['REQUEST_METHOD'] = $previous_method;
		}
	}
	$assert( ! get_post( $created_after_review[0] ) && str_contains( $redirect, 'bp_notice=success' ), 'The contributor must be able to remove an unsubmitted draft from My Blueprints.' );

	$source_change = Blueprint_Registry_Workflow::create_change(
		array(
			'title'     => 'Public history ' . wp_generate_password( 8, false ),
			'author_id' => $author_id,
		)
	);
	$assert( ! is_wp_error( $source_change ), 'The first proposal must be created.' );
	$created_posts[] = $source_change;
	$assert( ! is_wp_error( $add_file( $source_change, 'content/first.txt', "first version  \n" ) ), 'The first bundle file must be added.' );
	Blueprint_Registry_Workflow::set_source(
		$source_change,
		wp_json_encode(
			array(
				'$schema' => Blueprint_Registry_Validator::SCHEMA_URL,
				'steps'   => array(
					array(
						'step' => 'writeFile',
						'path' => '/wordpress/first.txt',
						'data' => array( 'resource' => 'bundled', 'path' => '/content/first.txt' ),
					),
				),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		)
	);
	$assert( true === Blueprint_Registry_Workflow::submit( $source_change, $author_id ), 'The first proposal must submit.' );
	wp_set_current_user( 1 );
	$release_one = Blueprint_Registry_Workflow::review( $source_change, 'approved', 1, 'Published the first version.' );
	$assert( ! is_wp_error( $release_one ), 'The first proposal must become a public release.' );
	$created_posts[]       = $release_one;
	$created_attachments[] = (int) get_post_meta( $release_one, '_bp_bundle_attachment_id', true );
	$blueprint_id          = (int) get_post_meta( $source_change, '_bp_target_blueprint_id', true );
	$created_posts[]       = $blueprint_id;

	wp_set_current_user( $author_id );
	ob_start();
	$render_dashboard->invoke( null );
	$dashboard = ob_get_clean();
	$assert( str_contains( $dashboard, 'bp-chip--published' ) && ! str_contains( $dashboard, 'bp-chip--accepted' ), 'A Blueprint with no active proposal must be shown as published, not as an accepted proposal.' );
	$update = Blueprint_Registry_Workflow::create_change(
		array(
			'title'     => get_the_title( $blueprint_id ),
			'author_id' => $author_id,
			'target_id' => $blueprint_id,
			'change_type' => 'update',
		)
	);
	$assert( ! is_wp_error( $update ), 'The update proposal must be created.' );
	$created_posts[] = $update;
	$copied_files = Blueprint_Registry_Bundles::get_change_files( $update );
	$assert( 1 === count( $copied_files ), 'The update must start with the previous bundle file.' );
	// Replace the inherited file's contents, the way the editor does.
	Blueprint_Registry_Bundles::remove_change_file( $update, $copied_files[0]['key'] );
	$assert( ! is_wp_error( $add_file( $update, 'content/first.txt', "first version \n" ) ), 'The inherited bundle file must be replaceable.' );
	$assert( ! is_wp_error( $add_file( $update, 'content/second.txt', "new file\n" ) ), 'A new bundle file must be added to the update.' );
	Blueprint_Registry_Workflow::set_source(
		$update,
		wp_json_encode(
			array(
				'$schema' => Blueprint_Registry_Validator::SCHEMA_URL,
				'steps'   => array(
					array(
						'step' => 'writeFile',
						'path' => '/wordpress/first.txt',
						'data' => array( 'resource' => 'bundled', 'path' => '/content/first.txt' ),
					),
					array(
						'step' => 'writeFile',
						'path' => '/wordpress/second.txt',
						'data' => array( 'resource' => 'bundled', 'path' => '/content/second.txt' ),
					),
				),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		)
	);
	$older_update = Blueprint_Registry_Workflow::create_change(
		array(
			'title'       => get_the_title( $blueprint_id ),
			'author_id'   => $author_id,
			'target_id'   => $blueprint_id,
			'change_type' => 'update',
		)
	);
	$assert( ! is_wp_error( $older_update ), 'A second draft for the same Blueprint must be created for the dashboard history check.' );
	$created_posts[] = $older_update;
	foreach ( Blueprint_Registry_Bundles::get_change_files( $older_update ) as $file_entry ) {
	}
	ob_start();
	$render_dashboard->invoke( null );
	$dashboard = ob_get_clean();
	$assert( 1 === substr_count( $dashboard, 'data-bp-blueprint-id="' . $blueprint_id . '"' ), 'The dashboard must show one card for a published Blueprint, even when it has several proposals.' );
	$assert( str_contains( $dashboard, Blueprint_Registry_Frontend::edit_url( $older_update ) ), 'The dashboard must link a Blueprint card to its most recent active proposal.' );
	// Forking inherits the current revision's files; the editor must show them
	// rather than promise them.
	$render_new_editor = new ReflectionMethod( Blueprint_Registry_Frontend::class, 'render_new_editor' );
	$render_new_editor->setAccessible( true );
	$previous_query_vars = array( get_query_var( 'bp_source_blueprint_id' ), get_query_var( 'bp_new_mode' ) );
	set_query_var( 'bp_source_blueprint_id', $blueprint_id );
	set_query_var( 'bp_new_mode', 'fork' );
	ob_start();
	$render_new_editor->invoke( null );
	$fork_editor = ob_get_clean();
	set_query_var( 'bp_source_blueprint_id', $previous_query_vars[0] );
	set_query_var( 'bp_new_mode', $previous_query_vars[1] );
	$assert( str_contains( $fork_editor, 'content/first.txt' ), 'The fork editor must list the bundle files it inherits.' );
	$assert( str_contains( $fork_editor, 'Included from revision' ), 'The fork editor must say where the inherited files come from.' );

	$render_editor = new ReflectionMethod( Blueprint_Registry_Frontend::class, 'render_editor' );
	$render_editor->setAccessible( true );
	ob_start();
	$render_editor->invoke( null, $update );
	$editor = ob_get_clean();
	$assert( 1 === substr_count( $editor, 'name="bp_action" value="save_and_review"' ) && ! str_contains( $editor, 'name="bp_action" value="save"' ), 'The editor must have one review action, with no competing save field.' );
	$assert( str_contains( $editor, 'Review changes' ) && ! str_contains( $editor, 'Save draft' ) && ! str_contains( $editor, '>Preview<' ), 'The editable proposal screen must offer Review changes without duplicate save or preview actions.' );
	$assert( strpos( $editor, 'bp-btn--quiet-danger' ) < strpos( $editor, 'Review changes' ), 'A draft editor must put the destructive Remove action before Review changes.' );
	$assert( str_contains( $editor, Blueprint_Registry_Routes::release_detail_url( $blueprint_id, 1 ) ) && str_contains( $editor, Blueprint_Registry_Frontend::edit_url( $older_update ) ), 'The editor must provide access to the published release and earlier proposals.' );
	$assert( str_contains( $editor, 'action=bp_bundle_file' ) && str_contains( $editor, 'download=1' ), 'Contributor bundle files must have open and download links.' );
	$render_submission_review = new ReflectionMethod( Blueprint_Registry_Frontend::class, 'render_submission_review' );
	$render_submission_review->setAccessible( true );
	ob_start();
	$render_submission_review->invoke( null, $update );
	$submission_review = ob_get_clean();
	$assert( ! str_contains( $submission_review, 'delete_draft' ) && str_contains( $submission_review, 'bp_reviewed_content' ), 'The review step must confirm specific contents without competing draft actions.' );
	$diff = Blueprint_Registry_Bundles::diff_release_to_change( $release_one, $update );
	$diff_by_path = array_column( $diff, null, 'path' );
	$assert( 'changed' === $diff_by_path['blueprint.json']['status'], 'The Blueprint JSON diff must identify a changed declaration.' );
	$assert( 'changed' === $diff_by_path['content/first.txt']['status'], 'The bundle diff must identify changed text resources.' );
	$assert( 'added' === $diff_by_path['content/second.txt']['status'], 'The bundle diff must identify added resources.' );
	$text_pair = Blueprint_Registry_Bundles::text_file_pair( $release_one, $update, 'content/first.txt' );
	$assert( is_array( $text_pair ) && "first version  \n" === $text_pair['base'] && "first version \n" === $text_pair['proposal'], 'The text-file diff must retain both file versions.' );
	ob_start();
	Blueprint_Registry_Diff::render_change( $update, __( 'Changes from the base release', 'blueprint-registry' ) );
	$rendered_diff = ob_get_clean();
	$assert( str_contains( $rendered_diff, 'content/second.txt' ) && str_contains( $rendered_diff, 'content/first.txt' ), 'The shared reviewer diff must show bundle-file changes.' );
	$assert( str_contains( $rendered_diff, 'bp-diff__badge--added' ) && str_contains( $rendered_diff, 'bp-diff__badge--changed' ), 'The shared reviewer diff must label each file as added or changed.' );
	$assert( str_contains( $rendered_diff, 'files changed' ), 'The shared reviewer diff must summarise how much moved.' );
	$assert( str_contains( $rendered_diff, 'diff-deletedline' ) && str_contains( $rendered_diff, 'diff-addedline' ), 'The shared reviewer diff must show actual removed and added lines.' );
	$assert( 3 <= substr_count( $rendered_diff, '·' ), 'The shared reviewer diff must make trailing spaces visible.' );
	$assert( 2 <= substr_count( $rendered_diff, "class='diff'" ), 'The shared reviewer diff must include a readable text diff for changed text files as well as the declaration.' );
	$assert( str_contains( $rendered_diff, 'action=bp_bundle_file' ) && str_contains( $rendered_diff, 'download=1' ), 'Changed bundle files in the review step must have open and download links.' );

	$entries = Blueprint_Registry_Bundles::release_file_entries( $release_one );
	$entry_paths = wp_list_pluck( $entries, 'path' );
	$assert( in_array( 'blueprint.json', $entry_paths, true ) && in_array( 'content/first.txt', $entry_paths, true ), 'Public release files must include the declaration and bundled resources.' );
	$file = Blueprint_Registry_Bundles::release_file_contents( $release_one, 'content/first.txt' );
	$assert( "first version  \n" === $file, 'Public file browsing must read the immutable release resource.' );
	$slug = get_post_field( 'post_name', $blueprint_id );
	$release_url = Blueprint_Registry_Routes::release_detail_url( $blueprint_id, 1 );
	$file_url    = Blueprint_Registry_Routes::release_file_url( $blueprint_id, 1, 'content/first.txt' );
	$assert( str_contains( $release_url, '/blueprints/' . $slug . '/?release=1' ), 'The public release-history link must select a previous version.' );
	$assert( str_contains( $file_url, '/releases/1/files/content/first.txt' ), 'The public file browser link must use the immutable release path.' );

	$assert( true === Blueprint_Registry_Workflow::submit( $update, $author_id ), 'The changed update must submit.' );
	wp_set_current_user( 1 );
	$release_two = Blueprint_Registry_Workflow::review( $update, 'approved', 1, 'Second version is ready.' );
	$assert( ! is_wp_error( $release_two ), 'The update must be accepted.' );
	$created_posts[]       = $release_two;
	$created_attachments[] = (int) get_post_meta( $release_two, '_bp_bundle_attachment_id', true );

	wp_set_current_user( $author_id );
	$stale = Blueprint_Registry_Workflow::create_change(
		array(
			'title'     => get_the_title( $blueprint_id ),
			'author_id' => $author_id,
			'target_id' => $blueprint_id,
			'change_type' => 'update',
		)
	);
	$assert( ! is_wp_error( $stale ), 'A later update proposal must be created.' );
	$created_posts[] = $stale;
	foreach ( Blueprint_Registry_Bundles::get_change_files( $stale ) as $file_entry ) {
	}
	$stale_base = (int) get_post_meta( $stale, '_bp_base_release_id', true );

	$parallel = Blueprint_Registry_Workflow::create_change(
		array(
			'title'     => get_the_title( $blueprint_id ),
			'author_id' => $author_id,
			'target_id' => $blueprint_id,
			'change_type' => 'update',
		)
	);
	$assert( ! is_wp_error( $parallel ), 'A parallel proposal must be created.' );
	$created_posts[] = $parallel;
	foreach ( Blueprint_Registry_Bundles::get_change_files( $parallel ) as $file_entry ) {
	}
	$assert( true === Blueprint_Registry_Workflow::submit( $parallel, $author_id ), 'The parallel update must submit.' );
	wp_set_current_user( 1 );
	$release_three = Blueprint_Registry_Workflow::review( $parallel, 'approved', 1, 'Third version is ready.' );
	$assert( ! is_wp_error( $release_three ), 'The parallel update must create a new release.' );
	$created_posts[]       = $release_three;
	$created_attachments[] = (int) get_post_meta( $release_three, '_bp_bundle_attachment_id', true );

	wp_set_current_user( $author_id );
	$assert( true === Blueprint_Registry_Workflow::submit( $stale, $author_id ), 'The stale proposal must enter review.' );
	wp_set_current_user( 1 );
	$stale_result = Blueprint_Registry_Workflow::review( $stale, 'approved', 1, 'Review the new base first.' );
	$assert( is_wp_error( $stale_result ) && 'blueprint_stale_change' === $stale_result->get_error_code(), 'A stale proposal must be stopped instead of overwriting a newer release.' );
	$messages = Blueprint_Registry_Workflow::review_messages( $stale );
	foreach ( $messages as $message ) {
		$created_comments[] = (int) $message->comment_ID;
	}
	$assert( 'changes_requested' === get_post_meta( $stale, '_bp_status', true ), 'A stale proposal must return to its contributor with reviewer feedback.' );
	wp_set_current_user( $author_id );
	$new_base = Blueprint_Registry_Workflow::refresh_base_release( $stale, $author_id );
	$assert( $release_three === $new_base, 'A contributor must be able to compare a returned update with the current release.' );
	$assert( $stale_base !== (int) get_post_meta( $stale, '_bp_base_release_id', true ), 'Refreshing the base must advance the proposal base-release pointer.' );
	$assert( true === Blueprint_Registry_Workflow::submit( $stale, $author_id ), 'A refreshed proposal must be submittable again.' );

	$fork = Blueprint_Registry_Workflow::create_change(
		array(
			'title'               => 'Contributor fork ' . wp_generate_password( 8, false ),
			'author_id'           => $author_id,
			'source_blueprint_id' => $blueprint_id,
			'change_type'         => 'fork',
		)
	);
	$assert( ! is_wp_error( $fork ), 'A directory visitor must be able to create a fork proposal.' );
	$created_posts[] = $fork;
	$assert( 0 === (int) get_post_meta( $fork, '_bp_target_blueprint_id', true ) && $blueprint_id === (int) get_post_meta( $fork, '_bp_source_blueprint_id', true ), 'A fork must be an independent proposal that records its source Blueprint.' );
	$assert( $author_id === (int) get_post_field( 'post_author', $fork ), 'A fork proposal must belong to the contributor who made it.' );
	foreach ( Blueprint_Registry_Bundles::get_change_files( $fork ) as $file_entry ) {
	}

	do_action( 'rest_api_init' );
	$server = rest_get_server();
	$response = $server->dispatch( new WP_REST_Request( 'GET', '/blueprints/v1/blueprints/' . $slug ) );
	$data = $response->get_data();
	$assert( 200 === $response->get_status() && 3 === count( $data['releases'] ), 'The public API must list every immutable release.' );
	$assert( isset( $data['releases'][0]['files'] ) && in_array( 'content/first.txt', wp_list_pluck( $data['releases'][0]['files'], 'path' ), true ), 'The public API must expose files for browsing.' );

	$removed_file_change = Blueprint_Registry_Workflow::create_change( array( 'author_id' => $author_id, 'target_id' => $blueprint_id, 'change_type' => 'update' ) );
	$created_posts[] = $removed_file_change;
	foreach ( Blueprint_Registry_Bundles::get_change_files( $removed_file_change ) as $file ) {
		Blueprint_Registry_Bundles::remove_change_file( $removed_file_change, $file['key'] );
	}
	$removed_pair = Blueprint_Registry_Bundles::text_file_pair( $release_one, $removed_file_change, 'content/first.txt' );
	$assert( is_array( $removed_pair ) && "first version  \n" === $removed_pair['base'] && '' === $removed_pair['proposal'], 'A removed text file must keep its old contents for the diff.' );
	$added_pair = Blueprint_Registry_Bundles::text_file_pair( $release_one, $update, 'content/second.txt' );
	$assert( is_array( $added_pair ) && '' === $added_pair['base'] && "new file\n" === $added_pair['proposal'], 'An added text file must show its complete new contents in the diff.' );

	fwrite( STDOUT, "PASS contribution workflow: proposal comparison, feedback, rebasing, history, files, and forks.\n" );
} catch ( Throwable $error ) {
	fwrite( STDERR, "FAIL contribution workflow: " . $error->getMessage() . "\n" );
	throw $error;
} finally {
	$submission_posts = array();
	foreach ( array_unique( $created_posts ) as $post_id ) {
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
			}
			$submission_posts[] = $submission->ID;
		}
	}
	foreach ( array_unique( $created_comments ) as $comment_id ) {
		if ( $comment_id ) {
			wp_delete_comment( $comment_id, true );
		}
	}
	foreach ( array_unique( $created_attachments ) as $attachment_id ) {
		if ( $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}
	}
	foreach ( array_unique( $submission_posts ) as $submission_id ) {
		wp_delete_post( $submission_id, true );
	}
	foreach ( array_unique( $created_posts ) as $post_id ) {
		if ( $post_id ) {
			wp_delete_post( $post_id, true );
		}
	}
	foreach ( array_unique( $created_users ) as $user_id ) {
		if ( $user_id && ! is_wp_error( $user_id ) ) {
			wp_delete_user( $user_id );
		}
	}
}
