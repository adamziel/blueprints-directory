<?php

defined( 'ABSPATH' ) || exit;

final class Blueprint_Registry_Workflow {
	private const REVIEW_MESSAGE_TYPE = 'bp_review_message';
	private static $setting_source = false;

	public static function source( $change_id ) {
		$source = (string) get_post_field( 'post_content', $change_id );
		return '' !== $source ? $source : (string) get_post_meta( $change_id, '_bp_blueprint_json', true );
	}

	public static function set_source( $change_id, $source ) {
		$source = (string) $source;
		update_post_meta( $change_id, '_bp_blueprint_json', $source );
		$change = get_post( $change_id );
		if ( ! $change ) {
			return;
		}

		$update       = array( 'ID' => $change_id );
		$presentation = self::presentation( $source );
		if ( (string) $change->post_content !== $source ) {
			$update['post_content'] = wp_slash( $source );
		}
		if ( isset( $presentation['title'] ) && $presentation['title'] !== $change->post_title ) {
			$update['post_title'] = $presentation['title'];
		}
		if ( 1 < count( $update ) ) {
			self::$setting_source = true;
			try {
				wp_update_post( $update );
			} finally {
				self::$setting_source = false;
			}
		}
	}

	public static function is_setting_source() {
		return self::$setting_source;
	}

	/**
	 * Gets the title and description used for the directory from a Blueprint's
	 * own meta object. Missing fields are deliberately omitted so an older
	 * Blueprint without them does not overwrite its existing directory data.
	 */
	public static function presentation( $source ) {
		$data = json_decode( $source, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) || empty( $data['meta'] ) || ! is_array( $data['meta'] ) ) {
			return array();
		}

		$presentation = array();
		if ( isset( $data['meta']['title'] ) && is_string( $data['meta']['title'] ) ) {
			$title = sanitize_text_field( $data['meta']['title'] );
			if ( '' !== $title ) {
				$presentation['title'] = $title;
			}
		}
		if ( isset( $data['meta']['description'] ) && is_string( $data['meta']['description'] ) ) {
			$presentation['description'] = sanitize_textarea_field( $data['meta']['description'] );
		}

