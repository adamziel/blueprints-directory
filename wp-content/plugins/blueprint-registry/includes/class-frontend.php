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

		wp_enqueue_style( 'blueprint-registry-gallery', BLUEPRINT_REGISTRY_URL . 'assets/gallery.css', array(), BLUEPRINT_REGISTRY_VERSION );
		wp_enqueue_style( 'blueprint-registry-management', BLUEPRINT_REGISTRY_URL . 'assets/management.css', array(), BLUEPRINT_REGISTRY_VERSION );

		if ( ! in_array( get_query_var( 'bp_manage' ), array( 'edit', 'edit_new' ), true ) ) {
			return;
		}

		if ( ! function_exists( 'wp_enqueue_code_editor' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		$settings = wp_enqueue_code_editor( array( 'type' => 'application/json', 'codemirror' => array( 'indentUnit' => 2, 'tabSize' => 2 ) ) );
		if ( false === $settings ) {
			return;
		}

		wp_enqueue_script( 'blueprint-registry-editor', BLUEPRINT_REGISTRY_URL . 'assets/editor.js', array( 'code-editor', 'wp-i18n' ), BLUEPRINT_REGISTRY_VERSION, true );
		wp_add_inline_script( 'blueprint-registry-editor', 'window.BlueprintRegistryEditor = ' . wp_json_encode( $settings ) . ';', 'before' );
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
		get_header();
		?>
		<main class="bp-manage" id="main-content">
			<?php if ( ! Blueprint_Registry_Capabilities::can_contribute() ) : ?>
				<section class="bp-manage__panel bp-manage__empty">
					<h1><?php esc_html_e( 'Manage Blueprints', 'blueprint-registry' ); ?></h1>
					<p><?php esc_html_e( 'Sign in with your WordPress.org account to create, fork, or update a Blueprint.', 'blueprint-registry' ); ?></p>
					<p><a class="bp-manage__button bp-manage__button--primary" href="<?php echo esc_url( wp_login_url( self::current_url() ) ); ?>"><?php esc_html_e( 'Sign in', 'blueprint-registry' ); ?></a></p>
				</section>
			<?php elseif ( 'edit_new' === get_query_var( 'bp_manage' ) ) : ?>
				<?php self::render_new_editor(); ?>
			<?php elseif ( 'edit' === get_query_var( 'bp_manage' ) ) : ?>
				<?php self::render_editor( (int) get_query_var( 'bp_change_id' ) ); ?>
			<?php elseif ( 'submission_review' === get_query_var( 'bp_manage' ) ) : ?>
				<?php self::render_submission_review( (int) get_query_var( 'bp_change_id' ) ); ?>
			<?php else : ?>
				<?php self::render_dashboard(); ?>
			<?php endif; ?>
		</main>
		<?php
		get_footer();
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
		if ( 'update' === $mode && (int) $blueprint->post_author !== get_current_user_id() ) {
			$this->redirect_result( new WP_Error( 'blueprint_forbidden', __( 'Only the Blueprint author can edit it.', 'blueprint-registry' ) ), self::dashboard_url() );
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
		$errors = Blueprint_Registry_Validator::validate_change( $change );
		if ( $errors ) {
			$this->redirect_result( new WP_Error( 'blueprint_invalid_change', __( 'Fix the validation errors before reviewing this Blueprint.', 'blueprint-registry' ) ), self::edit_url( $change ) );
		}

		$this->redirect_result( true, self::edit_url( $change ), self::submission_review_url( $change ) );
	}

	private function delete_draft() {
		$this->require_post();
		$change = $this->change_from_request();
		check_admin_referer( 'bp_front_delete_' . $change->ID );
		if ( 'draft' !== self::change_status( $change->ID ) ) {
			$this->redirect_result( new WP_Error( 'blueprint_not_draft', __( 'Only drafts can be removed.', 'blueprint-registry' ) ), self::dashboard_url() );
		}

		foreach ( Blueprint_Registry_Bundles::get_change_files( $change->ID ) as $file ) {
			wp_delete_attachment( (int) $file['attachment_id'], true );
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

		if ( isset( $_POST['bp_remove_file'] ) ) {
			$this->remove_change_file( $change, (int) $_POST['bp_remove_file'] );
		}

		if ( isset( $_POST['bp_blueprint_json'] ) ) {
			Blueprint_Registry_Workflow::set_source( $change->ID, wp_unslash( $_POST['bp_blueprint_json'] ) );
		}

		Blueprint_Registry_Admin::save_uploaded_file( $change->ID );
		Blueprint_Registry_Validator::validate_change( $change->ID );
		if ( $continue_to_review ) {
			$this->redirect_result( true, self::edit_url( $change->ID ), self::submission_review_url( $change->ID ) );
		}
		$this->redirect_result( true, self::edit_url( $change->ID ), null, __( 'Changes saved.', 'blueprint-registry' ) );
	}

	private function submit_change() {
		$this->require_post();
		$change = $this->change_from_request();
		check_admin_referer( 'bp_front_submit_' . $change->ID );
		$this->redirect_result( Blueprint_Registry_Workflow::submit( $change->ID ), self::edit_url( $change->ID ), self::edit_url( $change->ID ), __( 'Changes submitted for review.', 'blueprint-registry' ) );
	}

	private function preview_change() {
		$this->require_post();
		$change = $this->change_from_request();
		check_admin_referer( 'bp_front_preview_' . $change->ID );
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
		$this->redirect_result( $result, self::edit_url( $change->ID ), null, __( 'The proposal now compares with the current release. Review the diff before submitting again.', 'blueprint-registry' ) );
	}

	private function remove_change_file( $change, $attachment_id ) {
		$attachment_ids = wp_list_pluck( Blueprint_Registry_Bundles::get_change_files( $change->ID ), 'attachment_id' );
		if ( ! in_array( $attachment_id, array_map( 'intval', $attachment_ids ), true ) ) {
			$this->redirect_result( new WP_Error( 'blueprint_missing_file', __( 'That bundle file does not belong to this draft.', 'blueprint-registry' ) ), self::edit_url( $change->ID ) );
		}

		Blueprint_Registry_Bundles::remove_change_file( $change->ID, $attachment_id );
		wp_delete_attachment( $attachment_id, true );
		Blueprint_Registry_Validator::validate_change( $change->ID );
		$this->redirect_result( true, self::edit_url( $change->ID ), null, __( 'Bundle file removed.', 'blueprint-registry' ) );
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
		?>
		<header class="bp-manage__header bp-manage__header--editor">
			<div>
				<p><a href="<?php echo esc_url( self::dashboard_url() ); ?>">&larr; <?php esc_html_e( 'My Blueprints', 'blueprint-registry' ); ?></a></p>
				<h1><?php echo esc_html( $context['heading'] ); ?></h1>
			</div>
		</header>
		<?php if ( 'update' === $context['mode'] ) : ?>
			<?php self::render_revision_history( $context['source_blueprint_id'] ); ?>
		<?php endif; ?>
		<form class="bp-manage__editor" method="post" action="<?php echo esc_url( self::new_editor_url( $context['source_blueprint_id'], $context['mode'] ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="bp_action" value="create_and_review">
			<input type="hidden" name="bp_source_blueprint_id" value="<?php echo esc_attr( $context['source_blueprint_id'] ); ?>">
			<input type="hidden" name="bp_new_mode" value="<?php echo esc_attr( $context['mode'] ); ?>">
			<?php wp_nonce_field( 'bp_front_create_new_' . $context['source_blueprint_id'] . '_' . $context['mode'] ); ?>
			<section class="bp-manage__panel">
				<h2><?php esc_html_e( 'Blueprint JSON', 'blueprint-registry' ); ?></h2>
				<p><?php esc_html_e( 'This editor is not saved until you review your changes.', 'blueprint-registry' ); ?></p>
				<textarea id="bp_blueprint_json" name="bp_blueprint_json" rows="26"><?php echo esc_textarea( $context['source'] ); ?></textarea>
			</section>
			<section class="bp-manage__panel">
				<h2><?php esc_html_e( 'Bundle files', 'blueprint-registry' ); ?></h2>
				<?php if ( $context['source_blueprint_id'] ) : ?>
					<p><?php esc_html_e( 'The existing bundle files are copied when you review this Blueprint. Upload a resource to add another.', 'blueprint-registry' ); ?></p>
				<?php else : ?>
					<p><?php esc_html_e( 'Upload one resource at a time. Its bundle path is relative to the ZIP root.', 'blueprint-registry' ); ?></p>
				<?php endif; ?>
				<div class="bp-manage__file-fields">
					<p><label for="bp_bundle_file"><?php esc_html_e( 'File', 'blueprint-registry' ); ?></label><input type="file" id="bp_bundle_file" name="bp_bundle_file"></p>
					<p><label for="bp_bundle_path"><?php esc_html_e( 'Bundle path', 'blueprint-registry' ); ?></label><input type="text" id="bp_bundle_path" name="bp_bundle_path" placeholder="content/demo.xml"></p>
				</div>
			</section>
			<div class="bp-manage__actions">
				<button class="bp-manage__button bp-manage__button--primary" type="submit"><?php esc_html_e( 'Review changes', 'blueprint-registry' ); ?></button>
			</div>
		</form>
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
		if ( 'update' === $mode && (int) $blueprint->post_author !== get_current_user_id() ) {
			return new WP_Error( 'blueprint_forbidden', __( 'Only the Blueprint author can edit it.', 'blueprint-registry' ) );
		}

		$release_id = (int) get_post_meta( $source_blueprint_id, '_bp_current_release_id', true );
		if ( ! $release_id ) {
			return new WP_Error( 'blueprint_missing_release', __( 'This Blueprint has no published release to start from.', 'blueprint-registry' ) );
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
		$items = self::dashboard_items( get_current_user_id() );
		?>
		<header class="bp-manage__header">
			<div>
				<p class="bp-manage__eyebrow"><?php esc_html_e( 'WordPress Playground', 'blueprint-registry' ); ?></p>
				<h1><?php esc_html_e( 'My Blueprints', 'blueprint-registry' ); ?></h1>
				<p><?php esc_html_e( 'Create a Blueprint or continue working on a draft or review request.', 'blueprint-registry' ); ?></p>
			</div>
			<a class="bp-manage__button bp-manage__button--primary bp-manage__create-form--button" href="<?php echo esc_url( self::new_editor_url() ); ?>"><?php esc_html_e( '+ New Blueprint', 'blueprint-registry' ); ?></a>
		</header>
		<?php self::render_notice(); ?>
		<section class="bp-manage__section" aria-labelledby="bp-your-work">
			<h2 id="bp-your-work"><?php esc_html_e( 'Your work', 'blueprint-registry' ); ?></h2>
			<?php if ( $items ) : ?>
				<div class="bp-gallery__grid bp-manage__grid">
					<?php foreach ( $items as $item ) : ?>
						<?php
						$blueprint           = $item['blueprint'];
						$change              = $item['change'];
						$blueprint_id        = $blueprint ? $blueprint->ID : 0;
						$change_type         = $change ? get_post_meta( $change->ID, '_bp_change_type', true ) ?: 'new' : '';
						$status              = $change ? self::change_status( $change->ID ) : 'published';
						$edit_url            = $change ? self::edit_url( $change->ID ) : self::new_editor_url( $blueprint_id, 'update' );
						$thumbnail_id        = $blueprint_id ? get_post_thumbnail_id( $blueprint_id ) : get_post_thumbnail_id( $change->ID );
						$presentation        = $change ? Blueprint_Registry_Workflow::presentation( Blueprint_Registry_Workflow::source( $change->ID ) ) : array( 'description' => (string) get_post_field( 'post_content', $blueprint_id ) );
						$title               = $change ? get_the_title( $change ) : get_the_title( $blueprint );
						$modified            = $change ?: $blueprint;
						?>
						<article class="bp-tile bp-manage__tile"<?php if ( $blueprint_id ) : ?> data-bp-blueprint-id="<?php echo esc_attr( $blueprint_id ); ?>"<?php endif; ?>>
							<a class="bp-tile__image" href="<?php echo esc_url( $edit_url ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Edit %s', 'blueprint-registry' ), $title ) ); ?>">
								<?php if ( $thumbnail_id ) : ?>
									<?php echo wp_get_attachment_image( $thumbnail_id, 'large', false, array( 'loading' => 'lazy' ) ); ?>
								<?php else : ?>
									<span class="bp-tile__placeholder"><?php echo esc_html( strtoupper( substr( $title, 0, 1 ) ) ); ?></span>
								<?php endif; ?>
							</a>
							<div class="bp-tile__body">
								<p class="bp-tile__categories"><span class="bp-manage__status bp-manage__status--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( self::status_label( $status ) ); ?></span><?php echo esc_html( $change ? self::proposal_label( $change->ID, $change_type ) : __( 'Published Blueprint', 'blueprint-registry' ) ); ?></p>
								<h2><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $title ); ?></a></h2>
								<?php if ( ! empty( $presentation['description'] ) ) : ?>
									<p class="bp-tile__description"><?php echo esc_html( $presentation['description'] ); ?></p>
								<?php endif; ?>
								<div class="bp-tile__footer">
									<span><?php echo esc_html( get_the_modified_date( '', $modified ) ); ?></span>
									<div class="bp-manage__tile-actions">
										<?php if ( $change && 'draft' === $status ) : ?>
											<form class="bp-manage__inline-form" method="post" action="<?php echo esc_url( self::dashboard_url() ); ?>">
												<input type="hidden" name="bp_action" value="delete_draft">
												<input type="hidden" name="change_id" value="<?php echo esc_attr( $change->ID ); ?>">
												<?php wp_nonce_field( 'bp_front_delete_' . $change->ID ); ?>
												<button class="bp-manage__link-button" type="submit"><?php esc_html_e( 'Remove', 'blueprint-registry' ); ?></button>
											</form>
										<?php endif; ?>
										<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'blueprint-registry' ); ?> &rarr;</a>
									</div>
								</div>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<div class="bp-manage__panel bp-manage__empty"><p><?php esc_html_e( 'You have not created a Blueprint yet.', 'blueprint-registry' ); ?></p></div>
			<?php endif; ?>
		</section>
		<?php
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

		$status       = self::change_status( $change->ID );
		$review_messages = Blueprint_Registry_Workflow::review_messages( $change->ID );
		$errors       = get_post_meta( $change->ID, '_bp_validation_errors', true );
		$upload_error = get_post_meta( $change->ID, '_bp_upload_error', true );
		$editable     = in_array( $status, array( 'draft', 'pending_review', 'changes_requested' ), true );
		?>
		<header class="bp-manage__header bp-manage__header--editor">
			<div>
				<p><a href="<?php echo esc_url( self::dashboard_url() ); ?>">&larr; <?php esc_html_e( 'My Blueprints', 'blueprint-registry' ); ?></a></p>
				<h1><?php echo esc_html( get_the_title( $change ) ); ?></h1>
				<p><span class="bp-manage__status bp-manage__status--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( self::status_label( $status ) ); ?></span></p>
			</div>
		</header>
		<?php self::render_notice(); ?>
		<?php self::render_review_history( $review_messages ); ?>
		<?php self::render_revision_history( (int) get_post_meta( $change->ID, '_bp_target_blueprint_id', true ), $change->ID ); ?>
		<form id="bp-manage-editor" class="bp-manage__editor" method="post" action="<?php echo esc_url( self::edit_url( $change->ID ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="bp_action" value="save">
			<input type="hidden" name="change_id" value="<?php echo esc_attr( $change->ID ); ?>">
			<?php wp_nonce_field( 'bp_front_save_' . $change->ID ); ?>
			<section class="bp-manage__panel">
				<h2><?php esc_html_e( 'Blueprint JSON', 'blueprint-registry' ); ?></h2>
				<p><?php esc_html_e( 'Use the official Blueprint schema. Review the diff before submitting; errors block submission.', 'blueprint-registry' ); ?></p>
				<textarea id="bp_blueprint_json" name="bp_blueprint_json" rows="26" <?php disabled( ! $editable ); ?>><?php echo esc_textarea( Blueprint_Registry_Workflow::source( $change->ID ) ); ?></textarea>
				<?php self::render_validation_errors( $errors ); ?>
			</section>
			<section class="bp-manage__panel">
				<h2><?php esc_html_e( 'Bundle files', 'blueprint-registry' ); ?></h2>
				<p><?php esc_html_e( 'Upload one resource at a time. Its bundle path is relative to the ZIP root.', 'blueprint-registry' ); ?></p>
				<?php if ( $upload_error ) : ?><div class="bp-manage__notice bp-manage__notice--error"><?php echo esc_html( $upload_error ); ?></div><?php endif; ?>
				<div class="bp-manage__file-fields">
					<p><label for="bp_bundle_file"><?php esc_html_e( 'File', 'blueprint-registry' ); ?></label><input type="file" id="bp_bundle_file" name="bp_bundle_file" <?php disabled( ! $editable ); ?>></p>
					<p><label for="bp_bundle_path"><?php esc_html_e( 'Bundle path', 'blueprint-registry' ); ?></label><input type="text" id="bp_bundle_path" name="bp_bundle_path" placeholder="content/demo.xml" <?php disabled( ! $editable ); ?>></p>
				</div>
				<?php self::render_change_files( $change->ID, $editable ); ?>
			</section>
		</form>
		<?php if ( $editable ) : ?>
			<div class="bp-manage__actions">
				<?php self::render_delete_draft_action( $change ); ?>
				<button class="bp-manage__button bp-manage__button--primary" type="submit" form="bp-manage-editor" name="bp_action" value="save_and_review"><?php esc_html_e( 'Review changes', 'blueprint-registry' ); ?></button>
			</div>
		<?php endif; ?>
		<?php if ( ! $editable ) : ?>
			<div class="bp-manage__actions bp-manage__actions--editor">
				<?php self::render_change_actions( $change ); ?>
			</div>
		<?php endif; ?>
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
		?>
		<header class="bp-manage__header bp-manage__header--editor">
			<div>
				<p><a href="<?php echo esc_url( self::edit_url( $change->ID ) ); ?>">&larr; <?php esc_html_e( 'Edit Blueprint', 'blueprint-registry' ); ?></a></p>
				<h1><?php esc_html_e( 'Review changes', 'blueprint-registry' ); ?></h1>
				<p><?php echo esc_html( get_the_title( $change ) ); ?></p>
			</div>
		</header>
		<?php self::render_notice(); ?>
		<?php self::render_review_history( Blueprint_Registry_Workflow::review_messages( $change->ID ) ); ?>
		<section class="bp-manage__panel bp-manage__diff">
			<?php Blueprint_Registry_Diff::render_change( $change->ID, __( 'Changes from the base release', 'blueprint-registry' ) ); ?>
		</section>
		<div class="bp-manage__actions bp-manage__actions--editor">
			<?php self::render_delete_draft_action( $change ); ?>
			<a class="bp-manage__button" href="<?php echo esc_url( self::edit_url( $change->ID ) ); ?>"><?php esc_html_e( 'Back to editing', 'blueprint-registry' ); ?></a>
			<form class="bp-manage__inline-form" method="post" action="<?php echo esc_url( self::submission_review_url( $change->ID ) ); ?>" target="bp-playground">
				<input type="hidden" name="bp_action" value="preview">
				<input type="hidden" name="change_id" value="<?php echo esc_attr( $change->ID ); ?>">
				<?php wp_nonce_field( 'bp_front_preview_' . $change->ID ); ?>
				<button class="bp-manage__button" type="submit"><?php esc_html_e( 'Preview', 'blueprint-registry' ); ?></button>
			</form>
			<form class="bp-manage__inline-form" method="post" action="<?php echo esc_url( self::submission_review_url( $change->ID ) ); ?>">
				<input type="hidden" name="bp_action" value="submit">
				<input type="hidden" name="change_id" value="<?php echo esc_attr( $change->ID ); ?>">
				<?php wp_nonce_field( 'bp_front_submit_' . $change->ID ); ?>
				<button class="bp-manage__button bp-manage__button--primary" type="submit"><?php esc_html_e( 'Submit changes for review', 'blueprint-registry' ); ?></button>
			</form>
		</div>
		<?php
	}

	private static function render_delete_draft_action( $change ) {
		if ( 'draft' !== self::change_status( $change->ID ) ) {
			return;
		}
		?>
		<form class="bp-manage__inline-form" method="post" action="<?php echo esc_url( self::edit_url( $change->ID ) ); ?>">
			<input type="hidden" name="bp_action" value="delete_draft">
			<input type="hidden" name="change_id" value="<?php echo esc_attr( $change->ID ); ?>">
			<?php wp_nonce_field( 'bp_front_delete_' . $change->ID ); ?>
			<button class="bp-manage__button bp-manage__button--danger" type="submit"><?php esc_html_e( 'Remove', 'blueprint-registry' ); ?></button>
		</form>
		<?php
	}

	private static function render_change_actions( $change ) {
		$status = self::change_status( $change->ID );
		$target_id = (int) get_post_meta( $change->ID, '_bp_target_blueprint_id', true );
		$base_id   = (int) get_post_meta( $change->ID, '_bp_base_release_id', true );
		$current_id = $target_id ? (int) get_post_meta( $target_id, '_bp_current_release_id', true ) : 0;
		?>
		<form class="bp-manage__inline-form" method="post" action="<?php echo esc_url( self::edit_url( $change->ID ) ); ?>" target="bp-playground">
			<input type="hidden" name="bp_action" value="preview">
			<input type="hidden" name="change_id" value="<?php echo esc_attr( $change->ID ); ?>">
			<?php wp_nonce_field( 'bp_front_preview_' . $change->ID ); ?>
			<button class="bp-manage__button" type="submit"><?php esc_html_e( 'Preview', 'blueprint-registry' ); ?></button>
		</form>
		<?php if ( 'changes_requested' === $status && $target_id && $current_id && $current_id !== $base_id ) : ?>
			<form class="bp-manage__inline-form" method="post" action="<?php echo esc_url( self::edit_url( $change->ID ) ); ?>">
				<input type="hidden" name="bp_action" value="refresh_base">
				<input type="hidden" name="change_id" value="<?php echo esc_attr( $change->ID ); ?>">
				<?php wp_nonce_field( 'bp_front_refresh_base_' . $change->ID ); ?>
				<button class="bp-manage__button" type="submit"><?php esc_html_e( 'Compare with current release', 'blueprint-registry' ); ?></button>
			</form>
		<?php endif; ?>
		<?php if ( in_array( $status, array( 'accepted', 'rejected' ), true ) && $target_id ) : ?>
			<a class="bp-manage__button bp-manage__button--primary" href="<?php echo esc_url( self::new_editor_url( $target_id, 'update' ) ); ?>"><?php esc_html_e( 'Edit this Blueprint', 'blueprint-registry' ); ?></a>
		<?php endif; ?>
		<?php
	}

	private static function render_change_files( $change_id, $editable ) {
		$files = Blueprint_Registry_Bundles::get_change_files( $change_id );
		if ( ! $files ) {
			return;
		}
		?>
		<table class="bp-manage__files">
			<thead><tr><th><?php esc_html_e( 'Path', 'blueprint-registry' ); ?></th><th><?php esc_html_e( 'File', 'blueprint-registry' ); ?></th><th><?php esc_html_e( 'Download', 'blueprint-registry' ); ?></th><?php if ( $editable ) : ?><th><span class="screen-reader-text"><?php esc_html_e( 'Remove', 'blueprint-registry' ); ?></span></th><?php endif; ?></tr></thead>
			<tbody>
				<?php foreach ( $files as $file ) : ?>
					<?php $read_url = Blueprint_Registry_Admin::bundle_file_url( $change_id, $file['attachment_id'] ); $download_url = Blueprint_Registry_Admin::bundle_file_url( $change_id, $file['attachment_id'], true ); ?>
					<tr>
						<td><a href="<?php echo esc_url( $read_url ); ?>"><code><?php echo esc_html( $file['path'] ); ?></code></a></td>
						<td><a href="<?php echo esc_url( $read_url ); ?>"><?php echo esc_html( get_the_title( $file['attachment_id'] ) ); ?></a></td>
						<td><a href="<?php echo esc_url( $download_url ); ?>"><?php esc_html_e( 'Download', 'blueprint-registry' ); ?></a></td>
						<?php if ( $editable ) : ?><td><button class="bp-manage__link-button" type="submit" name="bp_remove_file" value="<?php echo esc_attr( $file['attachment_id'] ); ?>"><?php esc_html_e( 'Remove', 'blueprint-registry' ); ?></button></td><?php endif; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function render_validation_errors( $errors ) {
		if ( ! is_array( $errors ) || ! $errors ) {
			return;
		}
		?>
		<div class="bp-manage__notice bp-manage__notice--error"><strong><?php esc_html_e( 'Validation', 'blueprint-registry' ); ?></strong><ul><?php foreach ( $errors as $error ) : ?><li><?php echo esc_html( $error ); ?></li><?php endforeach; ?></ul></div>
		<?php
	}

	private static function render_review_history( $messages ) {
		if ( ! $messages ) {
			return;
		}
		?>
		<section class="bp-manage__panel">
			<h2><?php esc_html_e( 'Review history', 'blueprint-registry' ); ?></h2>
			<ul class="bp-manage__review-history">
				<?php foreach ( $messages as $message ) : ?>
					<?php $decision = get_comment_meta( $message->comment_ID, '_bp_review_decision', true ); $submission_id = (int) get_comment_meta( $message->comment_ID, '_bp_submission_id', true ); $submission_number = (int) get_post_meta( $submission_id, '_bp_submission_number', true ); ?>
					<li><strong><?php echo esc_html( self::status_label( $decision ) ); ?></strong><?php if ( $submission_number ) : ?> <span><?php echo esc_html( sprintf( __( 'Version %d', 'blueprint-registry' ), $submission_number ) ); ?></span><?php endif; ?> <?php echo esc_html( sprintf( __( 'by %1$s on %2$s', 'blueprint-registry' ), $message->comment_author, get_comment_date( '', $message ) ) ); ?><?php if ( $message->comment_content ) : ?><br><?php echo nl2br( esc_html( $message->comment_content ) ); ?><?php endif; ?></li>
				<?php endforeach; ?>
			</ul>
		</section>
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
		$summary = array();
		if ( $current_number ) {
			$summary[] = sprintf( __( 'Published release %d', 'blueprint-registry' ), $current_number );
		}
		if ( $changes ) {
			$summary[] = sprintf( _n( '%d earlier proposal', '%d earlier proposals', count( $changes ), 'blueprint-registry' ), count( $changes ) );
		}
		?>
		<details class="bp-manage__history">
			<summary><span><?php esc_html_e( 'Revision history', 'blueprint-registry' ); ?></span><?php if ( $summary ) : ?><span class="bp-manage__history-summary"><?php echo esc_html( implode( ' · ', $summary ) ); ?></span><?php endif; ?></summary>
			<div class="bp-manage__history-content">
				<?php if ( $current_number ) : ?>
					<p class="bp-manage__history-heading"><?php esc_html_e( 'Published version', 'blueprint-registry' ); ?></p>
					<p><a href="<?php echo esc_url( Blueprint_Registry_Routes::release_detail_url( $blueprint_id, $current_number ) ); ?>"><?php echo esc_html( sprintf( __( 'Release %d', 'blueprint-registry' ), $current_number ) ); ?></a> <span aria-hidden="true">·</span> <a href="<?php echo esc_url( get_permalink( $blueprint_id ) ); ?>"><?php esc_html_e( 'View published Blueprint', 'blueprint-registry' ); ?></a></p>
				<?php endif; ?>
				<?php if ( $changes ) : ?>
					<p class="bp-manage__history-heading"><?php esc_html_e( 'Earlier proposals', 'blueprint-registry' ); ?></p>
					<ol class="bp-manage__history-list">
						<?php foreach ( $changes as $change ) : ?>
							<?php $status = self::change_status( $change->ID ); ?>
							<li><span class="bp-manage__status bp-manage__status--<?php echo esc_attr( $status ); ?>"><?php echo esc_html( self::status_label( $status ) ); ?></span><a href="<?php echo esc_url( self::edit_url( $change->ID ) ); ?>"><?php echo esc_html( get_the_modified_date( get_option( 'date_format' ), $change ) ); ?></a></li>
						<?php endforeach; ?>
					</ol>
				<?php endif; ?>
			</div>
		</details>
		<?php
	}

	private static function render_notice() {
		if ( empty( $_GET['bp_notice'] ) || empty( $_GET['bp_message'] ) ) {
			return;
		}
		$class = 'error' === sanitize_key( wp_unslash( $_GET['bp_notice'] ) ) ? 'error' : 'success';
		?>
		<div class="bp-manage__notice bp-manage__notice--<?php echo esc_attr( $class ); ?>"><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['bp_message'] ) ) ); ?></div>
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
			'pending_review'    => __( 'Pending review', 'blueprint-registry' ),
			'changes_requested' => __( 'Changes requested', 'blueprint-registry' ),
			'approved'          => __( 'Accepted', 'blueprint-registry' ),
			'accepted'          => __( 'Accepted', 'blueprint-registry' ),
			'rejected'          => __( 'Rejected', 'blueprint-registry' ),
			'published'         => __( 'Published', 'blueprint-registry' ),
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
