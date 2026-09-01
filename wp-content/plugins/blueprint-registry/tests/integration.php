<?php

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this file through wp eval-file.\n" );
	exit( 1 );
}

if ( ! class_exists( 'Blueprint_Registry_Workflow' ) ) {
	fwrite( STDERR, "Activate blueprint-registry before running this test.\n" );
	exit( 1 );
}

$created_posts       = array();
$created_attachments = array();

$assert = static function ( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$attachment = static function ( $name, $contents, $parent_id ) use ( &$created_attachments ) {
	$upload = wp_upload_bits( $name, null, $contents );
	if ( ! empty( $upload['error'] ) ) {
		throw new RuntimeException( $upload['error'] );
	}
	$id = wp_insert_attachment(
		array(
			'post_title'     => $name,
			'post_mime_type' => 'application/xml',
			'post_status'    => 'inherit',
		),
		$upload['file'],
		$parent_id
	);
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}
	$created_attachments[] = (int) $id;
	return (int) $id;
};

try {
	wp_set_current_user( 1 );
	$assert( current_user_can( 'review_blueprints' ), 'Administrator must have the review capability.' );

	$invalid_change = Blueprint_Registry_Workflow::create_change(
		array(
			'title'     => 'Registry invalid ' . wp_generate_password( 8, false ),
			'author_id' => 1,
		)
	);
	$created_posts[] = $invalid_change;
	Blueprint_Registry_Workflow::set_source(
		$invalid_change,
		wp_json_encode(
			array(
				'$schema' => Blueprint_Registry_Validator::SCHEMA_URL,
				'steps'   => array(
					array(
						'step' => 'importWxr',
						'file' => array( 'resource' => 'bundled', 'path' => '/content/missing.xml' ),
					),
				),
			)
		)
	);
	$errors = Blueprint_Registry_Validator::validate_change( $invalid_change );
	$assert( 1 === count( $errors ) && str_contains( $errors[0], 'missing.xml' ), 'Missing bundled files must be rejected.' );
	$assert( is_wp_error( Blueprint_Registry_Workflow::submit( $invalid_change, 1 ) ), 'An invalid change must not be submitted.' );

	$change_id = Blueprint_Registry_Workflow::create_change(
		array(
			'title'     => 'Registry test ' . wp_generate_password( 8, false ),
			'author_id' => 1,
		)
	);
	$created_posts[] = $change_id;
	$asset_id        = $attachment( 'demo.txt', 'Blueprint test resource', $change_id );
	$xml_asset_id    = $attachment( 'demo.xml', '<wxr />', $change_id );
	$source_title    = 'Registry JSON title ' . wp_generate_password( 8, false );
	$source_description = 'This gallery description comes from Blueprint JSON.';
	$source          = wp_json_encode(
		array(
			'$schema' => Blueprint_Registry_Validator::SCHEMA_URL,
			'meta'    => array( 'title' => $source_title, 'description' => $source_description, 'author' => 'admin' ),
			'steps'   => array(
				array(
					'step' => 'writeFile',
					'path' => '/wordpress/demo.txt',
					'data' => array( 'resource' => 'bundled', 'path' => '/content/demo.txt' ),
				),
				array(
					'step' => 'importWxr',
					'file' => array( 'resource' => 'bundled', 'path' => '/content/demo.xml' ),
				),
			),
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	);
	Blueprint_Registry_Workflow::set_source( $change_id, $source );
	$assert( $source_title === get_the_title( $change_id ), 'The proposal title must come from Blueprint JSON metadata.' );
	$revisions = wp_get_post_revisions( $change_id, array( 'posts_per_page' => -1 ) );
	$assert( ! empty( $revisions ), 'Saving Blueprint JSON must create a native WordPress revision.' );
	$assert( $source === get_post_field( 'post_content', $change_id ), 'The current Blueprint JSON must be stored in the revisioned post content.' );
	$escaped_source = wp_json_encode( array( '$schema' => Blueprint_Registry_Validator::SCHEMA_URL, 'steps' => array( array( 'step' => 'runPHP', 'code' => "<?php echo 'Friends\\Import';" ) ) ) );
	Blueprint_Registry_Workflow::set_source( $change_id, $escaped_source );
	$assert( $escaped_source === Blueprint_Registry_Workflow::source( $change_id ), 'Blueprint JSON containing escaped PHP namespaces must survive saving unchanged.' );
	Blueprint_Registry_Workflow::set_source( $change_id, $source );
	$assert( true === Blueprint_Registry_Bundles::add_change_file( $change_id, $asset_id, 'content/demo.txt' ), 'A valid asset must be added to the draft.' );
	$assert( true === Blueprint_Registry_Bundles::add_change_file( $change_id, $xml_asset_id, 'content/demo.xml' ), 'A valid XML asset must be added to the draft.' );
	$assert( array() === Blueprint_Registry_Validator::validate_change( $change_id ), 'A complete change must validate.' );
	$preview_token = Blueprint_Registry_Bundles::build_preview( $change_id );
	$assert( ! is_wp_error( $preview_token ), 'A valid draft must create a short-lived preview bundle.' );
	$preview_path = Blueprint_Registry_Bundles::get_preview_path( $change_id, $preview_token );
	$assert( is_readable( $preview_path ), 'A preview token must grant access to its draft bundle.' );
	@unlink( $preview_path );
	$assert( true === Blueprint_Registry_Workflow::submit( $change_id, 1 ), 'A valid change must be submitted.' );
	$release_id = Blueprint_Registry_Workflow::review( $change_id, 'approved', 1, 'Looks good.' );
	$assert( ! is_wp_error( $release_id ), 'A reviewer must be able to create a release.' );
	$created_posts[] = $release_id;

	$blueprint_id = (int) get_post_meta( $change_id, '_bp_target_blueprint_id', true );
	$created_posts[] = $blueprint_id;
	$assert( 'blueprint' === get_post_type( $blueprint_id ), 'Approval must create a public Blueprint.' );
	$assert( $release_id === (int) get_post_meta( $blueprint_id, '_bp_current_release_id', true ), 'Approval must make the release current.' );
	$assert( 'accepted' === get_post_meta( $change_id, '_bp_status', true ), 'Approved changes must be marked accepted.' );
	$assert( $source_title === get_the_title( $blueprint_id ) && $source_description === get_post_field( 'post_content', $blueprint_id ), 'The public directory title and description must come from Blueprint JSON metadata.' );

	$bundle_id   = (int) get_post_meta( $release_id, '_bp_bundle_attachment_id', true );
	$bundle_path = get_attached_file( $bundle_id );
	$created_attachments[] = $bundle_id;
	$assert( is_readable( $bundle_path ), 'An accepted release must have a stored ZIP bundle.' );
	$zip = new ZipArchive();
	$assert( true === $zip->open( $bundle_path ), 'The stored bundle must be a readable ZIP.' );
	$assert( false !== $zip->locateName( 'blueprint.json' ), 'The bundle must contain blueprint.json.' );
	$assert( false !== $zip->locateName( 'content/demo.txt' ), 'The bundle must contain uploaded resources.' );
	$assert( false !== $zip->locateName( 'content/demo.xml' ), 'The bundle must contain XML resources.' );
	$zip->close();
	$first_bundle_contents = file_get_contents( $bundle_path );

	$fork_author = wp_insert_user(
		array(
			'user_login' => 'fork-user-' . wp_generate_password( 8, false ),
			'user_pass'  => 'password',
			'user_email' => wp_generate_password( 8, false ) . '@example.test',
			'role'       => 'subscriber',
		)
	);
	$assert( ! is_wp_error( $fork_author ), 'The test contributor must be created.' );
	$fork_change = Blueprint_Registry_Workflow::create_change(
		array(
			'title'               => 'Forked test Blueprint',
			'author_id'           => $fork_author,
			'source_blueprint_id' => $blueprint_id,
			'change_type'         => 'fork',
		)
	);
	$created_posts[] = $fork_change;
	$assert( get_post_field( 'post_content', $release_id ) === Blueprint_Registry_Workflow::source( $fork_change ), 'A fork must start from the exact release source.' );
	$assert( 2 === count( Blueprint_Registry_Bundles::get_change_files( $fork_change ) ), 'A fork must copy all bundle resources into the new draft.' );
	wp_set_current_user( $fork_author );
	$assert( current_user_can( 'edit_post', $fork_change ), 'A standard logged-in contributor must be able to edit their own draft.' );
	$assert( ! current_user_can( 'edit_post', $change_id ), 'A contributor must not be able to edit another author\'s draft.' );
	wp_set_current_user( 1 );
	foreach ( Blueprint_Registry_Bundles::get_change_files( $fork_change ) as $file ) {
		$created_attachments[] = (int) $file['attachment_id'];
	}

	$update_one = Blueprint_Registry_Workflow::create_change(
		array(
			'title'     => get_the_title( $blueprint_id ),
			'author_id' => 1,
			'target_id' => $blueprint_id,
			'change_type' => 'update',
		)
	);
	$update_two = Blueprint_Registry_Workflow::create_change(
		array(
			'title'     => get_the_title( $blueprint_id ),
			'author_id' => 1,
			'target_id' => $blueprint_id,
			'change_type' => 'update',
		)
	);
	$created_posts[] = $update_one;
	$created_posts[] = $update_two;
	$updated_title = 'Registry JSON title updated ' . wp_generate_password( 8, false );
	$updated_description = 'This changed gallery description comes from the new Blueprint JSON.';
	$update_source = json_decode( Blueprint_Registry_Workflow::source( $update_one ), true );
	$update_source['meta']['title'] = $updated_title;
	$update_source['meta']['description'] = $updated_description;
	Blueprint_Registry_Workflow::set_source( $update_one, wp_json_encode( $update_source, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	$assert( $updated_title === get_the_title( $update_one ), 'Editing Blueprint JSON metadata must update the proposal title.' );
	foreach ( array( $update_one, $update_two ) as $update ) {
		foreach ( Blueprint_Registry_Bundles::get_change_files( $update ) as $file ) {
			$created_attachments[] = (int) $file['attachment_id'];
		}
		$assert( true === Blueprint_Registry_Workflow::submit( $update, 1 ), 'An update based on the current release must submit.' );
	}
	$release_two = Blueprint_Registry_Workflow::review( $update_one, 'approved', 1 );
	$assert( ! is_wp_error( $release_two ), 'The first current update must be approved.' );
	$assert( $updated_title === get_the_title( $blueprint_id ) && $updated_description === get_post_field( 'post_content', $blueprint_id ), 'An accepted update must refresh the directory metadata from Blueprint JSON.' );
	$created_posts[] = $release_two;
	$created_attachments[] = (int) get_post_meta( $release_two, '_bp_bundle_attachment_id', true );
	$stale = Blueprint_Registry_Workflow::review( $update_two, 'approved', 1 );
	$assert( is_wp_error( $stale ) && 'blueprint_stale_change' === $stale->get_error_code(), 'An old-base update must be blocked.' );
	$assert( 'changes_requested' === get_post_meta( $update_two, '_bp_status', true ), 'A blocked stale update must request changes.' );
	$assert( $first_bundle_contents === file_get_contents( $bundle_path ), 'A later release must not mutate an earlier bundle.' );

	fwrite( STDOUT, "PASS integration: validation, submission, immutable bundles, forks, and stale-update protection.\n" );
} catch ( Throwable $error ) {
	fwrite( STDERR, "FAIL integration: " . $error->getMessage() . "\n" );
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
				$created_attachments[] = (int) $file['attachment_id'];
			}
			$submission_posts[] = $submission->ID;
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
	if ( isset( $fork_author ) && ! is_wp_error( $fork_author ) ) {
		wp_delete_user( $fork_author );
	}
}
