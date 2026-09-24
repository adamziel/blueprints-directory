<?php

defined( 'ABSPATH' ) || exit;

final class Blueprint_Registry_Frontend {
	public function __construct() {
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		add_filter( 'template_include', array( $this, 'management_template' ) );
		add_action( 'template_redirect', array( $this, 'handle_actions' ), 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'redirect_contributors_from_admin' ), 1 );
		add_filter( 'show_admin_bar', array( $this, 'hide_contributor_admin_bar' ) );
	}

	public function add_rewrite_rules() {
		self::register_rewrite_rules();
	}

	public static function register_rewrite_rules() {
		add_rewrite_rule( '^blueprints/manage/review/?$', 'index.php?bp_manage=review', 'top' );
		add_rewrite_rule( '^blueprints/manage/([0-9]+)/review/?$', 'index.php?bp_manage=submission_review&bp_change_id=$matches[1]', 'top' );
		add_rewrite_rule( '^blueprints/manage/new/?$', 'index.php?bp_manage=edit_new', 'top' );
		add_rewrite_rule( '^blueprints/manage/([0-9]+)/?$', 'index.php?bp_manage=edit&bp_change_id=$matches[1]', 'top' );
		add_rewrite_rule( '^blueprints/manage/?$', 'index.php?bp_manage=dashboard', 'top' );
	}

	public function add_query_vars( $vars ) {
		$vars[] = 'bp_manage';
		$vars[] = 'bp_change_id';
		$vars[] = 'bp_source_blueprint_id';
		$vars[] = 'bp_new_mode';
		return $vars;
	}

	public function management_template( $template ) {
		if ( self::is_management_page() ) {
			return BLUEPRINT_REGISTRY_DIR . 'templates/manage-blueprints.php';
		}

		return $template;
	}

	public function enqueue_assets() {
		if ( ! self::is_management_page() ) {
			return;
		}

		Blueprint_Registry_Assets::enqueue_workspace();
		Blueprint_Registry_Assets::enqueue_code_editor();
	}

	public function redirect_contributors_from_admin() {
		if ( wp_doing_ajax() || ! is_user_logged_in() || Blueprint_Registry_Capabilities::can_review() ) {
			return;
		}

		wp_safe_redirect( self::dashboard_url() );
		exit;
	}

	public function hide_contributor_admin_bar( $show ) {
		if ( is_user_logged_in() && ! Blueprint_Registry_Capabilities::can_review() ) {
			return false;
		}

		return $show;
	}

	public function handle_actions() {
		if ( 'review' === get_query_var( 'bp_manage' ) ) {
			wp_safe_redirect( Blueprint_Registry_Capabilities::can_review() ? Blueprint_Registry_Admin::review_queue_url() : self::dashboard_url() );
			exit;
		}

		if ( 'edit_new' === get_query_var( 'bp_manage' ) && 'update' === get_query_var( 'bp_new_mode' ) && (int) get_query_var( 'bp_source_blueprint_id' ) && is_user_logged_in() && 'GET' === strtoupper( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			$changes = get_posts( array(
				'post_type' => 'blueprint_change',
				'post_status' => 'draft',
				'author' => get_current_user_id(),
				'posts_per_page' => 1,
				'orderby' => array( 'modified' => 'DESC', 'ID' => 'DESC' ),
				'meta_query' => array(
					array( 'key' => '_bp_target_blueprint_id', 'value' => (int) get_query_var( 'bp_source_blueprint_id' ) ),
					array( 'key' => '_bp_status', 'value' => array( 'draft', 'pending_review', 'changes_requested' ), 'compare' => 'IN' ),
				),
			) );
			if ( $changes ) {
				wp_safe_redirect( self::edit_url( $changes[0]->ID ) );
				exit;
			}
		}

		if ( ! self::is_management_page() || empty( $_REQUEST['bp_action'] ) ) {
			return;
		}

		if ( ! Blueprint_Registry_Capabilities::can_contribute() ) {
			wp_safe_redirect( wp_login_url( self::current_url() ) );
			exit;
		}

		$action = sanitize_key( wp_unslash( $_REQUEST['bp_action'] ) );
		if ( 'create' === $action ) {
			$this->create_change();
		}
		if ( 'create_from_release' === $action ) {
			$this->create_from_release();
		}
		if ( 'create_and_review' === $action ) {
			$this->create_new_change();
		}
		if ( 'save' === $action ) {
			$this->save_change();
		}
		if ( 'save_and_review' === $action ) {
			$this->save_change( true );
		}
		if ( 'submit' === $action ) {
			$this->submit_change();
		}
		if ( 'preview' === $action ) {
			$this->preview_change();
		}
		if ( 'refresh_base' === $action ) {
			$this->refresh_base_release();
		}
		if ( 'delete_draft' === $action ) {
			$this->delete_draft();
		}
	}

	public static function dashboard_url( array $args = array() ) {
		return add_query_arg( $args, home_url( '/blueprints/manage/' ) );
	}

	public static function edit_url( $change_id, array $args = array() ) {
		return add_query_arg( $args, home_url( '/blueprints/manage/' . (int) $change_id . '/' ) );
	}

	public static function submission_review_url( $change_id, array $args = array() ) {
		return add_query_arg( $args, home_url( '/blueprints/manage/' . (int) $change_id . '/review/' ) );
	}

	public static function new_editor_url( $source_blueprint_id = 0, $mode = 'new' ) {
		$args = array();
		if ( $source_blueprint_id ) {
			$args['bp_source_blueprint_id'] = (int) $source_blueprint_id;
			$args['bp_new_mode']            = sanitize_key( $mode );
		}

		return add_query_arg( $args, home_url( '/blueprints/manage/new/' ) );
	}

	public static function render_page() {
		// Redirect completed comparisons before the document shell sends headers.
		if ( 'submission_review' === get_query_var( 'bp_manage' ) ) {
			$change = get_post( (int) get_query_var( 'bp_change_id' ) );
			if ( self::can_edit_change( $change ) && in_array( self::change_status( $change->ID ), array( 'accepted', 'rejected' ), true ) ) {
				wp_safe_redirect( self::edit_url( $change->ID ) );
				exit;
			}
		}
		Blueprint_Registry_Ui::open( 'bp-screen-workspace' );
		echo '<main class="bp-app__main" id="main-content">';

		if ( ! Blueprint_Registry_Capabilities::can_contribute() ) {
			self::render_signed_out();
		} else {
			switch ( get_query_var( 'bp_manage' ) ) {
				case 'edit_new':
					self::render_new_editor();
					break;
				case 'edit':
					self::render_editor( (int) get_query_var( 'bp_change_id' ) );
					break;
				case 'submission_review':
					self::render_submission_review( (int) get_query_var( 'bp_change_id' ) );
					break;
				default:
					self::render_dashboard();
			}
		}

		echo '</main>';
		Blueprint_Registry_Ui::close();
	}

	private static function render_signed_out() {
		?>
		<div class="bp-panel">
			<?php
			Blueprint_Registry_Ui::empty_state(
				'fork',
				__( 'Sign in to contribute', 'blueprint-registry' ),
				__( 'Sign in to create a Blueprint or make your own copy of one from the gallery.', 'blueprint-registry' ),
				sprintf(
					'<a class="bp-btn bp-btn--primary" href="%s">%s</a>',
					esc_url( wp_login_url( self::current_url() ) ),
					esc_html__( 'Sign in', 'blueprint-registry' )
				)
			);
			?>
		</div>
		<?php
	}

	private function create_change() {
		$this->require_post();
		check_admin_referer( 'bp_front_create' );
		wp_safe_redirect( self::new_editor_url() );
		exit;
	}

	private function create_from_release() {
		$blueprint_id = isset( $_GET['blueprint_id'] ) ? (int) $_GET['blueprint_id'] : 0;
		check_admin_referer( 'bp_front_create_from_release_' . $blueprint_id );
		$mode   = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : 'fork';
		$blueprint = get_post( $blueprint_id );
		if ( ! $blueprint || 'blueprint' !== $blueprint->post_type || 'publish' !== $blueprint->post_status || ! in_array( $mode, array( 'fork', 'update' ), true ) ) {
			$this->redirect_result( new WP_Error( 'blueprint_missing_source', __( 'That published Blueprint is not available.', 'blueprint-registry' ) ), self::dashboard_url() );
		}
		if ( 'update' === $mode && ! Blueprint_Registry_Capabilities::can_edit_blueprint( $blueprint_id ) ) {
			$this->redirect_result( new WP_Error( 'blueprint_forbidden', __( 'Only the Blueprint author or a reviewer can edit it.', 'blueprint-registry' ) ), self::dashboard_url() );
		}
		wp_safe_redirect( self::new_editor_url( $blueprint_id, $mode ) );
		exit;
	}

	private function create_new_change() {
		$this->require_post();
		$source_blueprint_id = isset( $_POST['bp_source_blueprint_id'] ) ? (int) $_POST['bp_source_blueprint_id'] : 0;
		$mode                = isset( $_POST['bp_new_mode'] ) ? sanitize_key( wp_unslash( $_POST['bp_new_mode'] ) ) : 'new';
		check_admin_referer( 'bp_front_create_new_' . $source_blueprint_id . '_' . $mode );
		$context = self::new_editor_context( $source_blueprint_id, $mode );
		if ( is_wp_error( $context ) ) {
			$this->redirect_result( $context, self::dashboard_url() );
		}

		$change = Blueprint_Registry_Workflow::create_change(
			array(
				'title'               => $context['title'],
				'author_id'           => get_current_user_id(),
				'target_id'           => 'update' === $context['mode'] ? $context['source_blueprint_id'] : 0,
				'source_blueprint_id' => $context['source_blueprint_id'],
				'change_type'         => $context['mode'],
			)
		);
		if ( is_wp_error( $change ) ) {
			$this->redirect_result( $change, self::new_editor_url( $context['source_blueprint_id'], $context['mode'] ) );
		}

		if ( 'fork' === $context['mode'] ) {
			update_post_meta( $change, '_bp_forked_from_blueprint_id', $context['source_blueprint_id'] );
			update_post_meta( $change, '_bp_forked_from_release_id', get_post_meta( $context['source_blueprint_id'], '_bp_current_release_id', true ) );
		}

		if ( isset( $_POST['bp_blueprint_json'] ) ) {
			Blueprint_Registry_Workflow::set_source( $change, wp_unslash( $_POST['bp_blueprint_json'] ) );
		}
		Blueprint_Registry_Admin::save_uploaded_file( $change );
		$upload_error = get_post_meta( $change, '_bp_upload_error', true );
		if ( $upload_error ) {
			$this->redirect_result( new WP_Error( 'blueprint_upload_failed', $upload_error ), self::edit_url( $change ) );
		}
		$errors = Blueprint_Registry_Validator::validate_change( $change );
		if ( $errors ) {
			$this->redirect_result( new WP_Error( 'blueprint_invalid_change', __( 'Fix the validation errors before reviewing this Blueprint.', 'blueprint-registry' ) ), self::edit_url( $change ) );
		}

		wp_safe_redirect( self::submission_review_url( $change ) );
		exit;
	}

	private function delete_draft() {
		$this->require_post();
		$change = $this->change_from_request();
		check_admin_referer( 'bp_front_delete_' . $change->ID );
		if ( 'draft' !== self::change_status( $change->ID ) ) {
			$this->redirect_result( new WP_Error( 'blueprint_not_draft', __( 'Only drafts can be removed.', 'blueprint-registry' ) ), self::dashboard_url() );
		}

		foreach ( Blueprint_Registry_Bundles::get_change_files( $change->ID ) as $file ) {
			Blueprint_Registry_Storage::delete( $change->ID, $file );
		}
		wp_delete_post( $change->ID, true );
		$this->redirect_result( true, self::dashboard_url(), null, __( 'Blueprint draft removed.', 'blueprint-registry' ) );
	}

	private function save_change( $continue_to_review = false ) {
		$this->require_post();
		$change = $this->change_from_request();
		check_admin_referer( 'bp_front_save_' . $change->ID );

		if ( ! in_array( self::change_status( $change->ID ), array( 'draft', 'pending_review', 'changes_requested' ), true ) ) {
			$this->redirect_result( new WP_Error( 'blueprint_not_editable', __( 'This proposal can no longer be edited.', 'blueprint-registry' ) ), self::edit_url( $change->ID ) );
		}

		if ( isset( $_POST['bp_blueprint_json'] ) ) {
			Blueprint_Registry_Workflow::set_source( $change->ID, wp_unslash( $_POST['bp_blueprint_json'] ) );
		}

		if ( isset( $_POST['bp_remove_file'] ) ) {
			$removed = $this->remove_change_file( $change, sanitize_text_field( wp_unslash( $_POST['bp_remove_file'] ) ) );
			if ( is_wp_error( $removed ) ) {
				$this->redirect_result( $removed, self::edit_url( $change->ID ) );
			}
		}

		if ( isset( $_POST['bp_discard_upload'] ) ) {
			delete_post_meta( $change->ID, '_bp_upload_error' );
		} else {
			Blueprint_Registry_Admin::save_uploaded_file( $change->ID );
		}
		$errors = Blueprint_Registry_Validator::validate_change( $change->ID );
		$upload_error = get_post_meta( $change->ID, '_bp_upload_error', true );
		if ( $upload_error || ( $continue_to_review && $errors ) ) {
			$this->redirect_result( new WP_Error( 'blueprint_invalid_change', $upload_error ?: __( 'Changes saved. Fix the problems below to continue.', 'blueprint-registry' ) ), self::edit_url( $change->ID ) );
		}
		if ( isset( $_POST['bp_remove_file'] ) ) {
			$this->redirect_result( true, self::edit_url( $change->ID ), null, __( 'Changes saved. Bundle file removed.', 'blueprint-registry' ) );
		}
		if ( $continue_to_review ) {
			wp_safe_redirect( self::submission_review_url( $change->ID ) );
			exit;
		}
		$this->redirect_result( true, self::edit_url( $change->ID ), null, __( 'Changes saved.', 'blueprint-registry' ) );
	}

	private function submit_change() {
		$this->require_post();
		$change = $this->change_from_request();
		check_admin_referer( 'bp_front_submit_' . $change->ID );
		// A second tab may have changed the draft since this comparison was opened.
		$reviewed = isset( $_POST['bp_reviewed_content'] ) ? sanitize_text_field( wp_unslash( $_POST['bp_reviewed_content'] ) ) : '';
		if ( ! hash_equals( self::review_fingerprint( $change->ID ), $reviewed ) ) {
			$this->redirect_result( new WP_Error( 'blueprint_review_changed', __( 'This draft changed in another tab. Check the updated contents before submitting.', 'blueprint-registry' ) ), self::submission_review_url( $change->ID ) );
		}
		if ( get_post_meta( $change->ID, '_bp_upload_error', true ) ) {
			$this->redirect_result( new WP_Error( 'blueprint_upload_failed', __( 'Resolve the file upload error before submitting.', 'blueprint-registry' ) ), self::edit_url( $change->ID ) );
		}
		$result = Blueprint_Registry_Workflow::submit( $change->ID );
		if ( is_wp_error( $result ) ) {
			$this->redirect_result( $result, self::edit_url( $change->ID ) );
		}
		wp_safe_redirect( self::edit_url( $change->ID ) );
		exit;
	}

	private function preview_change() {
		$this->require_post();
		$change = $this->change_from_request();
		check_admin_referer( 'bp_front_preview_' . $change->ID );
		if ( isset( $_POST['bp_reviewed_content'] ) && ! hash_equals( self::review_fingerprint( $change->ID ), sanitize_text_field( wp_unslash( $_POST['bp_reviewed_content'] ) ) ) ) {
			$this->redirect_result( new WP_Error( 'blueprint_preview_changed', __( 'This draft changed in another tab. Check the updated contents before running a preview.', 'blueprint-registry' ) ), self::submission_review_url( $change->ID ) );
		}
		$token = Blueprint_Registry_Bundles::build_preview( $change->ID );
		if ( is_wp_error( $token ) ) {
			$this->redirect_result( $token, self::edit_url( $change->ID ) );
		}

		$url = add_query_arg( 'token', rawurlencode( $token ), home_url( '/blueprints/drafts/' . $change->ID . '/preview.zip' ) );
		wp_redirect( 'https://playground.wordpress.net/?blueprint-url=' . rawurlencode( $url ) );
		exit;
	}

	private function refresh_base_release() {
		$this->require_post();
		$change = $this->change_from_request();
		check_admin_referer( 'bp_front_refresh_base_' . $change->ID );
		$result = Blueprint_Registry_Workflow::refresh_base_release( $change->ID );
		$this->redirect_result( $result, self::submission_review_url( $change->ID ), null, __( 'The proposal now compares with the current revision. Review the diff before submitting again.', 'blueprint-registry' ) );
	}

	private function remove_change_file( $change, $key ) {
		$keys = wp_list_pluck( Blueprint_Registry_Bundles::get_change_files( $change->ID ), 'key' );
		if ( ! in_array( (string) $key, array_map( 'strval', $keys ), true ) ) {
			return new WP_Error( 'blueprint_missing_file', __( 'That bundle file does not belong to this draft.', 'blueprint-registry' ) );
		}

		Blueprint_Registry_Bundles::remove_change_file( $change->ID, $key );
		return true;
	}

	private function change_from_request() {
		$change_id = isset( $_POST['change_id'] ) ? (int) $_POST['change_id'] : 0;
		$change    = get_post( $change_id );
		if ( ! self::can_edit_change( $change ) ) {
			wp_die( esc_html__( 'You cannot manage this Blueprint draft.', 'blueprint-registry' ), '', array( 'response' => 403 ) );
		}

		return $change;
	}

	private function require_post() {
		if ( 'POST' !== strtoupper( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'This action requires a form submission.', 'blueprint-registry' ), '', array( 'response' => 405 ) );
		}
	}

	private function redirect_result( $result, $fallback, $success_url = null, $success_message = null ) {
		if ( is_wp_error( $result ) ) {
			$url = add_query_arg(
				array(
					'bp_notice'  => 'error',
					'bp_message' => $result->get_error_message(),
				),
				$fallback
			);
		} else {
			$url = add_query_arg(
				array(
					'bp_notice'  => 'success',
					'bp_message' => $success_message ?: __( 'Blueprint action completed.', 'blueprint-registry' ),
				),
				$success_url ?: $fallback
			);
		}

		wp_safe_redirect( $url );
		exit;
	}

	private static function render_new_editor() {
		$context = self::new_editor_context( (int) get_query_var( 'bp_source_blueprint_id' ), (string) get_query_var( 'bp_new_mode' ) );
		if ( is_wp_error( $context ) ) {
			wp_die( esc_html( $context->get_error_message() ), '', array( 'response' => 403 ) );
		}

		$source_id      = $context['source_blueprint_id'];
		$release_id     = $source_id ? (int) get_post_meta( $source_id, '_bp_current_release_id', true ) : 0;
		$release_number = $release_id ? (int) get_post_meta( $release_id, '_bp_release_number', true ) : 0;
		$inherited      = array();

		if ( $release_id ) {
			$entries = Blueprint_Registry_Bundles::release_file_entries( $release_id );
			foreach ( is_wp_error( $entries ) ? array() : $entries as $entry ) {
				// blueprint.json is the editor's contents, not a bundle file.
				if ( 'blueprint.json' === $entry['path'] ) {
					continue;
				}
				$inherited[] = array(
					'path' => $entry['path'],
					'size' => (int) $entry['size'],
					'url'  => Blueprint_Registry_Routes::release_file_url( $source_id, $release_number, $entry['path'] ),
				);
			}
		}

		$intro     = array(
			'new'    => __( 'Edit the JSON, then check your changes before submitting.', 'blueprint-registry' ),
			'fork'   => __( 'Your own copy. The original stays unchanged.', 'blueprint-registry' ),
			'update' => __( 'Changes go through review. The published Blueprint stays unchanged.', 'blueprint-registry' ),
		);
		?>
		<a class="bp-back" href="<?php echo esc_url( $source_id ? get_permalink( $source_id ) : self::dashboard_url() ); ?>">
			<?php echo Blueprint_Registry_Ui::icon( 'arrow-left' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php echo esc_html( $source_id ? get_the_title( $source_id ) : __( 'My work', 'blueprint-registry' ) ); ?>
		</a>

		<div class="bp-page-head">
			<div class="bp-page-head__text">
				<h1><?php echo esc_html( $context['heading'] ); ?></h1>
				<div class="bp-page-head__meta">
					<?php if ( 'fork' === $context['mode'] ) : ?>
						<span class="bp-chip bp-chip--fork"><?php esc_html_e( 'Fork', 'blueprint-registry' ); ?></span>
					<?php elseif ( 'update' === $context['mode'] ) : ?>
						<span class="bp-chip bp-chip--draft"><?php esc_html_e( 'Update', 'blueprint-registry' ); ?></span>
					<?php else : ?>
						<span class="bp-chip bp-chip--draft"><?php esc_html_e( 'New Blueprint', 'blueprint-registry' ); ?></span>
					<?php endif; ?>
					<span class="bp-panel__head-note"><?php echo esc_html( $intro[ $context['mode'] ] ); ?></span>
				</div>
			</div>
			<div class="bp-page-head__actions">
				<button class="bp-btn bp-btn--primary" type="submit" form="bp-new-editor">
					<?php echo Blueprint_Registry_Ui::icon( 'diff' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php esc_html_e( 'Review changes', 'blueprint-registry' ); ?>
				</button>
			</div>
		</div>

		<?php Blueprint_Registry_Ui::notice(); ?>

		<form id="bp-new-editor" class="bp-editor" method="post" action="<?php echo esc_url( self::new_editor_url( $source_id, $context['mode'] ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="bp_action" value="create_and_review">
			<input type="hidden" name="bp_source_blueprint_id" value="<?php echo esc_attr( $source_id ); ?>">
			<input type="hidden" name="bp_new_mode" value="<?php echo esc_attr( $context['mode'] ); ?>">
			<?php wp_nonce_field( 'bp_front_create_new_' . $source_id . '_' . $context['mode'] ); ?>

			<div class="bp-editor__aside">
				<?php
				self::render_upload_panel(
					__( 'The path is relative to the bundle root.', 'blueprint-registry' ),
					true,
					0,
					$inherited,
					$release_number
				);
				?>
				<?php if ( 'update' === $context['mode'] ) : ?>
					<?php self::render_revision_history( $source_id ); ?>
				<?php endif; ?>
			</div>

			<div class="bp-editor__slot">
				<section class="bp-panel">
					<div class="bp-panel__head">
						<h2><?php esc_html_e( 'blueprint.json', 'blueprint-registry' ); ?></h2>
						<span class="bp-panel__head-note" data-bp-save-state role="status"><?php esc_html_e( 'Changes are saved when you choose Review changes', 'blueprint-registry' ); ?></span>
					</div>
					<div class="bp-panel__body">
						<label class="bp-visually-hidden" for="bp_blueprint_json"><?php esc_html_e( 'Blueprint JSON', 'blueprint-registry' ); ?></label>
						<textarea class="bp-input" id="bp_blueprint_json" name="bp_blueprint_json" rows="26" spellcheck="false"><?php echo esc_textarea( $context['source'] ); ?></textarea>
					</div>
				</section>
			</div>
		</form>
		<?php
	}

	/**
	 * The bundle-file uploader, shared by the new and existing editors.
	 */
	private static function render_upload_panel( $hint, $editable, $change_id = 0, array $inherited = array(), $inherited_from = 0 ) {
		$upload_error = $change_id ? get_post_meta( $change_id, '_bp_upload_error', true ) : '';
		$count        = $change_id ? count( Blueprint_Registry_Bundles::get_change_files( $change_id ) ) : count( $inherited );
		?>
		<section class="bp-panel" id="bp-bundle-panel">
			<div class="bp-panel__head">
				<h2><?php esc_html_e( 'Bundle files', 'blueprint-registry' ); ?></h2>
				<?php if ( $count ) : ?><span class="bp-panel__head-note"><?php echo esc_html( $count ); ?></span><?php endif; ?>
			</div>
			<?php if ( $change_id ) : ?>
				<?php self::render_change_files( $change_id, $editable ); ?>
			<?php elseif ( $inherited ) : ?>
				<ul class="bp-bundle-list">
					<?php foreach ( $inherited as $file ) : ?>
						<li>
							<?php echo Blueprint_Registry_Ui::icon( Blueprint_Registry_Ui::file_icon( $file['path'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<a class="bp-bundle-list__name" href="<?php echo esc_url( $file['url'] ); ?>" title="<?php echo esc_attr( $file['path'] ); ?>"><?php echo esc_html( $file['path'] ); ?></a>
							<span class="bp-bundle-list__size"><?php echo esc_html( size_format( $file['size'] ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
				<p class="bp-bundle-list__note">
					<?php
					echo esc_html(
						$inherited_from
							/* translators: %d: revision number the files come from. */
							? sprintf( __( 'Included from revision %d. Review changes to save your copy.', 'blueprint-registry' ), $inherited_from )
							: __( 'Carried over from the current revision.', 'blueprint-registry' )
					);
					?>
				</p>
			<?php endif; ?>
			<?php if ( $editable ) : ?>
				<div class="bp-panel__body">
					<?php if ( $upload_error ) : ?>
						<div class="bp-field">
							<p class="bp-field__hint"><?php esc_html_e( 'Choose the files again to retry, or continue without this upload.', 'blueprint-registry' ); ?></p>
							<button class="bp-btn bp-btn--sm" type="submit" name="bp_discard_upload" value="1"><?php esc_html_e( 'Continue without upload', 'blueprint-registry' ); ?></button>
						</div>
					<?php endif; ?>
					<div class="bp-field">
						<label class="bp-field__label" for="bp_bundle_source"><?php esc_html_e( 'Add files', 'blueprint-registry' ); ?></label>
						<input class="bp-input" type="file" id="bp_bundle_source" multiple data-bp-bundle-source>
						<input type="file" id="bp_bundle_file" name="bp_bundle_file" accept=".zip,application/zip" data-bp-bundle-archive hidden>
						<p class="bp-field__hint" data-bp-bundle-status aria-live="polite"><?php esc_html_e( 'Any file type. Files are added when you review changes.', 'blueprint-registry' ); ?></p>
					</div>
					<details class="bp-upload-options">
						<summary><?php esc_html_e( 'Choose a folder or filename', 'blueprint-registry' ); ?></summary>
					<div class="bp-field">
						<label class="bp-field__label" for="bp_bundle_path"><?php esc_html_e( 'Bundle path', 'blueprint-registry' ); ?></label>
						<input class="bp-input" type="text" id="bp_bundle_path" name="bp_bundle_path" placeholder="content/demo.xml">
						<p class="bp-field__hint"><?php echo esc_html( $hint ); ?> <?php esc_html_e( 'For several files, it is their shared folder.', 'blueprint-registry' ); ?></p>
					</div>
					</details>
					<noscript><p class="bp-field__hint"><?php esc_html_e( 'Bundle uploads need JavaScript so the selected files can be packed into a ZIP.', 'blueprint-registry' ); ?></p></noscript>
				</div>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function new_editor_context( $source_blueprint_id, $mode ) {
		$source_blueprint_id = (int) $source_blueprint_id;
		$mode                = sanitize_key( $mode ) ?: 'new';
		if ( ! $source_blueprint_id ) {
			if ( 'new' !== $mode ) {
				return new WP_Error( 'blueprint_missing_source', __( 'That published Blueprint is not available.', 'blueprint-registry' ) );
			}

			return array(
				'heading'             => __( 'New Blueprint', 'blueprint-registry' ),
				'mode'                => 'new',
				'source_blueprint_id' => 0,
				'source'              => Blueprint_Registry_Workflow::default_source( __( 'Untitled Blueprint', 'blueprint-registry' ), wp_get_current_user()->user_login ),
				'title'               => __( 'Untitled Blueprint', 'blueprint-registry' ),
			);
		}

		$blueprint = get_post( $source_blueprint_id );
		if ( ! $blueprint || 'blueprint' !== $blueprint->post_type || 'publish' !== $blueprint->post_status || ! in_array( $mode, array( 'fork', 'update' ), true ) ) {
			return new WP_Error( 'blueprint_missing_source', __( 'That published Blueprint is not available.', 'blueprint-registry' ) );
		}
		if ( 'update' === $mode && ! Blueprint_Registry_Capabilities::can_edit_blueprint( $source_blueprint_id ) ) {
			return new WP_Error( 'blueprint_forbidden', __( 'Only the Blueprint author or a reviewer can edit it.', 'blueprint-registry' ) );
		}

		$release_id = (int) get_post_meta( $source_blueprint_id, '_bp_current_release_id', true );
		if ( ! $release_id ) {
			return new WP_Error( 'blueprint_missing_release', __( 'This Blueprint has no published revision to start from.', 'blueprint-registry' ) );
		}

		return array(
			'heading'             => 'fork' === $mode ? sprintf( __( 'Fork %s', 'blueprint-registry' ), get_the_title( $blueprint ) ) : get_the_title( $blueprint ),
			'mode'                => $mode,
			'source_blueprint_id' => $source_blueprint_id,
			'source'              => (string) get_post_field( 'post_content', $release_id ),
			'title'               => get_the_title( $blueprint ),
		);
	}

	private static function render_dashboard() {
		$items  = self::dashboard_items( get_current_user_id() );
		$filter = isset( $_GET['bp_filter'] ) ? sanitize_key( wp_unslash( $_GET['bp_filter'] ) ) : 'all';
		$rows   = array_map( array( __CLASS__, 'dashboard_row' ), $items );
		$counts = array(
			'all'       => count( $rows ),
			'attention' => 0,
			'review'    => 0,
			'draft'     => 0,
			'published' => 0,
		);

		foreach ( $rows as $row ) {
			foreach ( $row['buckets'] as $bucket ) {
				$counts[ $bucket ]++;
			}
		}

		$visible = array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $filter ) {
					return 'all' === $filter || in_array( $filter, $row['buckets'], true );
				}
			)
		);

		$tabs = array(
			'all'       => __( 'All', 'blueprint-registry' ),
			'attention' => __( 'Needs your attention', 'blueprint-registry' ),
			'review'    => __( 'In review', 'blueprint-registry' ),
			'draft'     => __( 'Drafts', 'blueprint-registry' ),
			'published' => __( 'Published', 'blueprint-registry' ),
		);
		?>
		<div class="bp-page-head">
			<div class="bp-page-head__text">
				<h1><?php esc_html_e( 'My work', 'blueprint-registry' ); ?></h1>
				<p class="bp-page-head__sub"><?php esc_html_e( 'Your Blueprints, with the latest work on each one.', 'blueprint-registry' ); ?></p>
			</div>
			<div class="bp-page-head__actions">
				<a class="bp-btn bp-btn--primary" href="<?php echo esc_url( self::new_editor_url() ); ?>">
					<?php echo Blueprint_Registry_Ui::icon( 'plus' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php esc_html_e( 'New Blueprint', 'blueprint-registry' ); ?>
				</a>
			</div>
		</div>

		<?php Blueprint_Registry_Ui::notice(); ?>

		<?php if ( $counts['attention'] ) : ?>
			<div class="bp-notice bp-notice--warn">
				<?php echo Blueprint_Registry_Ui::icon( 'alert' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<div>
					<strong><?php echo esc_html( sprintf( _n( 'A reviewer asked for changes on %d proposal.', 'A reviewer asked for changes on %d proposals.', $counts['attention'], 'blueprint-registry' ), $counts['attention'] ) ); ?></strong>
					<?php esc_html_e( 'Open the feedback to continue.', 'blueprint-registry' ); ?>
				</div>
			</div>
		<?php endif; ?>

		<?php if ( $rows ) : ?>
			<nav class="bp-segments" aria-label="<?php esc_attr_e( 'Filter by status', 'blueprint-registry' ); ?>">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<?php if ( ! $counts[ $key ] && 'all' !== $key ) : ?><?php continue; ?><?php endif; ?>
					<a
						href="<?php echo esc_url( self::dashboard_url( 'all' === $key ? array() : array( 'bp_filter' => $key ) ) ); ?>"
						class="<?php echo 'attention' === $key ? 'is-attention' : ''; ?>"
						<?php echo $filter === $key ? 'aria-current="page"' : ''; ?>
					>
						<?php echo esc_html( $label ); ?>
						<span class="bp-segments__count"><?php echo esc_html( $counts[ $key ] ); ?></span>
					</a>
				<?php endforeach; ?>
			</nav>
		<?php endif; ?>

		<div class="bpv">
			<?php if ( ! $visible ) : ?>
				<?php
				Blueprint_Registry_Ui::empty_state(
					$rows ? 'search' : 'sparkle',
					$rows ? __( 'Nothing in this view', 'blueprint-registry' ) : __( 'Start your first Blueprint', 'blueprint-registry' ),
					$rows
						? __( 'Switch to another status to see the rest of your work.', 'blueprint-registry' )
						: __( 'Write one from scratch, or open any Blueprint in the gallery and fork it.', 'blueprint-registry' ),
					sprintf( '<a class="bp-btn" href="%s">%s</a>', esc_url( $rows ? self::dashboard_url() : get_post_type_archive_link( 'blueprint' ) ), $rows ? esc_html__( 'All work', 'blueprint-registry' ) : esc_html__( 'Explore the gallery', 'blueprint-registry' ) )
				);
				?>
			<?php else : ?>
				<div class="bpv__table-wrap">
					<table class="bpv__table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Blueprint', 'blueprint-registry' ); ?></th>
								<th><?php esc_html_e( 'Status', 'blueprint-registry' ); ?></th>
								<th><?php esc_html_e( 'Kind', 'blueprint-registry' ); ?></th>
								<th><?php esc_html_e( 'Updated', 'blueprint-registry' ); ?></th>
								<th><span class="bp-visually-hidden"><?php esc_html_e( 'Actions', 'blueprint-registry' ); ?></span></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $visible as $row ) : ?>
								<tr<?php echo $row['blueprint_id'] ? ' data-bp-blueprint-id="' . esc_attr( $row['blueprint_id'] ) . '"' : ''; ?>>
									<td>
										<div class="bpv__primary">
											<span class="bpv__thumb">
												<?php if ( $row['thumbnail_id'] ) : ?>
													<?php echo wp_get_attachment_image( $row['thumbnail_id'], 'medium', false, array( 'loading' => 'lazy', 'alt' => '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
												<?php else : ?>
													<span class="bpv__thumb-letter"><?php echo esc_html( strtoupper( substr( $row['title'], 0, 1 ) ) ); ?></span>
												<?php endif; ?>
											</span>
											<span class="bpv__primary-text">
												<a class="bpv__title" href="<?php echo esc_url( $row['edit_url'] ); ?>"><?php echo esc_html( $row['title'] ); ?></a>
												<?php if ( $row['description'] ) : ?><span class="bpv__subtitle"><?php echo esc_html( $row['description'] ); ?></span><?php endif; ?>
											</span>
										</div>
									</td>
									<td class="bpv__cell--tight"><?php Blueprint_Registry_Ui::chip( $row['status'], self::status_label( $row['status'] ) ); ?></td>
									<td class="bpv__cell--tight"><?php echo esc_html( $row['kind'] ); ?></td>
									<td class="bpv__cell--tight"><?php echo esc_html( $row['modified'] ); ?></td>
									<td class="bpv__cell--tight">
										<span class="bp-row-actions">
											<?php if ( $row['delete_id'] ) : ?>
												<form class="bp-inline-form" method="post" action="<?php echo esc_url( self::dashboard_url() ); ?>" data-bp-confirm="<?php esc_attr_e( 'Remove this draft? This cannot be undone.', 'blueprint-registry' ); ?>">
													<input type="hidden" name="bp_action" value="delete_draft">
													<input type="hidden" name="change_id" value="<?php echo esc_attr( $row['delete_id'] ); ?>">
													<?php wp_nonce_field( 'bp_front_delete_' . $row['delete_id'] ); ?>
													<button class="bp-btn bp-btn--sm bp-btn--quiet-danger" type="submit" aria-label="<?php echo esc_attr( sprintf( __( 'Remove %s', 'blueprint-registry' ), $row['title'] ) ); ?>">
														<?php echo Blueprint_Registry_Ui::icon( 'trash' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
													</button>
												</form>
											<?php endif; ?>
											<?php if ( $row['public_url'] ) : ?>
												<a class="bp-btn bp-btn--sm bp-btn--ghost" href="<?php echo esc_url( $row['public_url'] ); ?>"><?php esc_html_e( 'Published', 'blueprint-registry' ); ?></a>
											<?php endif; ?>
											<a class="bp-btn bp-btn--sm" href="<?php echo esc_url( $row['edit_url'] ); ?>"><?php echo esc_html( $row['action_label'] ); ?></a>
										</span>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<div class="bpv__footer">
					<span><?php echo esc_html( sprintf( _n( '%d item', '%d items', count( $visible ), 'blueprint-registry' ), count( $visible ) ) ); ?></span>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Flattens one dashboard entry into the fields the table renders, and tags
	 * it with the status buckets it belongs to.
	 */
	private static function dashboard_row( array $item ) {
		$blueprint    = $item['blueprint'];
		$change       = $item['change'];
		$blueprint_id = $blueprint ? $blueprint->ID : 0;
		$status       = $change ? self::change_status( $change->ID ) : 'published';
		$change_type  = $change ? ( get_post_meta( $change->ID, '_bp_change_type', true ) ?: 'new' ) : '';
		$presentation = $change
			? Blueprint_Registry_Workflow::presentation( Blueprint_Registry_Workflow::source( $change->ID ) )
			: array( 'description' => (string) get_post_field( 'post_content', $blueprint_id ) );

		$buckets = array();
		if ( 'changes_requested' === $status ) {
			$buckets[] = 'attention';
		}
		if ( 'pending_review' === $status ) {
			$buckets[] = 'review';
		}
		if ( 'draft' === $status ) {
			$buckets[] = 'draft';
		}
		if ( $blueprint_id ) {
			$buckets[] = 'published';
		}

		$action_labels = array(
			'draft'             => __( 'Continue', 'blueprint-registry' ),
			'changes_requested' => __( 'Address feedback', 'blueprint-registry' ),
			'pending_review'    => __( 'Edit changes', 'blueprint-registry' ),
		);

		return array(
			'blueprint_id' => $blueprint_id,
			'title'        => $change ? get_the_title( $change ) : get_the_title( $blueprint ),
			'description'  => wp_trim_words( wp_strip_all_tags( $presentation['description'] ?? '' ), 16 ),
			'status'       => $status,
			'kind'         => $change ? array( 'new' => __( 'New Blueprint', 'blueprint-registry' ), 'fork' => __( 'Fork', 'blueprint-registry' ), 'update' => __( 'Update', 'blueprint-registry' ) )[ $change_type ] : __( 'Blueprint', 'blueprint-registry' ),
			'modified'     => get_the_modified_date( get_option( 'date_format' ), $change ?: $blueprint ),
			'edit_url'     => $change ? self::edit_url( $change->ID ) : self::new_editor_url( $blueprint_id, 'update' ),
			'action_label' => $action_labels[ $status ] ?? __( 'Edit', 'blueprint-registry' ),
			'public_url'   => $blueprint_id ? get_permalink( $blueprint_id ) : '',
			'delete_id'    => $change && 'draft' === $status ? $change->ID : 0,
			'thumbnail_id' => $blueprint_id ? get_post_thumbnail_id( $blueprint_id ) : ( $change ? get_post_thumbnail_id( $change->ID ) : 0 ),
			'buckets'      => $buckets,
		);
	}

	private static function dashboard_items( $author_id ) {
		$blueprints = get_posts(
			array(
				'post_type'      => 'blueprint',
				'post_status'    => 'publish',
				'author'         => (int) $author_id,
				'posts_per_page' => 100,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		$changes    = get_posts(
			array(
				'post_type'      => 'blueprint_change',
				'post_status'    => 'draft',
				'author'         => (int) $author_id,
				'posts_per_page' => 100,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		$current_changes = array();
		$unpublished     = array();

		foreach ( $changes as $change ) {
			$target_id = (int) get_post_meta( $change->ID, '_bp_target_blueprint_id', true );
			if ( ! $target_id ) {
				if ( 'accepted' !== self::change_status( $change->ID ) ) {
					$unpublished[] = $change;
				}
				continue;
			}
			if ( ! self::dashboard_change_is_active( $change ) ) {
				continue;
			}

			if ( ! isset( $current_changes[ $target_id ] ) || self::dashboard_change_is_newer( $change, $current_changes[ $target_id ] ) ) {
				$current_changes[ $target_id ] = $change;
			}
		}

		$items = array();
		foreach ( $blueprints as $blueprint ) {
			$items[] = array(
				'blueprint' => $blueprint,
				'change'    => $current_changes[ $blueprint->ID ] ?? null,
			);
			unset( $current_changes[ $blueprint->ID ] );
		}
		foreach ( $current_changes as $change ) {
			$items[] = array(
				'blueprint' => get_post( (int) get_post_meta( $change->ID, '_bp_target_blueprint_id', true ) ),
				'change'    => $change,
			);
		}
		foreach ( $unpublished as $change ) {
			$items[] = array(
				'blueprint' => null,
				'change'    => $change,
			);
		}

		usort(
			$items,
			static function ( $left, $right ) {
				return self::dashboard_item_modified( $right ) <=> self::dashboard_item_modified( $left );
			}
		);

		return $items;
	}

	private static function dashboard_change_is_newer( $candidate, $current ) {
		$candidate_modified = get_post_modified_time( 'U', true, $candidate );
		$current_modified   = get_post_modified_time( 'U', true, $current );
		return $candidate_modified > $current_modified || ( $candidate_modified === $current_modified && $candidate->ID > $current->ID );
	}

	private static function dashboard_change_is_active( $change ) {
		return in_array( self::change_status( $change->ID ), array( 'draft', 'pending_review', 'changes_requested' ), true );
	}

	private static function dashboard_item_modified( $item ) {
		return get_post_modified_time( 'U', true, $item['change'] ?: $item['blueprint'] );
	}

	private static function render_editor( $change_id ) {
		$change = get_post( $change_id );
		if ( ! self::can_edit_change( $change ) ) {
			wp_die( esc_html__( 'You cannot manage this Blueprint draft.', 'blueprint-registry' ), '', array( 'response' => 403 ) );
		}

		$status    = self::change_status( $change->ID );
		$messages  = Blueprint_Registry_Workflow::review_messages( $change->ID );
		$errors    = get_post_meta( $change->ID, '_bp_validation_errors', true );
		$errors    = is_array( $errors ) ? $errors : array();
		$editable  = in_array( $status, array( 'draft', 'pending_review', 'changes_requested' ), true );
		$target_id = (int) get_post_meta( $change->ID, '_bp_target_blueprint_id', true );
		$type      = get_post_meta( $change->ID, '_bp_change_type', true ) ?: 'new';
		?>
		<a class="bp-back" href="<?php echo esc_url( self::dashboard_url() ); ?>">
			<?php echo Blueprint_Registry_Ui::icon( 'arrow-left' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php esc_html_e( 'My work', 'blueprint-registry' ); ?>
		</a>

		<div class="bp-page-head">
			<div class="bp-page-head__text">
				<h1><?php echo esc_html( get_the_title( $change ) ); ?></h1>
				<div class="bp-page-head__meta">
					<?php Blueprint_Registry_Ui::chip( $status, self::status_label( $status ) ); ?>
					<?php if ( $errors ) : ?>
						<span class="bp-chip bp-chip--rejected"><?php echo esc_html( sprintf( _n( '%d problem to fix', '%d problems to fix', count( $errors ), 'blueprint-registry' ), count( $errors ) ) ); ?></span>
					<?php endif; ?>
					<span class="bp-panel__head-note"><?php echo esc_html( self::proposal_label( $change->ID, $type ) ); ?></span>
					<?php if ( 'pending_review' === $status ) : ?>
						<span class="bp-panel__head-note"><?php esc_html_e( 'Your submitted version stays in the queue while you edit.', 'blueprint-registry' ); ?></span>
					<?php endif; ?>
				</div>
			</div>
			<div class="bp-page-head__actions">
				<?php if ( $editable ) : ?>
					<?php self::render_delete_draft_action( $change ); ?>
					<button class="bp-btn bp-btn--primary" type="submit" form="bp-manage-editor">
						<?php echo Blueprint_Registry_Ui::icon( 'diff' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php esc_html_e( 'Review changes', 'blueprint-registry' ); ?>
					</button>
				<?php else : ?>
					<?php self::render_change_actions( $change ); ?>
				<?php endif; ?>
			</div>
		</div>

		<?php $upload_error = get_post_meta( $change->ID, '_bp_upload_error', true ); ?>
		<?php if ( $upload_error ) : ?>
			<div class="bp-notice bp-notice--error" role="alert">
				<?php echo Blueprint_Registry_Ui::icon( 'alert' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<div><strong><?php esc_html_e( 'Your code was saved, but the files were not added.', 'blueprint-registry' ); ?></strong> <?php echo esc_html( $upload_error ); ?> <a href="#bp-bundle-panel"><?php esc_html_e( 'Try the upload again', 'blueprint-registry' ); ?></a></div>
			</div>
		<?php else : ?>
			<?php Blueprint_Registry_Ui::notice(); ?>
		<?php endif; ?>


		<?php self::render_review_history( $messages ); ?>

		<form id="bp-manage-editor" class="bp-editor" method="post" action="<?php echo esc_url( self::edit_url( $change->ID ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="bp_action" value="save_and_review">
			<input type="hidden" name="change_id" value="<?php echo esc_attr( $change->ID ); ?>">
			<?php wp_nonce_field( 'bp_front_save_' . $change->ID ); ?>

			<div class="bp-editor__aside">
				<?php self::render_upload_panel( __( 'The path is relative to the bundle root.', 'blueprint-registry' ), $editable, $change->ID ); ?>
				<?php self::render_revision_history( $target_id, $change->ID ); ?>
			</div>

			<div class="bp-editor__slot">
				<section class="bp-panel">
					<div class="bp-panel__head">
						<h2><?php esc_html_e( 'blueprint.json', 'blueprint-registry' ); ?></h2>
						<span class="bp-panel__head-note" data-bp-save-state role="status"><?php echo 'pending_review' === $status ? esc_html__( 'Saved working copy', 'blueprint-registry' ) : ( $editable ? esc_html__( 'Saved. Not submitted yet.', 'blueprint-registry' ) : esc_html__( 'Read-only record', 'blueprint-registry' ) ); ?></span>
					</div>
					<div class="bp-panel__body">
						<label class="bp-visually-hidden" for="bp_blueprint_json"><?php esc_html_e( 'Blueprint JSON', 'blueprint-registry' ); ?></label>
						<textarea class="bp-input" id="bp_blueprint_json" name="bp_blueprint_json" rows="26" spellcheck="false" <?php disabled( ! $editable ); ?>><?php echo esc_textarea( Blueprint_Registry_Workflow::source( $change->ID ) ); ?></textarea>
						<?php self::render_validation_errors( $errors ); ?>
					</div>
				</section>

			</div>
		</form>
		<?php self::render_submission_history( $change->ID ); ?>
		<?php
	}

	private static function render_submission_review( $change_id ) {
		$change = get_post( $change_id );
		if ( ! self::can_edit_change( $change ) ) {
			wp_die( esc_html__( 'You cannot manage this Blueprint draft.', 'blueprint-registry' ), '', array( 'response' => 403 ) );
		}

		$status = self::change_status( $change->ID );
		if ( ! in_array( $status, array( 'draft', 'pending_review', 'changes_requested' ), true ) ) {
			wp_safe_redirect( self::edit_url( $change->ID ) );
			exit;
		}

		$errors = get_post_meta( $change->ID, '_bp_validation_errors', true );
		$errors = is_array( $errors ) ? $errors : array();
		$upload_error = get_post_meta( $change->ID, '_bp_upload_error', true );
		if ( $upload_error ) { $errors[] = $upload_error; }
		?>
		<a class="bp-back" href="<?php echo esc_url( self::edit_url( $change->ID ) ); ?>">
			<?php echo Blueprint_Registry_Ui::icon( 'arrow-left' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php esc_html_e( 'Back to editing', 'blueprint-registry' ); ?>
		</a>

		<div class="bp-page-head">
			<div class="bp-page-head__text">
				<h1><?php esc_html_e( 'Review your changes', 'blueprint-registry' ); ?></h1>
				<p class="bp-page-head__sub">
					<?php
					echo esc_html(
						$errors
							? __( 'Fix the problems below before submitting.', 'blueprint-registry' )
							/* translators: %s: the Blueprint title. */
							: sprintf( __( 'Check %s below. A reviewer must accept it before it appears in the gallery.', 'blueprint-registry' ), get_the_title( $change ) )
					);
					?>
				</p>
			</div>
			<div class="bp-page-head__actions">
				<form class="bp-inline-form" method="post" action="<?php echo esc_url( self::submission_review_url( $change->ID ) ); ?>" target="bp-playground">
					<input type="hidden" name="bp_action" value="preview">
					<input type="hidden" name="change_id" value="<?php echo esc_attr( $change->ID ); ?>">
					<input type="hidden" name="bp_reviewed_content" value="<?php echo esc_attr( self::review_fingerprint( $change->ID ) ); ?>">
					<?php wp_nonce_field( 'bp_front_preview_' . $change->ID ); ?>
					<button class="bp-btn" type="submit">
						<?php echo Blueprint_Registry_Ui::icon( 'play' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php esc_html_e( 'Run preview ↗', 'blueprint-registry' ); ?>
					</button>
				</form>
				<form class="bp-inline-form" method="post" action="<?php echo esc_url( self::submission_review_url( $change->ID ) ); ?>">
					<input type="hidden" name="bp_action" value="submit">
					<input type="hidden" name="change_id" value="<?php echo esc_attr( $change->ID ); ?>">
					<input type="hidden" name="bp_reviewed_content" value="<?php echo esc_attr( self::review_fingerprint( $change->ID ) ); ?>">
					<?php wp_nonce_field( 'bp_front_submit_' . $change->ID ); ?>
					<button class="bp-btn bp-btn--primary" type="submit" <?php disabled( (bool) $errors ); ?>>
						<?php echo Blueprint_Registry_Ui::icon( 'send' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php echo 'pending_review' === $status ? esc_html__( 'Replace submitted version', 'blueprint-registry' ) : esc_html__( 'Submit for review', 'blueprint-registry' ); ?>
					</button>
				</form>
			</div>
		</div>

		<?php Blueprint_Registry_Ui::notice(); ?>
		<?php self::render_validation_errors( $errors ); ?>
		<?php $presentation = Blueprint_Registry_Workflow::presentation( Blueprint_Registry_Workflow::source( $change->ID ) ); ?>
		<section class="bp-panel bp-review-intro">
			<div class="bp-panel__body">
				<h2><?php echo esc_html( get_the_title( $change ) ); ?></h2>
				<?php if ( ! empty( $presentation['description'] ) ) : ?><p><?php echo esc_html( $presentation['description'] ); ?></p><?php endif; ?>
				<p class="bp-field__hint"><?php esc_html_e( 'Run preview opens this saved bundle in a new Playground tab. Nothing is published yet.', 'blueprint-registry' ); ?></p>
				<?php if ( 'pending_review' === $status ) : ?><p class="bp-field__hint"><?php esc_html_e( 'Submitting replaces the version in the queue with the contents below.', 'blueprint-registry' ); ?></p><?php endif; ?>
			</div>
		</section>

		<?php
		$target_id = (int) get_post_meta( $change->ID, '_bp_target_blueprint_id', true );
		$current_id = $target_id ? (int) get_post_meta( $target_id, '_bp_current_release_id', true ) : 0;
		$base_id = (int) get_post_meta( $change->ID, '_bp_base_release_id', true );
		?>
		<?php if ( 'changes_requested' === $status && $current_id && $base_id !== $current_id ) : ?>
			<form class="bp-notice bp-notice--warn" method="post" action="<?php echo esc_url( self::submission_review_url( $change->ID ) ); ?>">
				<input type="hidden" name="bp_action" value="refresh_base">
				<input type="hidden" name="change_id" value="<?php echo esc_attr( $change->ID ); ?>">
				<?php wp_nonce_field( 'bp_front_refresh_base_' . $change->ID ); ?>
				<div><?php esc_html_e( 'A newer revision has been published. Compare your changes with it before submitting again.', 'blueprint-registry' ); ?></div>
				<button class="bp-btn" type="submit"><?php esc_html_e( 'Compare with current revision', 'blueprint-registry' ); ?></button>
			</form>
		<?php endif; ?>

		<?php Blueprint_Registry_Diff::render_change( $change->ID, __( 'Changes from the base revision', 'blueprint-registry' ) ); ?>
		<?php if ( $base_id && Blueprint_Registry_Bundles::get_change_files( $change->ID ) ) : ?>
			<details class="bp-panel bp-submitted-history">
				<summary class="bp-history-toggle"><?php esc_html_e( 'All bundle files', 'blueprint-registry' ); ?></summary>
				<?php self::render_change_files( $change->ID, false ); ?>
			</details>
		<?php endif; ?>

		<?php
	}

	private static function review_fingerprint( $change_id ) {
		return hash( 'sha256', wp_json_encode( array(
			'base' => (int) get_post_meta( $change_id, '_bp_base_release_id', true ),
			'files' => Blueprint_Registry_Bundles::release_manifest( Blueprint_Registry_Workflow::source( $change_id ), Blueprint_Registry_Bundles::get_change_files( $change_id ) ),
		) ) );
	}

	private static function render_delete_draft_action( $change ) {
		if ( 'draft' !== self::change_status( $change->ID ) ) {
			return;
		}
		?>
		<form class="bp-inline-form" method="post" action="<?php echo esc_url( self::edit_url( $change->ID ) ); ?>" data-bp-confirm="<?php esc_attr_e( 'Remove this draft? This cannot be undone.', 'blueprint-registry' ); ?>">
			<input type="hidden" name="bp_action" value="delete_draft">
			<input type="hidden" name="change_id" value="<?php echo esc_attr( $change->ID ); ?>">
			<?php wp_nonce_field( 'bp_front_delete_' . $change->ID ); ?>
			<button class="bp-btn bp-btn--quiet-danger" type="submit">
				<?php echo Blueprint_Registry_Ui::icon( 'trash' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php esc_html_e( 'Remove draft', 'blueprint-registry' ); ?>
			</button>
		</form>
		<?php
	}

	/**
	 * Actions for a proposal that can no longer be edited.
	 */
	private static function render_change_actions( $change ) {
		$status     = self::change_status( $change->ID );
		$target_id  = (int) get_post_meta( $change->ID, '_bp_target_blueprint_id', true );
		?>
		<form class="bp-inline-form" method="post" action="<?php echo esc_url( self::edit_url( $change->ID ) ); ?>" target="bp-playground">
			<input type="hidden" name="bp_action" value="preview">
			<input type="hidden" name="change_id" value="<?php echo esc_attr( $change->ID ); ?>">
			<?php wp_nonce_field( 'bp_front_preview_' . $change->ID ); ?>
			<button class="bp-btn" type="submit">
				<?php echo Blueprint_Registry_Ui::icon( 'play' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php esc_html_e( 'Run preview ↗', 'blueprint-registry' ); ?>
			</button>
		</form>

		<?php if ( in_array( $status, array( 'accepted', 'rejected' ), true ) && $target_id ) : ?>
			<a class="bp-btn bp-btn--primary" href="<?php echo esc_url( self::new_editor_url( $target_id, 'update' ) ); ?>">
				<?php echo Blueprint_Registry_Ui::icon( 'edit' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php esc_html_e( 'Start a new update', 'blueprint-registry' ); ?>
			</a>
		<?php endif; ?>
		<?php
	}

	private static function render_change_files( $change_id, $editable ) {
		$files = Blueprint_Registry_Bundles::get_change_files( $change_id );
		if ( ! $files ) {
			return;
		}
		?>
		<ul class="bp-bundle-list">
			<?php foreach ( $files as $file ) : ?>
				<li>
					<?php echo Blueprint_Registry_Ui::icon( Blueprint_Registry_Ui::file_icon( $file['path'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<a class="bp-bundle-list__name" href="<?php echo esc_url( Blueprint_Registry_Admin::bundle_file_url( $change_id, $file['key'] ) ); ?>" title="<?php echo esc_attr( $file['path'] ); ?>"><?php echo esc_html( $file['path'] ); ?></a>
					<?php if ( $editable ) : ?>
						<button class="bp-bundle-list__remove" type="submit" name="bp_remove_file" value="<?php echo esc_attr( $file['key'] ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Remove %s', 'blueprint-registry' ), $file['path'] ) ); ?>">
							<?php echo Blueprint_Registry_Ui::icon( 'trash' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</button>
					<?php endif; ?>
					<a class="bp-bundle-list__remove" href="<?php echo esc_url( Blueprint_Registry_Admin::bundle_file_url( $change_id, $file['key'], true ) ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Download %s', 'blueprint-registry' ), $file['path'] ) ); ?>">
						<?php echo Blueprint_Registry_Ui::icon( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	private static function render_validation_errors( $errors ) {
		if ( ! is_array( $errors ) || ! $errors ) {
			return;
		}
		?>
		<div class="bp-notice bp-notice--error" style="margin-top:1rem">
			<?php echo Blueprint_Registry_Ui::icon( 'alert' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<div>
				<strong><?php echo esc_html( sprintf( _n( '%d problem to fix', '%d problems to fix', count( $errors ), 'blueprint-registry' ), count( $errors ) ) ); ?></strong>
				<ul><?php foreach ( $errors as $error ) : ?><li><?php echo esc_html( $error ); ?></li><?php endforeach; ?></ul>
			</div>
		</div>
		<?php
	}

	/**
	 * The latest reviewer feedback stays visible; earlier decisions are optional.
	 */
	private static function render_review_history( $messages ) {
		if ( ! $messages ) {
			return;
		}
		?>
		<section class="bp-panel bp-feedback">
			<div class="bp-panel__head">
				<h2><?php esc_html_e( 'Reviewer feedback', 'blueprint-registry' ); ?></h2>
				<span class="bp-panel__head-note"><?php echo esc_html( sprintf( _n( '%d decision', '%d decisions', count( $messages ), 'blueprint-registry' ), count( $messages ) ) ); ?></span>
			</div>
			<ul class="bp-thread">
				<?php foreach ( array_reverse( $messages ) as $index => $message ) : ?>
					<?php if ( 1 === $index ) : ?></ul><details><summary class="bp-history-toggle"><?php esc_html_e( 'Earlier feedback', 'blueprint-registry' ); ?></summary><ul class="bp-thread"><?php endif; ?>
					<?php
					$decision = get_comment_meta( $message->comment_ID, '_bp_review_decision', true );
					$version  = (int) get_post_meta( (int) get_comment_meta( $message->comment_ID, '_bp_submission_id', true ), '_bp_submission_number', true );
					$icon     = 'approved' === $decision ? 'check' : ( 'rejected' === $decision ? 'close' : 'alert' );
					?>
					<li>
						<span class="bp-thread__icon bp-thread__icon--<?php echo esc_attr( $decision ); ?>"><?php echo Blueprint_Registry_Ui::icon( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<div class="bp-thread__body">
							<div class="bp-thread__head">
								<strong><?php echo esc_html( $message->comment_author ); ?></strong>
								<span><?php echo esc_html( self::status_label( $decision ) ); ?></span>
								<?php if ( $version ) : ?><span><?php echo esc_html( sprintf( __( 'submission %d', 'blueprint-registry' ), $version ) ); ?></span><?php endif; ?>
								<span class="bp-detail__dot">&middot;</span>
								<span><?php echo esc_html( get_comment_date( get_option( 'date_format' ), $message ) ); ?></span>
							</div>
							<?php if ( $message->comment_content ) : ?>
								<p class="bp-thread__message"><?php echo esc_html( $message->comment_content ); ?></p>
							<?php endif; ?>
						</div>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( count( $messages ) > 1 ) : ?></details><?php endif; ?>
		</section>
		<?php
	}

	private static function render_submission_history( $change_id ) {
		$submissions = get_posts( array(
			'post_type' => 'blueprint_submission',
			'post_status' => 'private',
			'post_parent' => $change_id,
			'posts_per_page' => -1,
			'orderby' => 'ID',
			'order' => 'DESC',
		) );
		if ( ! $submissions ) {
			return;
		}
		?>
		<details class="bp-panel bp-submitted-history">
			<summary class="bp-history-toggle"><?php esc_html_e( 'Submitted versions', 'blueprint-registry' ); ?> <span class="bp-panel__head-note"><?php echo esc_html( count( $submissions ) ); ?></span></summary>
			<?php foreach ( $submissions as $submission ) : ?>
				<details class="bp-submitted-version">
					<summary class="bp-history-toggle">
						<?php echo esc_html( sprintf( __( 'Submission %1$d · %2$s · %3$s', 'blueprint-registry' ), (int) get_post_meta( $submission->ID, '_bp_submission_number', true ), self::status_label( get_post_meta( $submission->ID, '_bp_status', true ) ), get_the_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $submission ) ) ); ?>
					</summary>
					<?php Blueprint_Registry_Diff::render_submission( $submission->ID, __( 'Submitted changes', 'blueprint-registry' ) ); ?>
				</details>
			<?php endforeach; ?>
		</details>
		<?php
	}

	private static function render_revision_history( $blueprint_id, $current_change_id = 0 ) {
		$blueprint_id = (int) $blueprint_id;
		$blueprint    = get_post( $blueprint_id );
		if ( ! $blueprint || 'blueprint' !== $blueprint->post_type ) {
			return;
		}

		$current_release_id = (int) get_post_meta( $blueprint_id, '_bp_current_release_id', true );
		$current_number     = (int) get_post_meta( $current_release_id, '_bp_release_number', true );
		$changes            = get_posts(
			array(
				'post_type'      => 'blueprint_change',
				'post_status'    => 'draft',
				'author'         => get_current_user_id(),
				'posts_per_page' => 100,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'meta_query'     => array(
					array(
						'key'   => '_bp_target_blueprint_id',
						'value' => $blueprint_id,
					),
				),
			)
		);
		$changes = array_values(
			array_filter(
				$changes,
				static function ( $change ) use ( $current_change_id ) {
					return (int) $change->ID !== (int) $current_change_id;
				}
			)
		);

		if ( ! $current_number && ! $changes ) {
			return;
		}
		?>
		<section class="bp-panel">
			<div class="bp-panel__head"><h2><?php esc_html_e( 'History', 'blueprint-registry' ); ?></h2></div>
			<?php if ( $current_number ) : ?>
				<ul class="bp-bundle-list">
					<li>
						<a class="bp-bundle-list__name" href="<?php echo esc_url( Blueprint_Registry_Routes::release_detail_url( $blueprint_id, $current_number ) ); ?>"><?php echo esc_html( sprintf( __( 'Revision %d, current', 'blueprint-registry' ), $current_number ) ); ?></a>
					</li>
				</ul>
			<?php endif; ?>
			<?php if ( $changes ) : ?>
				<div class="bp-panel__head" style="border-top:1px solid var(--bp-line)">
					<h3><?php esc_html_e( 'Earlier proposals', 'blueprint-registry' ); ?></h3>
					<span class="bp-panel__head-note"><?php echo esc_html( count( $changes ) ); ?></span>
				</div>
				<ul class="bp-bundle-list">
					<?php foreach ( array_slice( $changes, 0, 6 ) as $change ) : ?>
						<?php $change_status = self::change_status( $change->ID ); ?>
						<li>
							<?php Blueprint_Registry_Ui::chip( $change_status, self::status_label( $change_status ) ); ?>
							<a class="bp-bundle-list__name" href="<?php echo esc_url( self::edit_url( $change->ID ) ); ?>"><?php echo esc_html( get_the_modified_date( get_option( 'date_format' ), $change ) ); ?></a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function can_edit_change( $change ) {
		return $change && 'blueprint_change' === $change->post_type && (int) $change->post_author === get_current_user_id();
	}

	private static function change_status( $change_id ) {
		return get_post_meta( $change_id, '_bp_status', true ) ?: 'draft';
	}

	private static function status_label( $status ) {
		$labels = array(
			'draft'             => __( 'Draft', 'blueprint-registry' ),
			'pending_review'    => __( 'In review', 'blueprint-registry' ),
			'changes_requested' => __( 'Changes requested', 'blueprint-registry' ),
			'approved'          => __( 'Accepted', 'blueprint-registry' ),
			'accepted'          => __( 'Accepted', 'blueprint-registry' ),
			'rejected'          => __( 'Rejected', 'blueprint-registry' ),
			'published'         => __( 'Published', 'blueprint-registry' ),
			'superseded'        => __( 'Replaced', 'blueprint-registry' ),
		);

		return $labels[ $status ] ?? ucwords( str_replace( '_', ' ', $status ) );
	}

	private static function proposal_label( $change_id, $change_type ) {
		if ( 'update' === $change_type ) {
			return sprintf( __( 'Update to %s', 'blueprint-registry' ), get_the_title( (int) get_post_meta( $change_id, '_bp_target_blueprint_id', true ) ) );
		}
		if ( 'fork' === $change_type ) {
			return sprintf( __( 'Fork of %s', 'blueprint-registry' ), get_the_title( (int) get_post_meta( $change_id, '_bp_source_blueprint_id', true ) ) );
		}
		return __( 'New Blueprint', 'blueprint-registry' );
	}

	private static function is_management_page() {
		return (bool) get_query_var( 'bp_manage' );
	}

	private static function current_url() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/blueprints/manage/';
		return home_url( $request_uri );
	}
}
