<?php

defined( 'ABSPATH' ) || exit;

final class Blueprint_Registry_Admin {
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_pages' ) );
		add_action( 'add_meta_boxes_blueprint_change', array( $this, 'add_change_boxes' ) );
		add_action( 'add_meta_boxes_blueprint_change', array( $this, 'remove_change_submit_box' ), 99 );
		add_filter( 'wp_insert_post_data', array( $this, 'lock_change_fields' ), 10, 2 );
		add_action( 'save_post_blueprint_change', array( $this, 'save_change' ), 10, 3 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_editor' ) );
		add_action( 'admin_post_bp_create_change', array( $this, 'create_change' ) );
		add_action( 'admin_post_bp_create_from_release', array( $this, 'create_from_release' ) );
		add_action( 'admin_post_bp_submit_change', array( $this, 'submit_change' ) );
		add_action( 'admin_post_bp_review_change', array( $this, 'review_change' ) );
		add_action( 'admin_post_bp_preview_change', array( $this, 'preview_change' ) );
		add_action( 'admin_post_bp_bundle_file', array( $this, 'serve_bundle_file' ) );
		add_action( 'admin_post_bp_remove_change_file', array( $this, 'remove_change_file' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_filter( 'upload_mimes', array( $this, 'allow_bundle_mimes' ) );
	}

	public function allow_bundle_mimes( $mimes ) {
		return array_merge( $mimes, self::allowed_mimes() );
	}

	public static function review_queue_url( array $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=blueprint-review-queue' ) );
	}

	public static function bundle_file_url( $change_id, $attachment_id, $download = false ) {
		$url = add_query_arg(
			array(
				'action'        => 'bp_bundle_file',
				'change_id'     => (int) $change_id,
				'attachment_id' => (int) $attachment_id,
				'download'      => $download ? '1' : null,
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, 'bp_bundle_file_' . (int) $change_id . '_' . (int) $attachment_id );
	}

	public function register_pages() {
		add_submenu_page(
			'edit.php?post_type=blueprint',
			__( 'Review queue', 'blueprint-registry' ),
			__( 'Review queue', 'blueprint-registry' ),
			'review_blueprints',
			'blueprint-review-queue',
			array( $this, 'render_review_queue' )
		);
	}

	public function add_change_boxes( $post ) {
		add_meta_box( 'bp_blueprint_source', __( 'Blueprint JSON', 'blueprint-registry' ), array( $this, 'render_source_box' ), 'blueprint_change', 'normal', 'high' );
		add_meta_box( 'bp_blueprint_diff', __( 'Changes from the base release', 'blueprint-registry' ), array( $this, 'render_diff_box' ), 'blueprint_change', 'normal', 'default' );
		add_meta_box( 'bp_change_files', __( 'Bundle files', 'blueprint-registry' ), array( $this, 'render_files_box' ), 'blueprint_change', 'normal', 'default' );
		add_meta_box( 'bp_review_history', __( 'Review history', 'blueprint-registry' ), array( $this, 'render_review_history_box' ), 'blueprint_change', 'normal', 'default' );
		if ( Blueprint_Registry_Capabilities::can_review() ) {
			add_meta_box( 'bp_review_decision', __( 'Review decision', 'blueprint-registry' ), array( $this, 'render_review_box' ), 'blueprint_change', 'side', 'high' );
		}
		add_meta_box( 'bp_change_details', __( 'Change details', 'blueprint-registry' ), array( $this, 'render_details_box' ), 'blueprint_change', 'side', 'high' );
	}

	public function remove_change_submit_box() {
		remove_meta_box( 'submitdiv', 'blueprint_change', 'side' );
	}

	public function lock_change_fields( $data, $postarr ) {
		if ( Blueprint_Registry_Workflow::is_setting_source() ) {
			return $data;
		}

		$change_id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		$change    = $change_id ? get_post( $change_id ) : null;
		$status    = $change_id ? get_post_meta( $change_id, '_bp_status', true ) : '';
		if ( ! $change || 'blueprint_change' !== $change->post_type || '' === $status ) {
			return $data;
		}

		$is_author = (int) $change->post_author === get_current_user_id();
		$editable  = in_array( $status, array( 'draft', 'pending_review', 'changes_requested' ), true );
		if ( $is_author && $editable ) {
			return $data;
		}

		$data['post_title']   = $change->post_title;
		$data['post_content'] = $change->post_content;
		$data['post_excerpt'] = $change->post_excerpt;
		return $data;
	}

	public function render_diff_box( $post ) {
		$submission_id = Blueprint_Registry_Workflow::current_submission_id( $post->ID );
		if ( $submission_id ) {
			Blueprint_Registry_Diff::render_submission( $submission_id );
			return;
		}
		echo '<p>' . esc_html__( 'Submit this proposal to see the version a reviewer will compare.', 'blueprint-registry' ) . '</p>';
	}

	public function render_source_box( $post ) {
		$submission_id = Blueprint_Registry_Workflow::current_submission_id( $post->ID );
		$source = $submission_id ? (string) get_post_field( 'post_content', $submission_id ) : Blueprint_Registry_Workflow::source( $post->ID );
		?>
		<p><?php echo esc_html( $this->submission_context( $submission_id ) ); ?></p>
		<textarea id="bp_blueprint_json" rows="24" style="width:100%; font-family:monospace" readonly><?php echo esc_textarea( $source ); ?></textarea>
		<?php
	}

	public function render_files_box( $post ) {
		$submission_id = Blueprint_Registry_Workflow::current_submission_id( $post->ID );
		$files = $submission_id ? Blueprint_Registry_Bundles::get_submission_files( $submission_id ) : array();
		?>
		<p><?php echo esc_html( $this->submission_context( $submission_id, true ) ); ?></p>
		<?php if ( $files ) : ?>
			<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Path', 'blueprint-registry' ); ?></th><th><?php esc_html_e( 'File', 'blueprint-registry' ); ?></th><th><?php esc_html_e( 'Download', 'blueprint-registry' ); ?></th></tr></thead><tbody>
			<?php foreach ( $files as $file ) : ?>
				<?php $read_url = self::bundle_file_url( $post->ID, $file['attachment_id'] ); $download_url = self::bundle_file_url( $post->ID, $file['attachment_id'], true ); ?>
				<tr><td><a href="<?php echo esc_url( $read_url ); ?>"><code><?php echo esc_html( $file['path'] ); ?></code></a></td><td><a href="<?php echo esc_url( $read_url ); ?>"><?php echo esc_html( get_the_title( $file['attachment_id'] ) ); ?></a></td><td><a href="<?php echo esc_url( $download_url ); ?>"><?php esc_html_e( 'Download', 'blueprint-registry' ); ?></a></td></tr>
			<?php endforeach; ?>
			</tbody></table>
		<?php else : ?>
			<p><?php esc_html_e( 'This proposal has no bundled files.', 'blueprint-registry' ); ?></p>
		<?php endif; ?>
		<?php
	}

	private function submission_context( $submission_id, $files = false ) {
		if ( ! $submission_id ) {
			return $files
				? __( 'This draft has not been submitted, so there are no submitted files to review.', 'blueprint-registry' )
				: __( 'This draft has not been submitted for review.', 'blueprint-registry' );
		}

		$status = get_post_meta( $submission_id, '_bp_status', true );
		if ( 'pending_review' === $status ) {
			return $files
				? __( 'These are the files in the submitted version currently under review.', 'blueprint-registry' )
				: __( 'This is the submitted version currently under review.', 'blueprint-registry' );
		}
		if ( 'accepted' === $status ) {
			return $files
				? __( 'These are the files from the version that was accepted.', 'blueprint-registry' )
				: __( 'This is the version that was accepted.', 'blueprint-registry' );
		}

		return $files
			? __( 'These are the files from the version that was reviewed.', 'blueprint-registry' )
			: __( 'This is the version that was reviewed.', 'blueprint-registry' );
	}

	public function render_details_box( $post ) {
		$status       = get_post_meta( $post->ID, '_bp_status', true ) ?: 'draft';
		$target_id    = (int) get_post_meta( $post->ID, '_bp_target_blueprint_id', true );
		$submission_id = Blueprint_Registry_Workflow::current_submission_id( $post->ID );
		$base_id      = $submission_id ? (int) get_post_meta( $submission_id, '_bp_base_release_id', true ) : (int) get_post_meta( $post->ID, '_bp_base_release_id', true );
		$base_number  = $base_id ? (int) get_post_meta( $base_id, '_bp_release_number', true ) : 0;
		?>
		<p><strong><?php esc_html_e( 'Status:', 'blueprint-registry' ); ?></strong> <?php echo esc_html( self::status_label( $status ) ); ?></p>
		<?php if ( $target_id ) : ?>
			<p><strong><?php esc_html_e( 'Target:', 'blueprint-registry' ); ?></strong> <?php echo esc_html( get_the_title( $target_id ) ); ?><br><strong><?php esc_html_e( 'Base release:', 'blueprint-registry' ); ?></strong> <?php if ( $base_number ) : ?><a href="<?php echo esc_url( Blueprint_Registry_Routes::release_detail_url( $target_id, $base_number ) ); ?>"><?php echo esc_html( sprintf( __( 'Release %d', 'blueprint-registry' ), $base_number ) ); ?></a><?php else : ?><?php esc_html_e( 'None', 'blueprint-registry' ); ?><?php endif; ?></p>
		<?php endif; ?>
		<?php
	}

	public function render_review_box( $post ) {
		$status        = get_post_meta( $post->ID, '_bp_status', true ) ?: 'draft';
		$submission_id = Blueprint_Registry_Workflow::current_submission_id( $post->ID );
		if ( 'pending_review' !== $status || ! $submission_id ) {
			echo '<p>' . esc_html__( 'There is no submitted version waiting for a decision.', 'blueprint-registry' ) . '</p>';
			return;
		}

		$submission_number = (int) get_post_meta( $submission_id, '_bp_submission_number', true );
		$preview_url = wp_nonce_url( admin_url( 'admin-post.php?action=bp_preview_change&change_id=' . $post->ID . '&submission_id=' . $submission_id ), 'bp_preview_change_' . $post->ID . '_' . $submission_id );
		?>
		<p><strong><?php echo esc_html( sprintf( __( 'Submitted version %d', 'blueprint-registry' ), $submission_number ) ); ?></strong></p>
		<p><a class="button" href="<?php echo esc_url( $preview_url ); ?>" target="bp-playground"><?php esc_html_e( 'Open Playground preview', 'blueprint-registry' ); ?></a></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="bp_review_change">
			<input type="hidden" name="change_id" value="<?php echo esc_attr( $post->ID ); ?>">
			<input type="hidden" name="submission_id" value="<?php echo esc_attr( $submission_id ); ?>">
			<?php wp_nonce_field( 'bp_review_change_' . $post->ID . '_' . $submission_id ); ?>
			<p><label for="bp_review_note"><strong><?php esc_html_e( 'Review message', 'blueprint-registry' ); ?></strong></label><textarea class="widefat" rows="5" id="bp_review_note" name="note"></textarea></p>
			<p class="description"><?php esc_html_e( 'A message is required when requesting changes or rejecting a proposal.', 'blueprint-registry' ); ?></p>
			<p><button class="button button-primary" name="decision" value="approved"><?php esc_html_e( 'Accept and release', 'blueprint-registry' ); ?></button></p>
			<p><button class="button" name="decision" value="changes_requested"><?php esc_html_e( 'Request changes', 'blueprint-registry' ); ?></button></p>
			<p><button class="button-link-delete" name="decision" value="rejected"><?php esc_html_e( 'Reject proposal', 'blueprint-registry' ); ?></button></p>
		</form>
		<?php
	}

	public function render_review_history_box( $post ) {
		$messages = Blueprint_Registry_Workflow::review_messages( $post->ID );
		if ( ! $messages ) {
			echo '<p>' . esc_html__( 'No review messages yet.', 'blueprint-registry' ) . '</p>';
			return;
		}
		?>
		<ul>
			<?php foreach ( $messages as $message ) : ?>
				<?php $decision = get_comment_meta( $message->comment_ID, '_bp_review_decision', true ); $submission_id = (int) get_comment_meta( $message->comment_ID, '_bp_submission_id', true ); $submission_number = (int) get_post_meta( $submission_id, '_bp_submission_number', true ); ?>
				<li><strong><?php echo esc_html( self::decision_label( $decision ) ); ?></strong><?php if ( $submission_number ) : ?> <?php echo esc_html( sprintf( __( 'Version %d', 'blueprint-registry' ), $submission_number ) ); ?><?php endif; ?> <?php echo esc_html( sprintf( __( 'by %1$s on %2$s', 'blueprint-registry' ), $message->comment_author, get_comment_date( '', $message ) ) ); ?><?php if ( $message->comment_content ) : ?><br><?php echo nl2br( esc_html( $message->comment_content ) ); ?><?php endif; ?></li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	public function enqueue_editor( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || 'blueprint_change' !== get_current_screen()->post_type ) {
			return;
		}
		wp_enqueue_style( 'blueprint-registry-management', BLUEPRINT_REGISTRY_URL . 'assets/management.css', array(), BLUEPRINT_REGISTRY_VERSION );
		$settings = wp_enqueue_code_editor( array( 'type' => 'application/json', 'codemirror' => array( 'indentUnit' => 2, 'tabSize' => 2, 'readOnly' => 'nocursor' ) ) );
		if ( false === $settings ) {
			return;
		}
		wp_enqueue_script( 'blueprint-registry-editor', BLUEPRINT_REGISTRY_URL . 'assets/editor.js', array( 'code-editor', 'wp-i18n' ), BLUEPRINT_REGISTRY_VERSION, true );
		wp_add_inline_script( 'blueprint-registry-editor', 'window.BlueprintRegistryEditor = ' . wp_json_encode( $settings ) . ';', 'before' );
	}

	public function save_change( $post_id, $post, $update ) {
		if ( ! isset( $_POST['bp_change_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bp_change_nonce'] ) ), 'bp_save_change_' . $post_id ) || defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( (int) $post->post_author !== get_current_user_id() || ! in_array( get_post_meta( $post_id, '_bp_status', true ) ?: 'draft', array( 'draft', 'changes_requested' ), true ) ) {
			return;
		}

		if ( isset( $_POST['bp_blueprint_json'] ) ) {
			$source = wp_unslash( $_POST['bp_blueprint_json'] );
			update_post_meta( $post_id, '_bp_blueprint_json', $source );
			if ( (string) get_post_field( 'post_content', $post_id ) !== $source ) {
				remove_action( 'save_post_blueprint_change', array( $this, 'save_change' ), 10 );
				wp_update_post( array( 'ID' => $post_id, 'post_content' => wp_slash( $source ) ) );
				add_action( 'save_post_blueprint_change', array( $this, 'save_change' ), 10, 3 );
			}
		}
		self::save_uploaded_file( $post_id );
		Blueprint_Registry_Validator::validate_change( $post_id );
	}

	public function render_dashboard() {
		if ( ! Blueprint_Registry_Capabilities::can_contribute() ) {
			wp_die( esc_html__( 'You must be logged in to manage Blueprints.', 'blueprint-registry' ) );
		}
		$changes = get_posts(
			array(
				'post_type'      => 'blueprint_change',
				'post_status'    => 'draft',
				'author'         => get_current_user_id(),
				'posts_per_page' => 100,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		?>
		<div class="wrap"><h1><?php esc_html_e( 'My Blueprints', 'blueprint-registry' ); ?></h1>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:1em 0">
			<input type="hidden" name="action" value="bp_create_change">
			<?php wp_nonce_field( 'bp_create_change' ); ?>
			<label for="bp_new_title" class="screen-reader-text"><?php esc_html_e( 'Blueprint title', 'blueprint-registry' ); ?></label><input id="bp_new_title" type="text" name="title" required placeholder="<?php esc_attr_e( 'Blueprint title', 'blueprint-registry' ); ?>">
			<button class="button button-primary"><?php esc_html_e( 'Create Blueprint', 'blueprint-registry' ); ?></button>
		</form>
		<?php if ( $changes ) : ?><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Title', 'blueprint-registry' ); ?></th><th><?php esc_html_e( 'Status', 'blueprint-registry' ); ?></th><th><?php esc_html_e( 'Modified', 'blueprint-registry' ); ?></th><th></th></tr></thead><tbody>
			<?php foreach ( $changes as $change ) : ?>
				<tr><td><?php echo esc_html( get_the_title( $change ) ); ?></td><td><?php echo esc_html( ucwords( str_replace( '_', ' ', get_post_meta( $change->ID, '_bp_status', true ) ?: 'draft' ) ) ); ?></td><td><?php echo esc_html( get_the_modified_date( '', $change ) ); ?></td><td><a class="button" href="<?php echo esc_url( get_edit_post_link( $change->ID, 'raw' ) ); ?>"><?php esc_html_e( 'Edit', 'blueprint-registry' ); ?></a> <?php $this->render_change_actions( $change ); ?></td></tr>
			<?php endforeach; ?>
		</tbody></table><?php else : ?><p><?php esc_html_e( 'Create a Blueprint to begin.', 'blueprint-registry' ); ?></p><?php endif; ?>
		</div>
		<?php
	}

	public function render_review_queue() {
		if ( ! Blueprint_Registry_Capabilities::can_review() ) {
			wp_die( esc_html__( 'You cannot review Blueprints.', 'blueprint-registry' ) );
		}
		$changes = get_posts(
			array(
				'post_type'      => 'blueprint_change',
				'post_status'    => 'draft',
				'meta_query'     => array(
					array(
						'key'   => '_bp_status',
						'value' => 'pending_review',
					),
				),
				'posts_per_page' => 100,
				'orderby'        => 'meta_value_num',
				'meta_key'       => '_bp_submitted_at',
				'order'          => 'ASC',
			)
		);
		$changes = array_filter(
			$changes,
			static function ( $change ) {
				return Blueprint_Registry_Workflow::current_submission_id( $change->ID );
			}
		);
		?>
		<div class="wrap"><h1><?php esc_html_e( 'Blueprint review queue', 'blueprint-registry' ); ?></h1>
		<?php if ( $changes ) : ?><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Title', 'blueprint-registry' ); ?></th><th><?php esc_html_e( 'Version', 'blueprint-registry' ); ?></th><th><?php esc_html_e( 'Author', 'blueprint-registry' ); ?></th><th><?php esc_html_e( 'Submitted', 'blueprint-registry' ); ?></th><th><?php esc_html_e( 'Actions', 'blueprint-registry' ); ?></th></tr></thead><tbody>
			<?php foreach ( $changes as $change ) : ?>
				<?php $submission_id = Blueprint_Registry_Workflow::current_submission_id( $change->ID ); $submitted_at = (int) get_post_meta( $submission_id, '_bp_submitted_at', true ); ?>
				<tr><td><?php echo esc_html( get_the_title( $change ) ); ?></td><td><?php echo esc_html( (int) get_post_meta( $submission_id, '_bp_submission_number', true ) ); ?></td><td><?php echo esc_html( get_the_author_meta( 'display_name', $change->post_author ) ); ?></td><td><?php echo esc_html( $submitted_at ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $submitted_at ) : '—' ); ?></td><td><?php $this->render_review_actions( $change ); ?></td></tr>
			<?php endforeach; ?>
		</tbody></table><?php else : ?><p><?php esc_html_e( 'There are no pending reviews.', 'blueprint-registry' ); ?></p><?php endif; ?>
		</div>
		<?php
	}

	public function create_change() {
		if ( ! Blueprint_Registry_Capabilities::can_contribute() ) {
			wp_die( esc_html__( 'You cannot create a Blueprint.', 'blueprint-registry' ), 403 );
		}
		check_admin_referer( 'bp_create_change' );
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$change = Blueprint_Registry_Workflow::create_change( array( 'title' => $title, 'author_id' => get_current_user_id() ) );
		$this->redirect_result( $change, admin_url( 'admin.php?page=blueprint-registry' ), 'created' );
	}

	public function create_from_release() {
		if ( ! Blueprint_Registry_Capabilities::can_contribute() ) {
			wp_die( esc_html__( 'You cannot create a Blueprint change.', 'blueprint-registry' ), 403 );
		}
		$blueprint_id = isset( $_GET['blueprint_id'] ) ? (int) $_GET['blueprint_id'] : 0;
		check_admin_referer( 'bp_create_from_release_' . $blueprint_id );
		$mode = isset( $_GET['mode'] ) ? sanitize_key( $_GET['mode'] ) : 'fork';
		$blueprint = get_post( $blueprint_id );
		if ( ! $blueprint || 'blueprint' !== $blueprint->post_type || 'publish' !== $blueprint->post_status || ! in_array( $mode, array( 'fork', 'update' ), true ) ) {
			$this->redirect_result( new WP_Error( 'blueprint_missing_source', __( 'That published Blueprint is not available.', 'blueprint-registry' ) ), admin_url( 'admin.php?page=blueprint-registry' ) );
		}
		if ( 'update' === $mode && (int) $blueprint->post_author !== get_current_user_id() ) {
			$this->redirect_result( new WP_Error( 'blueprint_forbidden', __( 'Only the Blueprint author can edit it.', 'blueprint-registry' ) ), admin_url( 'admin.php?page=blueprint-registry' ) );
		}
		$target = 'update' === $mode ? $blueprint_id : 0;
		$title  = 'update' === $mode ? get_the_title( $blueprint_id ) : sprintf( __( '%s fork', 'blueprint-registry' ), get_the_title( $blueprint_id ) );
		$change = Blueprint_Registry_Workflow::create_change(
			array(
				'title'               => $title,
				'author_id'           => get_current_user_id(),
				'target_id'           => $target,
				'source_blueprint_id' => $blueprint_id,
				'change_type'         => $mode,
			)
		);
		if ( ! is_wp_error( $change ) && 'fork' === $mode ) {
			update_post_meta( $change, '_bp_forked_from_blueprint_id', $blueprint_id );
			update_post_meta( $change, '_bp_forked_from_release_id', get_post_meta( $blueprint_id, '_bp_current_release_id', true ) );
		}
		$this->redirect_result( $change, admin_url( 'admin.php?page=blueprint-registry' ), 'created' );
	}

	public function submit_change() {
		$change_id = isset( $_GET['change_id'] ) ? (int) $_GET['change_id'] : 0;
		check_admin_referer( 'bp_submit_change_' . $change_id );
		$result = Blueprint_Registry_Workflow::submit( $change_id );
		$this->redirect_result( $result, admin_url( 'admin.php?page=blueprint-registry' ), 'submitted' );
	}

	public function review_change() {
		$change_id     = isset( $_POST['change_id'] ) ? (int) $_POST['change_id'] : 0;
		$submission_id = isset( $_POST['submission_id'] ) ? (int) $_POST['submission_id'] : 0;
		check_admin_referer( 'bp_review_change_' . $change_id . '_' . $submission_id );
		$decision = isset( $_POST['decision'] ) ? sanitize_key( $_POST['decision'] ) : '';
		$note     = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		$result   = Blueprint_Registry_Workflow::review( $change_id, $decision, get_current_user_id(), $note, $submission_id );
		$this->redirect_result( $result, self::review_queue_url(), 'reviewed' );
	}

	public function preview_change() {
		$change_id = isset( $_GET['change_id'] ) ? (int) $_GET['change_id'] : 0;
		$submission_id = isset( $_GET['submission_id'] ) ? (int) $_GET['submission_id'] : 0;
		check_admin_referer( 'bp_preview_change_' . $change_id . ( $submission_id ? '_' . $submission_id : '' ) );
		$change = get_post( $change_id );
		if ( ! $change || (int) $change->post_author !== get_current_user_id() && ! Blueprint_Registry_Capabilities::can_review() ) {
			wp_die( esc_html__( 'You cannot preview this Blueprint.', 'blueprint-registry' ), 403 );
		}
		if ( $submission_id && ( $submission_id !== Blueprint_Registry_Workflow::current_submission_id( $change_id ) || $change_id !== (int) get_post_field( 'post_parent', $submission_id ) ) ) {
			wp_die( esc_html__( 'That submitted version is no longer available for review.', 'blueprint-registry' ), 404 );
		}
		$token = $submission_id ? Blueprint_Registry_Bundles::build_submission_preview( $submission_id ) : Blueprint_Registry_Bundles::build_preview( $change_id );
		if ( is_wp_error( $token ) ) {
			$this->redirect_result( $token, get_edit_post_link( $change_id, 'raw' ) );
		}
		$url = add_query_arg( 'token', rawurlencode( $token ), home_url( '/blueprints/drafts/' . ( $submission_id ?: $change_id ) . '/preview.zip' ) );
		wp_redirect( 'https://playground.wordpress.net/?blueprint-url=' . rawurlencode( $url ) );
		exit;
	}

	public function serve_bundle_file() {
		$change_id     = isset( $_GET['change_id'] ) ? (int) $_GET['change_id'] : 0;
		$attachment_id = isset( $_GET['attachment_id'] ) ? (int) $_GET['attachment_id'] : 0;
		check_admin_referer( 'bp_bundle_file_' . $change_id . '_' . $attachment_id );

		$change     = get_post( $change_id );
		$attachment = get_post( $attachment_id );
		$parent_id  = $attachment ? (int) $attachment->post_parent : 0;
		$submission = $parent_id ? get_post( $parent_id ) : null;
		$is_file_for_change = $attachment && 'attachment' === $attachment->post_type && ( $parent_id === $change_id || ( $submission && 'blueprint_submission' === $submission->post_type && (int) $submission->post_parent === $change_id ) );
		if ( ! $change || 'blueprint_change' !== $change->post_type || ! $is_file_for_change || ( (int) $change->post_author !== get_current_user_id() && ! Blueprint_Registry_Capabilities::can_review() ) ) {
			wp_die( esc_html__( 'You cannot read this bundle file.', 'blueprint-registry' ), '', array( 'response' => 403 ) );
		}

		$path = get_attached_file( $attachment_id );
		if ( ! is_readable( $path ) ) {
			wp_die( esc_html__( 'This bundle file is no longer available.', 'blueprint-registry' ), esc_html__( 'Not found', 'blueprint-registry' ), array( 'response' => 404 ) );
		}

		nocache_headers();
		header( 'Content-Type: ' . ( get_post_mime_type( $attachment_id ) ?: 'application/octet-stream' ) );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: ' . ( isset( $_GET['download'] ) ? 'attachment' : 'inline' ) . '; filename="' . sanitize_file_name( basename( $path ) ) . '"' );
		header( 'Cache-Control: private, no-store' );
		readfile( $path ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public function remove_change_file() {
		$change_id    = isset( $_GET['change_id'] ) ? (int) $_GET['change_id'] : 0;
		$attachment_id = isset( $_GET['attachment_id'] ) ? (int) $_GET['attachment_id'] : 0;
		check_admin_referer( 'bp_remove_file_' . $change_id );
		$change = get_post( $change_id );
		$status = $change ? get_post_meta( $change_id, '_bp_status', true ) ?: 'draft' : '';
		if ( ! $change || (int) $change->post_author !== get_current_user_id() || ! in_array( $status, array( 'draft', 'changes_requested' ), true ) ) {
			wp_die( esc_html__( 'You cannot remove this file.', 'blueprint-registry' ), 403 );
		}
		Blueprint_Registry_Bundles::remove_change_file( $change_id, $attachment_id );
		wp_delete_attachment( $attachment_id, true );
		wp_safe_redirect( get_edit_post_link( $change_id, 'raw' ) );
		exit;
	}

	public function notices() {
		if ( empty( $_GET['bp_notice'] ) ) {
			return;
		}
		$class = isset( $_GET['bp_error'] ) ? 'notice-error' : 'notice-success';
		$message = isset( $_GET['bp_message'] ) ? sanitize_text_field( wp_unslash( $_GET['bp_message'] ) ) : __( 'Blueprint action completed.', 'blueprint-registry' );
		printf( '<div class="notice %1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
	}

	public static function save_uploaded_file( $change_id ) {
		if ( empty( $_FILES['bp_bundle_file']['name'] ) || UPLOAD_ERR_NO_FILE === (int) $_FILES['bp_bundle_file']['error'] ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$upload = wp_handle_upload( $_FILES['bp_bundle_file'], array( 'test_form' => false, 'mimes' => self::allowed_mimes() ) );
		if ( ! empty( $upload['error'] ) ) {
			update_post_meta( $change_id, '_bp_upload_error', sanitize_text_field( $upload['error'] ) );
			return;
		}
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $upload['type'],
				'post_title'     => sanitize_file_name( basename( $upload['file'] ) ),
				'post_status'    => 'inherit',
			),
			$upload['file'],
			$change_id
		);
		if ( is_wp_error( $attachment_id ) ) {
			update_post_meta( $change_id, '_bp_upload_error', $attachment_id->get_error_message() );
			return;
		}
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
		$path = isset( $_POST['bp_bundle_path'] ) ? wp_unslash( $_POST['bp_bundle_path'] ) : basename( $upload['file'] );
		$path = '' === trim( $path ) ? basename( $upload['file'] ) : $path;
		$result = Blueprint_Registry_Bundles::add_change_file( $change_id, $attachment_id, $path );
		if ( is_wp_error( $result ) ) {
			wp_delete_attachment( $attachment_id, true );
			update_post_meta( $change_id, '_bp_upload_error', $result->get_error_message() );
		}
	}

	public static function allowed_mimes() {
		return array(
			'zip'           => 'application/zip',
			'json'          => 'application/json',
			'xml|wxr'       => 'application/xml',
			'txt|md|csv'    => 'text/plain',
			'jpg|jpeg'      => 'image/jpeg',
			'png'           => 'image/png',
			'webp'          => 'image/webp',
			'gif'           => 'image/gif',
		);
	}

	private function render_change_actions( $change ) {
		$status = get_post_meta( $change->ID, '_bp_status', true );
		if ( in_array( $status, array( 'draft', 'pending_review', 'changes_requested' ), true ) ) {
			$submit_url = wp_nonce_url( admin_url( 'admin-post.php?action=bp_submit_change&change_id=' . $change->ID ), 'bp_submit_change_' . $change->ID );
			printf( ' <a class="button button-primary" href="%1$s">%2$s</a>', esc_url( $submit_url ), esc_html__( 'Submit', 'blueprint-registry' ) );
		}
		$preview_url = wp_nonce_url( admin_url( 'admin-post.php?action=bp_preview_change&change_id=' . $change->ID ), 'bp_preview_change_' . $change->ID );
		printf( ' <a class="button" href="%1$s">%2$s</a>', esc_url( $preview_url ), esc_html__( 'Preview', 'blueprint-registry' ) );
	}

	private function render_review_actions( $change ) {
		printf( '<a class="button button-primary" href="%1$s">%2$s</a>', esc_url( get_edit_post_link( $change->ID, 'raw' ) ), esc_html__( 'Review submitted version', 'blueprint-registry' ) );
	}

	private static function status_label( $status ) {
		$labels = array(
			'draft'             => __( 'Draft', 'blueprint-registry' ),
			'pending_review'    => __( 'Pending review', 'blueprint-registry' ),
			'changes_requested' => __( 'Changes requested', 'blueprint-registry' ),
			'approved'          => __( 'Accepted', 'blueprint-registry' ),
			'accepted'          => __( 'Accepted', 'blueprint-registry' ),
			'rejected'          => __( 'Rejected', 'blueprint-registry' ),
		);

		return $labels[ $status ] ?? ucwords( str_replace( '_', ' ', $status ) );
	}

	private static function decision_label( $decision ) {
		$labels = array(
			'approved'          => __( 'Accepted', 'blueprint-registry' ),
			'changes_requested' => __( 'Changes requested', 'blueprint-registry' ),
			'rejected'          => __( 'Rejected', 'blueprint-registry' ),
		);

		return $labels[ $decision ] ?? __( 'Review update', 'blueprint-registry' );
	}

	private function redirect_result( $result, $fallback, $notice = '' ) {
		if ( is_wp_error( $result ) ) {
			$url = add_query_arg( array( 'bp_notice' => 1, 'bp_error' => 1, 'bp_message' => rawurlencode( $result->get_error_message() ) ), $fallback );
		} else {
			$url = add_query_arg( array( 'bp_notice' => 1, 'bp_message' => rawurlencode( __( 'Blueprint action completed.', 'blueprint-registry' ) ) ), $fallback );
			if ( 'created' === $notice && is_int( $result ) ) {
				$url = get_edit_post_link( $result, 'raw' );
			}
		}
		wp_safe_redirect( $url );
		exit;
	}
}