		return $presentation;
	}

	public static function create_change( $args = array() ) {
		$defaults = array(
			'author_id'   => get_current_user_id(),
			'title'       => __( 'Untitled Blueprint', 'blueprint-registry' ),
			'target_id'   => 0,
			'source_blueprint_id' => 0,
			'change_type' => 'new',
		);
		$args     = wp_parse_args( $args, $defaults );
		$target   = (int) $args['target_id'];
		$source_blueprint = (int) $args['source_blueprint_id'];

		if ( $target && 'blueprint' !== get_post_type( $target ) ) {
			return new WP_Error( 'blueprint_invalid_target', __( 'The target Blueprint does not exist.', 'blueprint-registry' ) );
		}
		if ( $source_blueprint && 'blueprint' !== get_post_type( $source_blueprint ) ) {
			return new WP_Error( 'blueprint_invalid_source', __( 'The source Blueprint does not exist.', 'blueprint-registry' ) );
		}

		$change_id = wp_insert_post(
			array(
				'post_type'   => 'blueprint_change',
				'post_status' => 'draft',
				'post_title'  => sanitize_text_field( $args['title'] ),
				'post_author' => (int) $args['author_id'],
				'post_parent' => $target,
			),
			true
		);
		if ( is_wp_error( $change_id ) ) {
			return $change_id;
		}

		update_post_meta( $change_id, '_bp_change_files', array() );
		$source = self::default_source( $args['title'], get_userdata( (int) $args['author_id'] )->user_login );
		$base   = 0;
		$source_blueprint = $source_blueprint ?: $target;
		if ( $source_blueprint ) {
			$base = (int) get_post_meta( $source_blueprint, '_bp_current_release_id', true );
			if ( $base ) {
				$source = (string) get_post_field( 'post_content', $base );
				$copied = Blueprint_Registry_Bundles::copy_release_to_change( $base, $change_id );
				if ( is_wp_error( $copied ) ) {
					wp_delete_post( $change_id, true );
					return $copied;
				}
			}
		}

		self::set_source( $change_id, $source );
		update_post_meta( $change_id, '_bp_target_blueprint_id', $target );
		update_post_meta( $change_id, '_bp_source_blueprint_id', $source_blueprint );
		update_post_meta( $change_id, '_bp_base_release_id', $base );
		update_post_meta( $change_id, '_bp_change_type', sanitize_key( $args['change_type'] ) );
		update_post_meta( $change_id, '_bp_status', 'draft' );

		return (int) $change_id;
	}

	public static function submit( $change_id, $user_id = 0 ) {
		$change = get_post( $change_id );
		if ( ! $change || 'blueprint_change' !== $change->post_type ) {
			return new WP_Error( 'blueprint_missing_change', __( 'The change proposal does not exist.', 'blueprint-registry' ) );
		}

		$user_id = $user_id ?: get_current_user_id();
		if ( (int) $change->post_author !== (int) $user_id ) {
			return new WP_Error( 'blueprint_forbidden', __( 'You cannot submit this change proposal.', 'blueprint-registry' ) );
		}
		if ( ! in_array( self::status( $change_id ), array( 'draft', 'pending_review', 'changes_requested' ), true ) ) {
			return new WP_Error( 'blueprint_not_submittable', __( 'This proposal can no longer be submitted for review.', 'blueprint-registry' ) );
		}

		$errors = Blueprint_Registry_Validator::validate_change( $change_id );
		if ( $errors ) {
			return new WP_Error( 'blueprint_invalid_change', implode( ' ', $errors ), $errors );
		}

		$submission = self::create_submission( $change_id );
		if ( is_wp_error( $submission ) ) {
			return $submission;
		}

		$current_submission = self::current_submission_id( $change_id );
		if ( $current_submission ) {
			update_post_meta( $current_submission, '_bp_status', 'superseded' );
		}

		update_post_meta( $change_id, '_bp_current_submission_id', $submission );
		update_post_meta( $change_id, '_bp_status', 'pending_review' );
		update_post_meta( $change_id, '_bp_submitted_at', time() );
		return true;
	}

	/**
	 * Returns the submitted copy that is currently in the reviewer queue.
	 */
	public static function current_submission_id( $change_id ) {
		return (int) get_post_meta( $change_id, '_bp_current_submission_id', true );
	}

	public static function review( $change_id, $decision, $reviewer_id = 0, $note = '', $submission_id = 0 ) {
		$change = get_post( $change_id );
		if ( ! $change || 'blueprint_change' !== $change->post_type ) {
			return new WP_Error( 'blueprint_missing_change', __( 'The change proposal does not exist.', 'blueprint-registry' ) );
		}

		$reviewer_id = $reviewer_id ?: get_current_user_id();
		if ( ! user_can( $reviewer_id, 'review_blueprints' ) ) {
			return new WP_Error( 'blueprint_forbidden', __( 'You cannot review this change proposal.', 'blueprint-registry' ) );
		}

		if ( ! in_array( $decision, array( 'approved', 'changes_requested', 'rejected' ), true ) ) {
			return new WP_Error( 'blueprint_invalid_decision', __( 'The review decision is invalid.', 'blueprint-registry' ) );
		}
		if ( 'pending_review' !== self::status( $change_id ) ) {
			return new WP_Error( 'blueprint_not_pending', __( 'Only pending changes can be reviewed.', 'blueprint-registry' ) );
		}

		$current_submission = self::current_submission_id( $change_id );
		$submission_id      = $submission_id ?: $current_submission;
		$submission         = get_post( $submission_id );
		if ( ! $submission || 'blueprint_submission' !== $submission->post_type || (int) $submission->post_parent !== $change_id || $submission_id !== $current_submission || 'pending_review' !== get_post_meta( $submission_id, '_bp_status', true ) ) {
			return new WP_Error( 'blueprint_stale_submission', __( 'A newer submitted version is waiting for review.', 'blueprint-registry' ) );
		}

		$note = sanitize_textarea_field( $note );
		if ( in_array( $decision, array( 'changes_requested', 'rejected' ), true ) && '' === $note ) {
			return new WP_Error( 'blueprint_review_note_required', __( 'Requesting changes or rejecting a proposal requires a review message.', 'blueprint-registry' ) );
		}

		if ( 'changes_requested' === $decision || 'rejected' === $decision ) {
			$message = self::record_review_message( $change_id, $submission_id, $decision, $reviewer_id, $note );
			if ( is_wp_error( $message ) ) {
				return $message;
			}

			self::record_review_details( $change_id, $reviewer_id, $note );
			update_post_meta( $submission_id, '_bp_status', $decision );
			update_post_meta( $change_id, '_bp_status', $decision );
			return true;
		}

		$errors = Blueprint_Registry_Validator::validate( (string) $submission->post_content, Blueprint_Registry_Bundles::get_submission_files( $submission_id ) );
		if ( $errors ) {
			return new WP_Error( 'blueprint_invalid_change', implode( ' ', $errors ), $errors );
		}

		$message = self::record_review_message( $change_id, $submission_id, 'approved', $reviewer_id, $note );
		if ( is_wp_error( $message ) ) {
			return $message;
		}

		$release = self::create_release( $change_id, $submission_id, $reviewer_id );
		if ( is_wp_error( $release ) ) {
			wp_delete_comment( $message, true );
			if ( 'blueprint_stale_change' === $release->get_error_code() ) {
				$note = $note ?: $release->get_error_message();
				$message = self::record_review_message( $change_id, $submission_id, 'changes_requested', $reviewer_id, $note );
				if ( is_wp_error( $message ) ) {
					return $message;
				}
				self::record_review_details( $change_id, $reviewer_id, $note );
				update_post_meta( $submission_id, '_bp_status', 'changes_requested' );
				update_post_meta( $change_id, '_bp_status', 'changes_requested' );
			}
			return $release;
		}

		self::record_review_details( $change_id, $reviewer_id, $note );
		update_post_meta( $submission_id, '_bp_status', 'accepted' );
		return $release;
	}

	public static function review_messages( $change_id ) {
		return get_comments(
			array(
				'post_id' => (int) $change_id,
				'type'    => self::REVIEW_MESSAGE_TYPE,
				'status'  => 'approve',
				'orderby' => 'comment_date_gmt',
				'order'   => 'ASC',
			)
		);
	}

	private static function create_submission( $change_id ) {
		$source = Blueprint_Registry_Validator::canonical_json( self::source( $change_id ) );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$submission_number = 1 + count(
			get_posts(
				array(
					'post_type'      => 'blueprint_submission',
					'post_parent'    => $change_id,
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			)
		);
		$submission = wp_insert_post(
			array(
				'post_type'    => 'blueprint_submission',
				'post_status'  => 'private',
				'post_title'   => sprintf( '%s — submitted version %d', get_the_title( $change_id ), $submission_number ),
				'post_content' => $source,
				'post_parent'  => $change_id,
				'post_author'  => (int) get_post_field( 'post_author', $change_id ),
			),
			true
		);
		if ( is_wp_error( $submission ) ) {
			return $submission;
		}

		$files = Blueprint_Registry_Bundles::copy_change_files_to_submission( $change_id, $submission );
		if ( is_wp_error( $files ) ) {
			wp_delete_post( $submission, true );
			return $files;
		}

		update_post_meta( $submission, '_bp_change_id', $change_id );
		update_post_meta( $submission, '_bp_base_release_id', (int) get_post_meta( $change_id, '_bp_base_release_id', true ) );
		update_post_meta( $submission, '_bp_submission_number', $submission_number );
		update_post_meta( $submission, '_bp_submitted_at', time() );
		update_post_meta( $submission, '_bp_status', 'pending_review' );
		update_post_meta( $submission, '_bp_manifest', Blueprint_Registry_Bundles::release_manifest( $source, $files ) );
		return (int) $submission;
	}

	/**
	 * Lets the author re-submit a returned update after another release became
	 * current. The proposal contents stay untouched so the author can inspect
	 * the new comparison before sending it back to review.
	 */
	public static function refresh_base_release( $change_id, $user_id = 0 ) {
		$change = get_post( $change_id );
		if ( ! $change || 'blueprint_change' !== $change->post_type ) {
			return new WP_Error( 'blueprint_missing_change', __( 'The change proposal does not exist.', 'blueprint-registry' ) );
		}

		$user_id = $user_id ?: get_current_user_id();
		if ( (int) $change->post_author !== (int) $user_id ) {
			return new WP_Error( 'blueprint_forbidden', __( 'You cannot update the base release for this proposal.', 'blueprint-registry' ) );
		}
		if ( 'changes_requested' !== self::status( $change_id ) ) {
			return new WP_Error( 'blueprint_not_refreshable', __( 'Only a proposal returned for changes can use a newer base release.', 'blueprint-registry' ) );
		}

		$target_id = (int) get_post_meta( $change_id, '_bp_target_blueprint_id', true );
		$base_id   = (int) get_post_meta( $change_id, '_bp_base_release_id', true );
		$current_id = (int) get_post_meta( $target_id, '_bp_current_release_id', true );
		if ( ! $target_id || 'blueprint' !== get_post_type( $target_id ) || ! $current_id ) {
			return new WP_Error( 'blueprint_missing_target', __( 'This proposal has no published Blueprint to update.', 'blueprint-registry' ) );
		}
		if ( $base_id === $current_id ) {
			return new WP_Error( 'blueprint_current_base', __( 'This proposal already uses the current release as its base.', 'blueprint-registry' ) );
		}

		update_post_meta( $change_id, '_bp_base_release_id', $current_id );
		return $current_id;
	}

	/**
	 * When an earlier submitted copy is accepted, preserve later editor work as
	 * a separate draft based on the release that was just created.
	 */
	private static function preserve_later_draft( $change_id, $submission_id, $target_id, $release_id ) {
		$draft_source      = Blueprint_Registry_Validator::canonical_json( self::source( $change_id ) );
		$submission_source = Blueprint_Registry_Validator::canonical_json( (string) get_post_field( 'post_content', $submission_id ) );
		if ( is_wp_error( $draft_source ) || is_wp_error( $submission_source ) ) {
			return false;
		}
		$draft_manifest      = Blueprint_Registry_Bundles::release_manifest( $draft_source, Blueprint_Registry_Bundles::get_change_files( $change_id ) );
		$submission_manifest = Blueprint_Registry_Bundles::release_manifest( $submission_source, Blueprint_Registry_Bundles::get_submission_files( $submission_id ) );
		if ( $draft_manifest === $submission_manifest ) {
			return true;
		}

		$follow_up = self::create_change(
			array(
				'title'       => get_the_title( $change_id ),
				'author_id'   => (int) get_post_field( 'post_author', $change_id ),
				'target_id'   => $target_id,
				'change_type' => 'update',
			)
		);
		if ( is_wp_error( $follow_up ) ) {
			return false;
		}

		self::set_source( $follow_up, $draft_source );
		$copied = Blueprint_Registry_Bundles::replace_change_files_from_change( $change_id, $follow_up );
		if ( is_wp_error( $copied ) ) {
			wp_delete_post( $follow_up, true );
			return false;
		}
		update_post_meta( $follow_up, '_bp_base_release_id', $release_id );
		update_post_meta( $change_id, '_bp_follow_up_change_id', $follow_up );
		return true;
	}

	/**
	 * Makes an accepted proposal point at the exact submitted version that
	 * became public. Draft attachments are no longer needed after their copies
	 * have either been released or moved to a follow-up draft.
	 */
	private static function finalize_accepted_change( $change_id, $submission_id ) {
		$draft_files = Blueprint_Registry_Bundles::get_change_files( $change_id );
		self::set_source( $change_id, (string) get_post_field( 'post_content', $submission_id ) );
		update_post_meta( $change_id, '_bp_change_files', Blueprint_Registry_Bundles::get_submission_files( $submission_id ) );
		foreach ( $draft_files as $file ) {
			wp_delete_attachment( (int) $file['attachment_id'], true );
		}
	}

	private static function create_release( $change_id, $submission_id, $reviewer_id ) {
		$target_id = (int) get_post_meta( $change_id, '_bp_target_blueprint_id', true );
		$base_id   = (int) get_post_meta( $submission_id, '_bp_base_release_id', true );
		$source    = Blueprint_Registry_Validator::canonical_json( (string) get_post_field( 'post_content', $submission_id ) );
		$presentation = self::presentation( $source );
		$created_target = false;

		if ( $target_id ) {
			$current_id = (int) get_post_meta( $target_id, '_bp_current_release_id', true );
			if ( $current_id !== $base_id ) {
				return new WP_Error( 'blueprint_stale_change', __( 'A newer release exists. Refresh this proposal before approval.', 'blueprint-registry' ) );
			}
			$target_update = array( 'ID' => $target_id );
			if ( isset( $presentation['title'] ) ) {
				$target_update['post_title'] = $presentation['title'];
			}
			if ( isset( $presentation['description'] ) ) {
				$target_update['post_content'] = $presentation['description'];
			}
			if ( 1 < count( $target_update ) ) {
				wp_update_post( $target_update );
			}
		} else {
			$target_id = wp_insert_post(
				array(
					'post_type'    => 'blueprint',
					'post_status'  => 'publish',
					'post_title'   => $presentation['title'] ?? get_the_title( $change_id ),
					'post_author'  => (int) get_post_field( 'post_author', $change_id ),
					'post_content' => $presentation['description'] ?? '',
				),
				true
			);
			if ( is_wp_error( $target_id ) ) {
				return $target_id;
			}
			$created_target = true;
			update_post_meta( $target_id, '_bp_visibility', 'public' );
		}

		if ( '' === (string) get_post_meta( $target_id, '_bp_gallery_order', true ) ) {
			update_post_meta( $target_id, '_bp_gallery_order', 1000000000 + time() );
		}

		$release_number = 1 + count(
			get_posts(
				array(
					'post_type'      => 'blueprint_release',
					'post_parent'    => $target_id,
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
			)
		);
		$files          = Blueprint_Registry_Bundles::get_submission_files( $submission_id );
		$manifest       = Blueprint_Registry_Bundles::release_manifest( $source, $files );
		$release_id     = wp_insert_post(
			array(
				'post_type'    => 'blueprint_release',
				'post_status'  => 'private',
				'post_title'   => sprintf( '%s — release %d', get_the_title( $target_id ), $release_number ),
				'post_content' => $source,
				'post_parent'  => $target_id,
				'post_author'  => $reviewer_id,
			),
			true
		);
		if ( is_wp_error( $release_id ) ) {
			if ( $created_target ) {
				wp_delete_post( $target_id, true );
			}
			return $release_id;
		}

		$bundle_id = Blueprint_Registry_Bundles::build_release_bundle( $release_id, $source, $files );
		if ( is_wp_error( $bundle_id ) ) {
			wp_delete_post( $release_id, true );
			if ( $created_target ) {
				wp_delete_post( $target_id, true );
			}
			return $bundle_id;
		}

		update_post_meta( $release_id, '_bp_release_number', $release_number );
		update_post_meta( $release_id, '_bp_parent_blueprint_id', $target_id );
		update_post_meta( $release_id, '_bp_base_release_id', $base_id );
		update_post_meta( $release_id, '_bp_bundle_attachment_id', $bundle_id );
		update_post_meta( $release_id, '_bp_manifest', $manifest );
		update_post_meta( $release_id, '_bp_checksum', hash( 'sha256', wp_json_encode( $manifest ) ) );
		update_post_meta( $release_id, '_bp_status', 'released' );

		update_post_meta( $target_id, '_bp_current_release_id', $release_id );
		if ( $created_target ) {
			if ( 'fork' === get_post_meta( $change_id, '_bp_change_type', true ) ) {
				update_post_meta( $target_id, '_bp_forked_from_blueprint_id', (int) get_post_meta( $change_id, '_bp_source_blueprint_id', true ) );
				update_post_meta( $target_id, '_bp_forked_from_release_id', $base_id );
			}
			update_post_meta( $change_id, '_bp_target_blueprint_id', $target_id );
			wp_update_post( array( 'ID' => $change_id, 'post_parent' => $target_id ) );
		}
		update_post_meta( $change_id, '_bp_release_id', $release_id );
		update_post_meta( $change_id, '_bp_status', 'accepted' );
		update_post_meta( $submission_id, '_bp_release_id', $release_id );
		if ( self::preserve_later_draft( $change_id, $submission_id, $target_id, $release_id ) ) {
			self::finalize_accepted_change( $change_id, $submission_id );
		}
		return (int) $release_id;
	}

	private static function record_review_message( $change_id, $submission_id, $decision, $reviewer_id, $note ) {
		$user = get_userdata( $reviewer_id );
		if ( ! $user ) {
			return new WP_Error( 'blueprint_missing_reviewer', __( 'The reviewer no longer exists.', 'blueprint-registry' ) );
		}

		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $change_id,
				'comment_content'      => $note,
				'comment_type'         => self::REVIEW_MESSAGE_TYPE,
				'comment_approved'     => 1,
				'user_id'              => $reviewer_id,
				'comment_author'       => $user->display_name,
				'comment_author_email' => $user->user_email,
			)
		);
		if ( ! $comment_id ) {
			return new WP_Error( 'blueprint_review_message_failed', __( 'The review message could not be saved.', 'blueprint-registry' ) );
		}

		add_comment_meta( $comment_id, '_bp_review_decision', $decision, true );
		add_comment_meta( $comment_id, '_bp_submission_id', $submission_id, true );
		return (int) $comment_id;
	}

	private static function record_review_details( $change_id, $reviewer_id, $note ) {
		update_post_meta( $change_id, '_bp_review_note', $note );
		update_post_meta( $change_id, '_bp_reviewed_by', $reviewer_id );
		update_post_meta( $change_id, '_bp_reviewed_at', time() );
	}

	private static function status( $change_id ) {
		return get_post_meta( $change_id, '_bp_status', true ) ?: 'draft';
	}

	public static function default_source( $title, $author ) {
		return wp_json_encode(
			array(
				'$schema' => Blueprint_Registry_Validator::SCHEMA_URL,
				'meta'    => array(
					'title'  => sanitize_text_field( $title ),
					'author' => sanitize_user( $author, true ),
				),
				'steps'   => array(),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		) . "\n";
	}
}
