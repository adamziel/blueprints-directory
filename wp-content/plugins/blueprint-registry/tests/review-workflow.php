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

$source_for = static function ( $title, $marker ) {
	return wp_json_encode(
		array(
			'$schema' => Blueprint_Registry_Validator::SCHEMA_URL,
			'meta'    => array( 'title' => $title, 'description' => $marker ),
			'steps'   => array(),
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . "\n";
};

// Bundle files live in private storage now; write contents straight in.
$add_file = static function ( $change_id, $path, $contents ) {
	$record = Blueprint_Registry_Bundles::add_change_file( $change_id, $contents, $path );
	if ( is_wp_error( $record ) ) {
		throw new RuntimeException( $record->get_error_message() );
	}

	return $record;
};

// Editing a bundle file is remove-then-add, the same as a contributor replacing
// one in the editor.
$set_file = static function ( $change_id, $path, $contents ) {
	foreach ( Blueprint_Registry_Bundles::get_change_files( $change_id ) as $file ) {
		Blueprint_Registry_Bundles::remove_change_file( $change_id, $file['key'] );
	}

	$record = Blueprint_Registry_Bundles::add_change_file( $change_id, $contents, $path );
	if ( is_wp_error( $record ) ) {
		throw new RuntimeException( $record->get_error_message() );
	}

	return $record;
};

try {
	$author_id = wp_insert_user(
		array(
			'user_login' => 'review-author-' . wp_generate_password( 8, false ),
			'user_pass'  => 'password',
			'user_email' => wp_generate_password( 8, false ) . '@example.test',
			'role'       => 'subscriber',
		)
	);
	$assert( ! is_wp_error( $author_id ), 'The test contributor must be created.' );
	$created_users[] = $author_id;
	$other_author_id = wp_insert_user(
		array(
			'user_login' => 'other-review-author-' . wp_generate_password( 8, false ),
			'user_pass'  => 'password',
			'user_email' => wp_generate_password( 8, false ) . '@example.test',
			'role'       => 'subscriber',
		)
	);
	$assert( ! is_wp_error( $other_author_id ), 'The second test contributor must be created.' );
	$created_users[] = $other_author_id;

	$create_change = static function ( $title ) use ( &$created_posts, $author_id ) {
		$change_id = Blueprint_Registry_Workflow::create_change(
			array(
				'title'     => $title,
				'author_id' => $author_id,
			)
		);
		if ( is_wp_error( $change_id ) ) {
			throw new RuntimeException( $change_id->get_error_message() );
		}
		$created_posts[] = $change_id;
		return $change_id;
	};
	$track_submission = static function ( $change_id ) use ( &$created_posts, &$created_attachments, $assert ) {
		$submission_id = Blueprint_Registry_Workflow::current_submission_id( $change_id );
		$assert( $submission_id && 'blueprint_submission' === get_post_type( $submission_id ), 'Submission must create a private submitted-version post.' );
		$created_posts[] = $submission_id;
		foreach ( Blueprint_Registry_Bundles::get_submission_files( $submission_id ) as $file ) {
		}
		return $submission_id;
	};
	$submit_admin_review = static function ( $change_id, $submission_id, $decision, $note ) {
		$previous_post    = $_POST;
		$previous_request = $_REQUEST;
		$redirect         = '';
		$stop_redirect    = static function ( $location ) use ( &$redirect ) {
			$redirect = $location;
			throw new RuntimeException( 'stop-admin-post-redirect' );
		};

		$_POST = array(
			'action'        => 'bp_review_change',
			'change_id'     => $change_id,
			'submission_id' => $submission_id,
			'decision'      => $decision,
			'note'          => $note,
			'_wpnonce'      => wp_create_nonce( 'bp_review_change_' . $change_id . '_' . $submission_id ),
		);
		$_REQUEST = $_POST;
		add_filter( 'wp_redirect', $stop_redirect, -PHP_INT_MAX );
		try {
			do_action( 'admin_post_bp_review_change' );
		} catch ( RuntimeException $error ) {
			if ( 'stop-admin-post-redirect' !== $error->getMessage() ) {
				throw $error;
			}
		} finally {
			remove_filter( 'wp_redirect', $stop_redirect, -PHP_INT_MAX );
			$_POST    = $previous_post;
			$_REQUEST = $previous_request;
		}

		return $redirect;
	};
	$submit_frontend_form = static function ( $action, $change_id, $fields = array() ) {
		global $wp_query;

		$previous_post     = $_POST;
		$previous_request  = $_REQUEST;
		$previous_method   = $_SERVER['REQUEST_METHOD'] ?? null;
		$previous_wp_query = $wp_query ?? null;
		if ( ! isset( $wp_query ) || ! $wp_query instanceof WP_Query ) {
			$wp_query = new WP_Query();
		}
		$previous_query_vars = $wp_query->query_vars;
		$redirect            = '';
		$stop_redirect       = static function ( $location ) use ( &$redirect ) {
			$redirect = $location;
			throw new RuntimeException( 'stop-frontend-redirect' );
		};

		$_POST = array_merge(
			array(
				'bp_action' => $action,
				'change_id' => $change_id,
				'_wpnonce'  => wp_create_nonce( 'submit' === $action ? 'bp_front_submit_' . $change_id : 'bp_front_save_' . $change_id ),
			),
			$fields
		);
		$_REQUEST                              = $_POST;
		$_SERVER['REQUEST_METHOD']             = 'POST';
		$wp_query->query_vars['bp_manage']     = 'edit';
		$wp_query->query_vars['bp_change_id']  = $change_id;
		$frontend = new Blueprint_Registry_Frontend();
		add_filter( 'wp_redirect', $stop_redirect, -PHP_INT_MAX );
		try {
			$frontend->handle_actions();
		} catch ( RuntimeException $error ) {
			if ( 'stop-frontend-redirect' !== $error->getMessage() ) {
				throw $error;
			}
		} finally {
			remove_filter( 'wp_redirect', $stop_redirect, -PHP_INT_MAX );
			$_POST                = $previous_post;
			$_REQUEST             = $previous_request;
			$wp_query->query_vars = $previous_query_vars;
			$wp_query             = $previous_wp_query;
			if ( null === $previous_method ) {
				unset( $_SERVER['REQUEST_METHOD'] );
			} else {
				$_SERVER['REQUEST_METHOD'] = $previous_method;
			}
		}

		return $redirect;
	};

	wp_set_current_user( $author_id );
	$title     = 'Review workflow ' . wp_generate_password( 8, false );
	$change_id = $create_change( $title );
	Blueprint_Registry_Workflow::set_source( $change_id, $source_for( $title, 'first submitted version' ) );
	$assert( ! is_wp_error( $add_file( $change_id, 'content/review.txt', "first file\n" ) ), 'The contributor file must be added to the draft.' );

	$other_submit = Blueprint_Registry_Workflow::submit( $change_id, $other_author_id );
	$assert( is_wp_error( $other_submit ) && 'blueprint_forbidden' === $other_submit->get_error_code(), 'Only the proposal author may submit it.' );
	$assert( true === Blueprint_Registry_Workflow::submit( $change_id, $author_id ), 'A contributor must be able to submit their valid draft.' );
	$submission_one = $track_submission( $change_id );
	$assert( 'pending_review' === get_post_meta( $change_id, '_bp_status', true ), 'Submission must place a proposal in the pending queue.' );
	$assert( 0 < (int) get_post_meta( $submission_one, '_bp_submitted_at', true ), 'The fixed submitted version must have a queue timestamp.' );
	$assert( Blueprint_Registry_Workflow::source( $change_id ) === get_post_field( 'post_content', $submission_one ), 'The submitted version must copy the current Blueprint JSON.' );
	$submission_one_file = Blueprint_Registry_Bundles::get_submission_files( $submission_one )[0];
	$assert( "first file\n" === Blueprint_Registry_Storage::read( $submission_one, $submission_one_file ), 'The submitted version must copy the bundle file.' );

	$second_source = $source_for( $title, 'second submitted version' );
	Blueprint_Registry_Workflow::set_source( $change_id, $second_source );
	$set_file( $change_id, 'content/review.txt', "second file\n" );
	$assert( $second_source === Blueprint_Registry_Workflow::source( $change_id ), 'A contributor must keep editing while the earlier version is in the queue.' );
	$assert( "first file\n" === Blueprint_Registry_Storage::read( $submission_one, $submission_one_file ), 'Editing the draft must not alter the earlier submitted file.' );
	$assert( true === Blueprint_Registry_Workflow::submit( $change_id, $author_id ), 'A contributor must be able to submit a later version without leaving the queue.' );
	$submission_two = $track_submission( $change_id );
	$assert( $submission_two !== $submission_one && 'superseded' === get_post_meta( $submission_one, '_bp_status', true ), 'A later submission must replace the older queued version.' );
	$assert( 'pending_review' === get_post_meta( $change_id, '_bp_status', true ), 'Submitting another version must keep the proposal in the reviewer queue.' );
	$submission_two_file = Blueprint_Registry_Bundles::get_submission_files( $submission_two )[0];
	$assert( "second file\n" === Blueprint_Registry_Storage::read( $submission_two, $submission_two_file ), 'The newer submitted version must include the current file contents.' );

	$stale_review = Blueprint_Registry_Workflow::review( $change_id, 'approved', 1, '', $submission_one );
	$assert( is_wp_error( $stale_review ) && 'blueprint_stale_submission' === $stale_review->get_error_code(), 'A reviewer cannot decide on a submitted version that was replaced.' );
	$assert( is_wp_error( Blueprint_Registry_Workflow::review( $change_id, 'approved', $author_id, '', $submission_two ) ), 'A contributor must not be able to review a proposal.' );

	wp_set_current_user( 1 );
	$admin = new Blueprint_Registry_Admin();
	ob_start();
	$admin->render_review_queue();
	$queue = ob_get_clean();
	$assert( str_contains( $queue, get_the_title( $change_id ) ) && str_contains( $queue, '>2</td>' ) && str_contains( $queue, esc_url( Blueprint_Registry_Admin::review_url( $change_id ) ) ), 'The wp-admin queue must show the latest submitted version and link to its review screen.' );
	ob_start();
	$admin->render_source_box( get_post( $change_id ) );
	$review_source = ob_get_clean();
	$assert( str_contains( $review_source, 'second submitted version' ), 'The reviewer source panel must show the fixed submitted JSON.' );

	wp_set_current_user( $author_id );
	Blueprint_Registry_Workflow::set_source( $change_id, $source_for( $title, 'working copy only' ) );
	$set_file( $change_id, 'content/review.txt', "working file only\n" );
	wp_set_current_user( 1 );
	ob_start();
	$admin->render_source_box( get_post( $change_id ) );
	$review_source = ob_get_clean();
	$assert( str_contains( $review_source, 'second submitted version' ) && ! str_contains( $review_source, 'working copy only' ), 'The reviewer must not see later unsubmitted JSON edits.' );
	ob_start();
	$admin->render_files_box( get_post( $change_id ) );
	$review_files = ob_get_clean();
	$assert( str_contains( $review_files, $submission_two_file['path'] ), 'The reviewer file panel must show submitted file copies.' );
	$assert( str_contains( $review_files, 'action=bp_bundle_file' ) && str_contains( $review_files, 'download=1' ), 'Reviewer bundle files must have open and download links.' );
	$assert(
		str_contains( $review_files, 'submission_id=' . $submission_two )
		&& str_contains( $review_files, 'key=' . $submission_two_file['key'] )
		&& ! str_contains( $review_files, 'attachment_id=' ),
		'Reviewer file links must address the submitted private file by its storage key.'
	);

	$missing_message = Blueprint_Registry_Workflow::review( $change_id, 'changes_requested', 1, '', $submission_two );
	$assert( is_wp_error( $missing_message ) && 'blueprint_review_note_required' === $missing_message->get_error_code(), 'Returned proposals require a reviewer message.' );
	$redirect = $submit_admin_review( $change_id, $submission_two, 'changes_requested', 'Use a placeholder URL for this request.' );
	$assert( str_contains( $redirect, 'page=blueprint-review-queue' ), 'The review action must return to the wp-admin queue.' );
	$assert( 'changes_requested' === get_post_meta( $change_id, '_bp_status', true ) && 'changes_requested' === get_post_meta( $submission_two, '_bp_status', true ), 'Requesting changes must close that submitted version.' );
	$messages = Blueprint_Registry_Workflow::review_messages( $change_id );
	$assert( 1 === count( $messages ), 'Requesting changes must create one review-history entry.' );
	$created_comments[] = (int) $messages[0]->comment_ID;
	$assert( $submission_two === (int) get_comment_meta( $messages[0]->comment_ID, '_bp_submission_id', true ), 'Review history must identify the submitted version it covers.' );

	wp_set_current_user( $author_id );
	$third_source = $source_for( $title, 'third submitted version' );
	Blueprint_Registry_Workflow::set_source( $change_id, $third_source );
	$set_file( $change_id, 'content/review.txt', "third file\n" );
	$assert( true === Blueprint_Registry_Workflow::submit( $change_id, $author_id ), 'A returned proposal must be resubmittable.' );
	$submission_three = $track_submission( $change_id );
	$assert( $submission_three !== $submission_two && 'superseded' === get_post_meta( $submission_two, '_bp_status', true ), 'Resubmitting after feedback must create another fixed version.' );
	$submission_three_file = Blueprint_Registry_Bundles::get_submission_files( $submission_three )[0];
	Blueprint_Registry_Workflow::set_source( $change_id, $source_for( $title, 'later working copy' ) );
	$set_file( $change_id, 'content/review.txt', "later working file\n" );

	wp_set_current_user( 1 );
	$release_id = Blueprint_Registry_Workflow::review( $change_id, 'approved', 1, 'Ready to publish.', $submission_three );
	$assert( ! is_wp_error( $release_id ), 'A reviewer must be able to accept the latest submitted version.' );
	$created_posts[]       = $release_id;
	$created_attachments[] = (int) get_post_meta( $release_id, '_bp_bundle_attachment_id', true );
	$blueprint_id          = (int) get_post_meta( $change_id, '_bp_target_blueprint_id', true );
	$created_posts[]       = $blueprint_id;
	$assert( 'accepted' === get_post_meta( $change_id, '_bp_status', true ), 'Acceptance must mark the reviewed proposal accepted.' );
	$assert( $third_source === get_post_field( 'post_content', $release_id ), 'Acceptance must release the fixed submitted JSON, not later draft edits.' );
	$assert( "third file\n" === Blueprint_Registry_Bundles::release_file_contents( $release_id, 'content/review.txt' ), 'Acceptance must release the fixed submitted file, not later draft edits.' );
	$follow_up_id = (int) get_post_meta( $change_id, '_bp_follow_up_change_id', true );
	$assert( $follow_up_id && 'draft' === get_post_meta( $follow_up_id, '_bp_status', true ), 'Later draft work must remain available as a new draft after acceptance.' );
	$created_posts[] = $follow_up_id;
	foreach ( Blueprint_Registry_Bundles::get_change_files( $follow_up_id ) as $file ) {
	}
	$assert( str_contains( Blueprint_Registry_Workflow::source( $follow_up_id ), 'later working copy' ), 'The follow-up draft must retain later JSON edits.' );
	$assert( "later working file\n" === Blueprint_Registry_Storage::read( $follow_up_id, Blueprint_Registry_Bundles::get_change_files( $follow_up_id )[0] ), 'The follow-up draft must retain later file edits.' );
	$assert( $release_id === (int) get_post_meta( $follow_up_id, '_bp_base_release_id', true ), 'The follow-up draft must start from the newly accepted release.' );

	wp_set_current_user( $author_id );
	$review_redirect = $submit_frontend_form(
		'save_and_review',
		$follow_up_id,
		array( 'bp_blueprint_json' => Blueprint_Registry_Workflow::source( $follow_up_id ) )
	);
	$assert( str_contains( $review_redirect, '/blueprints/manage/' . $follow_up_id . '/review/' ), 'The editor must save an update and open its pre-submit review step.' );
	$render_review = new ReflectionMethod( Blueprint_Registry_Frontend::class, 'render_submission_review' );
	$render_review->setAccessible( true );
	ob_start();
	$render_review->invoke( null, $follow_up_id );
	$review_html = ob_get_clean();
	preg_match( '/name="bp_reviewed_content" value="([^"]+)"/', $review_html, $reviewed_content );
	$assert( ! empty( $reviewed_content[1] ), 'The review screen must identify the contents being confirmed.' );
	$submission_redirect = $submit_frontend_form( 'submit', $follow_up_id, array( 'bp_reviewed_content' => $reviewed_content[1] ) );
	$assert( str_contains( $submission_redirect, '/blueprints/manage/' . $follow_up_id . '/' ) && ! str_contains( $submission_redirect, 'bp_notice=error' ) && ! str_contains( $submission_redirect, '/review/' ), 'The pre-submit review step must submit the update while keeping the editor available.' );
	$assert( 'pending_review' === get_post_meta( $follow_up_id, '_bp_status', true ), 'The confirmed review step must place the fixed version in the queue.' );
	$follow_up_submission = $track_submission( $follow_up_id );
	wp_set_current_user( 1 );
	ob_start();
	$admin->render_details_box( get_post( $follow_up_id ) );
	$review_details = ob_get_clean();
	$assert( str_contains( $review_details, 'In review' ) && str_contains( $review_details, 'Revision 1' ) && ! str_contains( $review_details, '>' . $release_id . '<' ), 'Reviewer details must use the shared status label and a revision number rather than an internal post ID.' );
	$assert( is_wp_error( Blueprint_Registry_Workflow::submit( $change_id, $author_id ) ), 'An accepted proposal must not re-enter the queue.' );

	$rejected_change = $create_change( 'Rejected workflow ' . wp_generate_password( 8, false ) );
	$assert( true === Blueprint_Registry_Workflow::submit( $rejected_change, $author_id ), 'A second proposal must enter the pending queue.' );
	$rejected_submission = $track_submission( $rejected_change );
	wp_set_current_user( 1 );
	$missing_rejection_message = Blueprint_Registry_Workflow::review( $rejected_change, 'rejected', 1, '', $rejected_submission );
	$assert( is_wp_error( $missing_rejection_message ) && 'blueprint_review_note_required' === $missing_rejection_message->get_error_code(), 'Rejected proposals require a reviewer message.' );
	$assert( true === Blueprint_Registry_Workflow::review( $rejected_change, 'rejected', 1, 'This does not belong in the gallery.', $rejected_submission ), 'A reviewer must be able to reject a proposal with a message.' );
	$assert( 'rejected' === get_post_meta( $rejected_change, '_bp_status', true ) && 'rejected' === get_post_meta( $rejected_submission, '_bp_status', true ), 'A rejected proposal must be closed.' );
	$messages = Blueprint_Registry_Workflow::review_messages( $rejected_change );
	$created_comments[] = (int) $messages[0]->comment_ID;

	$failed_release_change = $create_change( 'Release failure workflow ' . wp_generate_password( 8, false ) );
	$assert( true === Blueprint_Registry_Workflow::submit( $failed_release_change, $author_id ), 'A proposal used to test release cleanup must enter the queue.' );
	$failed_submission = $track_submission( $failed_release_change );
	wp_set_current_user( 1 );
	$blueprint_count = count( get_posts( array( 'post_type' => 'blueprint', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids' ) ) );
	$fail_bundle_upload = static function ( $upload ) {
		if ( str_starts_with( $upload['name'], 'blueprint-release-' ) ) {
			return 'Forced release archive upload failure.';
		}
		return $upload;
	};
	add_filter( 'wp_upload_bits', $fail_bundle_upload );
	try {
		$failed_release = Blueprint_Registry_Workflow::review( $failed_release_change, 'approved', 1, 'This release will fail while building its bundle.', $failed_submission );
	} finally {
		remove_filter( 'wp_upload_bits', $fail_bundle_upload );
	}
	$assert( is_wp_error( $failed_release ) && 'blueprint_upload_failed' === $failed_release->get_error_code(), 'A bundle upload failure must stop release creation.' );
	$assert( 'pending_review' === get_post_meta( $failed_release_change, '_bp_status', true ), 'A failed release must leave the submitted version pending for another review attempt.' );
	$assert( 0 === (int) get_post_meta( $failed_release_change, '_bp_target_blueprint_id', true ) && 0 === (int) get_post_field( 'post_parent', $failed_release_change ), 'A failed first release must not leave a dangling public Blueprint target.' );
	$assert( $blueprint_count === count( get_posts( array( 'post_type' => 'blueprint', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids' ) ) ), 'A failed first release must not add a public Blueprint.' );
	$assert( array() === Blueprint_Registry_Workflow::review_messages( $failed_release_change ), 'A failed acceptance must not leave an approved review-history entry.' );

	fwrite( STDOUT, "PASS review workflow: fixed submitted versions, continuing edits, reviewer decisions, and release cleanup.\n" );
} catch ( Throwable $error ) {
	fwrite( STDERR, "FAIL review workflow: " . $error->getMessage() . "\n" );
	throw $error;
} finally {
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
