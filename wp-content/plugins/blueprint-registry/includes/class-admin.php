<?php

defined( 'ABSPATH' ) || exit;

final class Blueprint_Registry_Admin {
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_pages' ) );
		add_action( 'admin_menu', array( $this, 'hide_review_screen_from_menu' ), 999 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_review_assets' ) );
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
	}

	public static function review_queue_url( array $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=blueprint-review-queue' ) );
	}

	public static function bundle_file_url( $change_id, $key, $download = false ) {
		$url = add_query_arg(
			array(
				'action'    => 'bp_bundle_file',
				'change_id' => (int) $change_id,
				'key'       => (string) $key,
				'download'  => $download ? '1' : null,
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, 'bp_bundle_file_' . (int) $change_id . '_' . (string) $key );
	}

	public static function submission_file_url( $submission_id, $key, $download = false ) {
		$url = add_query_arg(
			array(
				'action'        => 'bp_bundle_file',
				'submission_id' => (int) $submission_id,
				'key'           => (string) $key,
				'download'      => $download ? '1' : null,
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, 'bp_bundle_file_' . (int) $submission_id . '_' . (string) $key );
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

		// Reached from the queue rather than the menu, so it is registered and
		// then hidden instead of being left off the admin entirely.
		add_submenu_page(
			'edit.php?post_type=blueprint',
			__( 'Review proposal', 'blueprint-registry' ),
			__( 'Review proposal', 'blueprint-registry' ),
			'review_blueprints',
			'blueprint-review',
			array( $this, 'render_review_screen' )
		);
	}

	public function hide_review_screen_from_menu() {
		remove_submenu_page( 'edit.php?post_type=blueprint', 'blueprint-review' );
	}

	public static function review_url( $change_id ) {
		return add_query_arg(
			array(
				'page'      => 'blueprint-review',
				'change_id' => (int) $change_id,
			),
			admin_url( 'edit.php?post_type=blueprint' )
		);
	}

	public function add_change_boxes( $post ) {
		add_meta_box( 'bp_blueprint_source', __( 'Blueprint JSON', 'blueprint-registry' ), array( $this, 'render_source_box' ), 'blueprint_change', 'normal', 'high' );
		add_meta_box( 'bp_blueprint_diff', __( 'Changes from the base revision', 'blueprint-registry' ), array( $this, 'render_diff_box' ), 'blueprint_change', 'normal', 'default' );
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
				<?php $read_url = self::submission_file_url( $submission_id, $file['key'] ); $download_url = self::submission_file_url( $submission_id, $file['key'], true ); ?>
				<tr><td><a href="<?php echo esc_url( $read_url ); ?>"><code><?php echo esc_html( $file['path'] ); ?></code></a></td><td><a href="<?php echo esc_url( $read_url ); ?>"><?php echo esc_html( wp_basename( $file['path'] ) ); ?></a></td><td><a href="<?php echo esc_url( $download_url ); ?>"><?php esc_html_e( 'Download', 'blueprint-registry' ); ?></a></td></tr>
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
			<p><strong><?php esc_html_e( 'Target:', 'blueprint-registry' ); ?></strong> <?php echo esc_html( get_the_title( $target_id ) ); ?><br><strong><?php esc_html_e( 'Base revision:', 'blueprint-registry' ); ?></strong> <?php if ( $base_number ) : ?><a href="<?php echo esc_url( Blueprint_Registry_Routes::release_detail_url( $target_id, $base_number ) ); ?>"><?php echo esc_html( sprintf( __( 'Revision %d', 'blueprint-registry' ), $base_number ) ); ?></a><?php else : ?><?php esc_html_e( 'None', 'blueprint-registry' ); ?><?php endif; ?></p>
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
			<p><button class="button button-primary" name="decision" value="approved"><?php esc_html_e( 'Accept and publish', 'blueprint-registry' ); ?></button></p>
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
				<li><strong><?php echo esc_html( self::decision_label( $decision ) ); ?></strong><?php if ( $submission_number ) : ?> <?php echo esc_html( sprintf( __( 'Submission %d', 'blueprint-registry' ), $submission_number ) ); ?><?php endif; ?> <?php echo esc_html( sprintf( __( 'by %1$s on %2$s', 'blueprint-registry' ), $message->comment_author, get_comment_date( '', $message ) ) ); ?><?php if ( $message->comment_content ) : ?><br><?php echo nl2br( esc_html( $message->comment_content ) ); ?><?php endif; ?></li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	public function enqueue_editor( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || 'blueprint_change' !== get_current_screen()->post_type ) {
			return;
		}

		Blueprint_Registry_Assets::enqueue_admin();
	}

	/**
	 * Loads the shared styles on the queue and the review screen.
	 */
	public function enqueue_review_assets( $hook ) {
		if ( ! str_contains( (string) $hook, 'blueprint-review' ) ) {
			return;
		}

		Blueprint_Registry_Assets::enqueue_admin();
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
		$changes = array_values(
			array_filter(
				$changes,
				static function ( $change ) {
					return Blueprint_Registry_Workflow::current_submission_id( $change->ID );
				}
			)
		);
		?>
		<div class="wrap bp-admin">
			<div class="bp-page-head">
				<div class="bp-page-head__text">
					<h1><?php esc_html_e( 'Review queue', 'blueprint-registry' ); ?></h1>
					<p class="bp-page-head__sub"><?php esc_html_e( 'Submitted Blueprints, oldest first. Open one to review its contents and leave a decision.', 'blueprint-registry' ); ?></p>
				</div>
			</div>

			<div class="bpv">
				<?php if ( ! $changes ) : ?>
					<?php
					Blueprint_Registry_Ui::empty_state(
						'check',
						__( 'The queue is empty', 'blueprint-registry' ),
						__( 'No proposals are waiting for review.', 'blueprint-registry' )
					);
					?>
				<?php else : ?>
					<div class="bpv__table-wrap">
						<table class="bpv__table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Proposal', 'blueprint-registry' ); ?></th>
									<th><?php esc_html_e( 'Author', 'blueprint-registry' ); ?></th>
									<th><?php esc_html_e( 'Submission', 'blueprint-registry' ); ?></th>
									<th><?php esc_html_e( 'Waiting', 'blueprint-registry' ); ?></th>
									<th><span class="bp-visually-hidden"><?php esc_html_e( 'Actions', 'blueprint-registry' ); ?></span></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $changes as $change ) : ?>
									<?php
									$submission_id = Blueprint_Registry_Workflow::current_submission_id( $change->ID );
									$submitted_title = Blueprint_Registry_Workflow::presentation( get_post_field( 'post_content', $submission_id ) )['title'] ?? get_the_title( $change );
									$submitted_at  = (int) get_post_meta( $submission_id, '_bp_submitted_at', true );
									$target_id     = (int) get_post_meta( $change->ID, '_bp_target_blueprint_id', true );
									$thumbnail_id  = $target_id ? get_post_thumbnail_id( $target_id ) : 0;
									$type          = get_post_meta( $change->ID, '_bp_change_type', true ) ?: 'new';
									?>
									<tr>
										<td>
											<div class="bpv__primary">
												<span class="bpv__thumb">
													<?php if ( $thumbnail_id ) : ?>
														<?php echo wp_get_attachment_image( $thumbnail_id, 'medium', false, array( 'alt' => '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
													<?php else : ?>
														<span class="bpv__thumb-letter"><?php echo esc_html( strtoupper( substr( $submitted_title, 0, 1 ) ) ); ?></span>
													<?php endif; ?>
												</span>
												<span class="bpv__primary-text">
													<a class="bpv__title" href="<?php echo esc_url( self::review_url( $change->ID ) ); ?>"><?php echo esc_html( $submitted_title ); ?></a>
													<span class="bpv__subtitle">
														<?php
														echo esc_html(
															$target_id
																/* translators: %s: the Blueprint being updated. */
																? sprintf( __( 'Update to %s', 'blueprint-registry' ), get_the_title( $target_id ) )
																: ( 'fork' === $type ? __( 'Fork', 'blueprint-registry' ) : __( 'New Blueprint', 'blueprint-registry' ) )
														);
														?>
													</span>
												</span>
											</div>
										</td>
										<td class="bpv__cell--tight"><?php echo esc_html( get_the_author_meta( 'display_name', $change->post_author ) ); ?></td>
										<td class="bpv__cell--tight"><?php echo esc_html( (int) get_post_meta( $submission_id, '_bp_submission_number', true ) ); ?></td>
										<td class="bpv__cell--tight">
											<?php if ( $submitted_at ) : ?>
												<span title="<?php echo esc_attr( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $submitted_at ) ); ?>">
													<?php echo esc_html( sprintf( __( '%s ago', 'blueprint-registry' ), human_time_diff( $submitted_at ) ) ); ?>
												</span>
											<?php else : ?>
												&mdash;
											<?php endif; ?>
										</td>
										<td class="bpv__cell--tight"><span class="bp-row-actions"><?php $this->render_review_actions( $change ); ?></span></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<div class="bpv__footer">
						<span><?php echo esc_html( sprintf( _n( '%d proposal waiting', '%d proposals waiting', count( $changes ), 'blueprint-registry' ), count( $changes ) ) ); ?></span>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * The reviewer's screen: the locked submission, the comparison against the
	 * base release, and the three decisions that can be made about it.
	 */
	public function render_review_screen() {
		if ( ! Blueprint_Registry_Capabilities::can_review() ) {
			wp_die( esc_html__( 'You cannot review Blueprints.', 'blueprint-registry' ) );
		}

		$change_id = isset( $_GET['change_id'] ) ? (int) $_GET['change_id'] : 0;
		$change    = get_post( $change_id );
		if ( ! $change || 'blueprint_change' !== $change->post_type ) {
			wp_die( esc_html__( 'That proposal does not exist.', 'blueprint-registry' ) );
		}

		$status        = get_post_meta( $change->ID, '_bp_status', true ) ?: 'draft';
		$submission_id = Blueprint_Registry_Workflow::current_submission_id( $change->ID );
		$submitted_title = Blueprint_Registry_Workflow::presentation( get_post_field( 'post_content', $submission_id ) )['title'] ?? get_the_title( $change );
		$decidable     = 'pending_review' === $status && $submission_id;
		$version       = $submission_id ? (int) get_post_meta( $submission_id, '_bp_submission_number', true ) : 0;
		$submitted_at  = $submission_id ? (int) get_post_meta( $submission_id, '_bp_submitted_at', true ) : 0;
		$target_id     = (int) get_post_meta( $change->ID, '_bp_target_blueprint_id', true );
		$base_id       = $submission_id ? (int) get_post_meta( $submission_id, '_bp_base_release_id', true ) : (int) get_post_meta( $change->ID, '_bp_base_release_id', true );
		$base_number   = $base_id ? (int) get_post_meta( $base_id, '_bp_release_number', true ) : 0;
		$base_blueprint_id = $base_id ? (int) get_post_field( 'post_parent', $base_id ) : 0;
		$files         = $submission_id ? Blueprint_Registry_Bundles::get_submission_files( $submission_id ) : array();
		$messages      = Blueprint_Registry_Workflow::review_messages( $change->ID );
		$preview_url   = $submission_id
			? wp_nonce_url( admin_url( 'admin-post.php?action=bp_preview_change&change_id=' . $change->ID . '&submission_id=' . $submission_id ), 'bp_preview_change_' . $change->ID . '_' . $submission_id )
			: '';
		?>
		<div class="wrap bp-admin">
			<a class="bp-back" href="<?php echo esc_url( self::review_queue_url() ); ?>">
				<?php echo Blueprint_Registry_Ui::icon( 'arrow-left' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php esc_html_e( 'Review queue', 'blueprint-registry' ); ?>
			</a>

			<div class="bp-page-head">
				<div class="bp-page-head__text">
					<h1><?php echo esc_html( $submitted_title ); ?></h1>
					<div class="bp-page-head__meta">
						<?php Blueprint_Registry_Ui::chip( $status, self::status_label( $status ) ); ?>
						<?php if ( $version ) : ?><span class="bp-chip bp-chip--plain"><?php echo esc_html( sprintf( __( 'Submission %d', 'blueprint-registry' ), $version ) ); ?></span><?php endif; ?>
						<span class="bp-panel__head-note">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: author name, 2: relative time. */
									__( 'by %1$s%2$s', 'blueprint-registry' ),
									get_the_author_meta( 'display_name', $change->post_author ),
									$submitted_at ? sprintf( __( ', submitted %s ago', 'blueprint-registry' ), human_time_diff( $submitted_at ) ) : ''
								)
							);
							?>
						</span>
					</div>
				</div>
				<div class="bp-page-head__actions">
					<?php if ( $preview_url ) : ?>
						<a class="bp-btn" href="<?php echo esc_url( $preview_url ); ?>" target="bp-playground">
							<?php echo Blueprint_Registry_Ui::icon( 'play' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php esc_html_e( 'Run preview ↗', 'blueprint-registry' ); ?>
						</a>
					<?php endif; ?>
					<?php if ( $target_id ) : ?>
						<a class="bp-btn" href="<?php echo esc_url( get_permalink( $target_id ) ); ?>">
							<?php echo Blueprint_Registry_Ui::icon( 'external' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php esc_html_e( 'Current revision', 'blueprint-registry' ); ?>
						</a>
					<?php endif; ?>
				</div>
			</div>

			<div class="bp-review">
				<div class="bp-review__main">
					<?php if ( $submission_id ) : ?>
						<?php Blueprint_Registry_Diff::render_submission( $submission_id, __( 'Changes from the base revision', 'blueprint-registry' ) ); ?>
					<?php else : ?>
						<div class="bp-panel">
							<?php
							Blueprint_Registry_Ui::empty_state(
								'inbox',
								__( 'Nothing submitted', 'blueprint-registry' ),
								__( 'This proposal is still a draft. There is no locked version to review.', 'blueprint-registry' )
							);
							?>
						</div>
					<?php endif; ?>

					<?php if ( $base_id && $files ) : ?>
						<details class="bp-panel">
							<summary class="bp-history-toggle"><?php esc_html_e( 'All submitted bundle files', 'blueprint-registry' ); ?> <span class="bp-panel__head-note"><?php echo esc_html( count( $files ) ); ?></span></summary>
							<table class="bp-files">
								<tbody>
							<?php foreach ( $files as $file ) : ?>
								<tr>
									<td>
										<a class="bp-file-name" href="<?php echo esc_url( self::submission_file_url( $submission_id, $file['key'] ) ); ?>">
											<?php echo Blueprint_Registry_Ui::icon( Blueprint_Registry_Ui::file_icon( $file['path'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
											<?php echo esc_html( $file['path'] ); ?>
										</a>
									</td>
									<td>
										<a class="bp-btn bp-btn--sm bp-btn--ghost" href="<?php echo esc_url( self::submission_file_url( $submission_id, $file['key'], true ) ); ?>">
													<?php echo Blueprint_Registry_Ui::icon( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
													<?php esc_html_e( 'Download', 'blueprint-registry' ); ?>
												</a>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</details>
					<?php endif; ?>
				</div>

				<aside class="bp-review__aside">
					<?php if ( $decidable ) : ?>
						<form class="bp-panel bp-decision" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<div class="bp-panel__head"><h2><?php esc_html_e( 'Your decision', 'blueprint-registry' ); ?></h2></div>
							<input type="hidden" name="action" value="bp_review_change">
							<input type="hidden" name="change_id" value="<?php echo esc_attr( $change->ID ); ?>">
							<input type="hidden" name="submission_id" value="<?php echo esc_attr( $submission_id ); ?>">
							<?php wp_nonce_field( 'bp_review_change_' . $change->ID . '_' . $submission_id ); ?>

							<div class="bp-panel__body">
								<div class="bp-field">
									<label class="bp-field__label" for="bp_review_note"><?php esc_html_e( 'Message to the contributor', 'blueprint-registry' ); ?></label>
									<textarea class="bp-input bp-decision__note" id="bp_review_note" name="note" rows="5" placeholder="<?php esc_attr_e( 'What should change, and why?', 'blueprint-registry' ); ?>"></textarea>
									<p class="bp-field__hint"><?php esc_html_e( 'Required when requesting changes or rejecting.', 'blueprint-registry' ); ?></p>
								</div>

								<div class="bp-decision__actions">
									<button class="bp-btn bp-btn--quiet-danger" type="submit" name="decision" value="rejected">
										<?php echo Blueprint_Registry_Ui::icon( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										<?php esc_html_e( 'Reject proposal', 'blueprint-registry' ); ?>
									</button>
									<button class="bp-btn" type="submit" name="decision" value="changes_requested">
										<?php echo Blueprint_Registry_Ui::icon( 'edit' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										<?php esc_html_e( 'Request changes', 'blueprint-registry' ); ?>
									</button>
									<button class="bp-btn bp-btn--primary" type="submit" name="decision" value="approved">
										<?php echo Blueprint_Registry_Ui::icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										<?php esc_html_e( 'Accept and publish', 'blueprint-registry' ); ?>
									</button>
								</div>
								<p class="bp-field__hint"><?php esc_html_e( 'Accept publishes this submission. Request changes lets the contributor revise it. Reject closes the proposal.', 'blueprint-registry' ); ?></p>
							</div>
						</form>
					<?php else : ?>
						<section class="bp-panel">
							<div class="bp-panel__head"><h2><?php esc_html_e( 'Your decision', 'blueprint-registry' ); ?></h2></div>
							<div class="bp-panel__body">
								<p class="bp-field__hint"><?php esc_html_e( 'There is no submitted version waiting for a decision.', 'blueprint-registry' ); ?></p>
							</div>
						</section>
					<?php endif; ?>

					<section class="bp-panel">
						<div class="bp-panel__head"><h2><?php esc_html_e( 'Details', 'blueprint-registry' ); ?></h2></div>
						<div class="bp-panel__body">
							<dl class="bp-meta-list">
								<div>
									<dt><?php esc_html_e( 'Author', 'blueprint-registry' ); ?></dt>
									<dd><?php echo esc_html( get_the_author_meta( 'display_name', $change->post_author ) ); ?></dd>
								</div>
								<?php if ( $target_id ) : ?>
									<div>
										<dt><?php esc_html_e( 'Target', 'blueprint-registry' ); ?></dt>
										<dd><?php echo esc_html( get_the_title( $target_id ) ); ?></dd>
									</div>
								<?php endif; ?>
								<div>
									<dt><?php esc_html_e( 'Base revision', 'blueprint-registry' ); ?></dt>
									<dd>
										<?php if ( $base_number && $base_blueprint_id ) : ?>
											<a href="<?php echo esc_url( Blueprint_Registry_Routes::release_detail_url( $base_blueprint_id, $base_number ) ); ?>"><?php echo esc_html( sprintf( __( 'Revision %d', 'blueprint-registry' ), $base_number ) ); ?></a>
										<?php else : ?>
											<?php esc_html_e( 'None', 'blueprint-registry' ); ?>
										<?php endif; ?>
									</dd>
								</div>
								<?php if ( $submitted_at ) : ?>
									<div>
										<dt><?php esc_html_e( 'Submitted', 'blueprint-registry' ); ?></dt>
										<dd><?php echo esc_html( wp_date( get_option( 'date_format' ), $submitted_at ) ); ?></dd>
									</div>
								<?php endif; ?>
							</dl>
						</div>
					</section>

					<?php if ( $messages ) : ?>
						<section class="bp-panel">
							<div class="bp-panel__head">
								<h2><?php esc_html_e( 'History', 'blueprint-registry' ); ?></h2>
								<span class="bp-panel__head-note"><?php echo esc_html( count( $messages ) ); ?></span>
							</div>
							<ul class="bp-thread">
								<?php foreach ( $messages as $message ) : ?>
									<?php
									$decision = get_comment_meta( $message->comment_ID, '_bp_review_decision', true );
									$number   = (int) get_post_meta( (int) get_comment_meta( $message->comment_ID, '_bp_submission_id', true ), '_bp_submission_number', true );
									$icon     = 'approved' === $decision ? 'check' : ( 'rejected' === $decision ? 'close' : 'alert' );
									?>
									<li>
										<span class="bp-thread__icon bp-thread__icon--<?php echo esc_attr( $decision ); ?>"><?php echo Blueprint_Registry_Ui::icon( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
										<div class="bp-thread__body">
											<div class="bp-thread__head">
												<strong><?php echo esc_html( $message->comment_author ); ?></strong>
												<span><?php echo esc_html( self::decision_label( $decision ) ); ?></span>
												<?php if ( $number ) : ?><span><?php echo esc_html( sprintf( __( 'submission %d', 'blueprint-registry' ), $number ) ); ?></span><?php endif; ?>
											</div>
											<?php if ( $message->comment_content ) : ?>
												<p class="bp-thread__message"><?php echo esc_html( $message->comment_content ); ?></p>
											<?php endif; ?>
										</div>
									</li>
								<?php endforeach; ?>
							</ul>
						</section>
					<?php endif; ?>
				</aside>
			</div>
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

	/**
	 * Streams one stored proposal file.
	 *
	 * The only way to read private storage. Checks the nonce, then that the
	 * caller is the proposal's author or a reviewer, then that the requested key
	 * actually belongs to the proposal named in the URL — so a valid key for one
	 * proposal cannot be replayed against another.
	 */
	public function serve_bundle_file() {
		$change_id     = isset( $_GET['change_id'] ) ? (int) $_GET['change_id'] : 0;
		$submission_id = isset( $_GET['submission_id'] ) ? (int) $_GET['submission_id'] : 0;
		$key           = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$owner_id      = $submission_id ?: $change_id;

		check_admin_referer( 'bp_bundle_file_' . $owner_id . '_' . $key );

		$owner = get_post( $owner_id );
		if ( ! $owner || ! in_array( $owner->post_type, array( 'blueprint_change', 'blueprint_submission' ), true ) ) {
			wp_die( esc_html__( 'You cannot read this bundle file.', 'blueprint-registry' ), '', array( 'response' => 403 ) );
		}

		$change = 'blueprint_submission' === $owner->post_type ? get_post( (int) $owner->post_parent ) : $owner;
		if ( ! $change || 'blueprint_change' !== $change->post_type ) {
			wp_die( esc_html__( 'You cannot read this bundle file.', 'blueprint-registry' ), '', array( 'response' => 403 ) );
		}

		if ( (int) $change->post_author !== get_current_user_id() && ! Blueprint_Registry_Capabilities::can_review() ) {
			wp_die( esc_html__( 'You cannot read this bundle file.', 'blueprint-registry' ), '', array( 'response' => 403 ) );
		}

		$files  = 'blueprint_submission' === $owner->post_type
			? Blueprint_Registry_Bundles::get_submission_files( $owner_id )
			: Blueprint_Registry_Bundles::get_change_files( $owner_id );
		$record = null;
		foreach ( $files as $candidate ) {
			if ( ( $candidate['key'] ?? '' ) === $key ) {
				$record = $candidate;
			}
		}

		if ( ! $record ) {
			wp_die( esc_html__( 'This bundle file is no longer available.', 'blueprint-registry' ), esc_html__( 'Not found', 'blueprint-registry' ), array( 'response' => 404 ) );
		}

		$path = Blueprint_Registry_Storage::file_path( $owner_id, $key );
		if ( is_wp_error( $path ) || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'This bundle file is no longer available.', 'blueprint-registry' ), esc_html__( 'Not found', 'blueprint-registry' ), array( 'response' => 404 ) );
		}

		nocache_headers();
		// Never the file's own type: a bundle may hold HTML or SVG, and this is
		// served from the site's own origin.
		header( 'Content-Type: application/octet-stream' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: ' . ( isset( $_GET['download'] ) ? 'attachment' : 'inline' ) . '; filename="' . sanitize_file_name( wp_basename( $record['path'] ) ) . '"' );
		header( 'Cache-Control: private, no-store' );
		readfile( $path ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public function remove_change_file() {
		$change_id = isset( $_GET['change_id'] ) ? (int) $_GET['change_id'] : 0;
		$key       = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		check_admin_referer( 'bp_remove_file_' . $change_id . '_' . $key );
		$change = get_post( $change_id );
		$status = $change ? get_post_meta( $change_id, '_bp_status', true ) ?: 'draft' : '';
		if ( ! $change || (int) $change->post_author !== get_current_user_id() || ! in_array( $status, array( 'draft', 'changes_requested' ), true ) ) {
			wp_die( esc_html__( 'You cannot remove this file.', 'blueprint-registry' ), 403 );
		}
		$file_exists = false;
		foreach ( Blueprint_Registry_Bundles::get_change_files( $change_id ) as $file ) {
			if ( ( $file['key'] ?? '' ) === $key ) {
				$file_exists = true;
				break;
			}
		}
		if ( ! $file_exists ) {
			wp_die( esc_html__( 'This bundle file is no longer available.', 'blueprint-registry' ), esc_html__( 'Not found', 'blueprint-registry' ), array( 'response' => 404 ) );
		}
		Blueprint_Registry_Bundles::remove_change_file( $change_id, $key );
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

	/**
	 * Takes a browser-created bundle ZIP into the proposal's private storage.
	 *
	 * Raw bundle files never reach this endpoint. WordPress first accepts the
	 * archive through its ordinary ZIP upload rules, then the bundle layer checks
	 * and unpacks its entries.
	 */
	public static function save_uploaded_file( $change_id ) {
		if ( empty( $_FILES['bp_bundle_file']['name'] ) || UPLOAD_ERR_NO_FILE === (int) $_FILES['bp_bundle_file']['error'] ) {
			return;
		}

		$file = $_FILES['bp_bundle_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			update_post_meta( $change_id, '_bp_upload_error', self::upload_error_message( (int) $file['error'] ) );
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$uploaded = wp_handle_upload( $file, array( 'test_form' => false ) );
		if ( ! empty( $uploaded['error'] ) ) {
			update_post_meta( $change_id, '_bp_upload_error', $uploaded['error'] );
			return;
		}

		$stored = Blueprint_Registry_Bundles::import_change_archive( $change_id, $uploaded['file'] );
		@unlink( $uploaded['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_wp_error( $stored ) ) {
			update_post_meta( $change_id, '_bp_upload_error', $stored->get_error_message() );
			return;
		}

		delete_post_meta( $change_id, '_bp_upload_error' );
	}

	private static function upload_error_message( $code ) {
		if ( UPLOAD_ERR_INI_SIZE === $code || UPLOAD_ERR_FORM_SIZE === $code ) {
			return __( 'That file is larger than this server accepts in one upload.', 'blueprint-registry' );
		}

		return __( 'That file could not be uploaded.', 'blueprint-registry' );
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
		printf(
			'<a class="bp-btn bp-btn--sm bp-btn--primary" href="%1$s">%2$s</a>',
			esc_url( self::review_url( $change->ID ) ),
			esc_html__( 'Review', 'blueprint-registry' )
		);
	}

	private static function status_label( $status ) {
		$labels = array(
			'draft'             => __( 'Draft', 'blueprint-registry' ),
			'pending_review'    => __( 'In review', 'blueprint-registry' ),
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
