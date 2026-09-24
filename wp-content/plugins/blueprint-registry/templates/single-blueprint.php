<?php
/**
 * Public Blueprint page: what it does, how to run it, and every release ever
 * published for it.
 *
 * @package BlueprintRegistry
 */

defined( 'ABSPATH' ) || exit;

$blueprint_id       = get_the_ID();
$current_release_id = (int) get_post_meta( $blueprint_id, '_bp_current_release_id', true );
$current_number     = (int) get_post_meta( $current_release_id, '_bp_release_number', true );
$requested_number   = isset( $_GET['release'] ) ? absint( $_GET['release'] ) : $current_number;
$releases           = Blueprint_Registry_Routes::releases( $blueprint_id );
$selected_release   = null;

foreach ( $releases as $release ) {
	if ( $requested_number === (int) get_post_meta( $release->ID, '_bp_release_number', true ) ) {
		$selected_release = $release;
		break;
	}
}
if ( ! $selected_release && $releases ) {
	$selected_release = $releases[0];
}

$selected_number = $selected_release ? (int) get_post_meta( $selected_release->ID, '_bp_release_number', true ) : 0;
$is_current      = $selected_number === $current_number;
$files           = $selected_release ? Blueprint_Registry_Bundles::release_file_entries( $selected_release->ID ) : array();
$forked_from     = (int) get_post_meta( $blueprint_id, '_bp_forked_from_blueprint_id', true );
$author_id       = (int) get_post_field( 'post_author', $blueprint_id );
$categories      = wp_get_post_terms( $blueprint_id, 'blueprint_category', array( 'fields' => 'names' ) );
$categories      = is_wp_error( $categories ) ? array() : $categories;
$can_edit        = is_user_logged_in() && get_current_user_id() === $author_id;
$bundle_url      = $selected_release ? Blueprint_Registry_Routes::release_url( $blueprint_id, $selected_number, 'bundle' ) : '';
$run_url         = $bundle_url ? 'https://playground.wordpress.net/?blueprint-url=' . rawurlencode( $bundle_url ) : '';
$comparing       = ! empty( $_GET['compare'] ) && $selected_release && ! $is_current;
$summary         = $selected_release ? Blueprint_Registry_Ui::summarise( get_post_field( 'post_content', $selected_release->ID ) ) : '';
$declaration     = $selected_release ? Blueprint_Registry_Bundles::release_file_contents( $selected_release->ID, 'blueprint.json' ) : new WP_Error( 'none', '' );
$declaration     = is_wp_error( $declaration ) ? '' : $declaration;
$presentation = Blueprint_Registry_Workflow::presentation( $selected_release ? $selected_release->post_content : '' );
$display_title = $presentation['title'] ?? get_the_title( $blueprint_id );
$description = $presentation['description'] ?? '';
$total_size      = 0;
if ( ! is_wp_error( $files ) ) {
	foreach ( $files as $file ) {
		$total_size += (int) $file['size'];
	}
}

Blueprint_Registry_Ui::open( 'bp-screen-blueprint' );
?>
<main class="bp-app__main" id="main-content">
	<a class="bp-back" href="<?php echo esc_url( get_post_type_archive_link( 'blueprint' ) ); ?>">
		<?php echo Blueprint_Registry_Ui::icon( 'arrow-left' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php esc_html_e( 'Blueprint Gallery', 'blueprint-registry' ); ?>
	</a>

	<?php Blueprint_Registry_Ui::notice(); ?>

	<?php if ( $selected_release && ! $is_current ) : ?>
		<div class="bp-notice bp-notice--warn">
			<?php echo Blueprint_Registry_Ui::icon( 'clock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<div>
				<strong><?php echo esc_html( sprintf( __( 'You are viewing revision %d.', 'blueprint-registry' ), $selected_number ) ); ?></strong>
				<?php esc_html_e( 'Revisions never change once published.', 'blueprint-registry' ); ?>
				<a href="<?php echo esc_url( Blueprint_Registry_Routes::release_detail_url( $blueprint_id, $current_number ) ); ?>"><?php echo esc_html( sprintf( __( 'Go to the current revision (%d)', 'blueprint-registry' ), $current_number ) ); ?></a>
			</div>
		</div>
	<?php endif; ?>

	<div class="bp-detail__hero">
		<div>
			<h1 class="bp-detail__title"><?php echo esc_html( $display_title ); ?></h1>
			<p class="bp-detail__byline">
				<?php echo get_avatar( $author_id, 44, '', '', array( 'class' => 'bp-detail__avatar' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span><?php echo esc_html( get_the_author_meta( 'display_name', $author_id ) ); ?></span>
				<?php if ( $categories ) : ?>
					<span class="bp-detail__dot">&middot;</span>
					<span><?php echo esc_html( implode( ', ', $categories ) ); ?></span>
				<?php endif; ?>
				<?php if ( $forked_from ) : ?>
					<span class="bp-detail__dot">&middot;</span>
					<span class="bp-chip bp-chip--fork"><?php echo esc_html( sprintf( __( 'Forked from %s', 'blueprint-registry' ), get_the_title( $forked_from ) ) ); ?></span>
				<?php endif; ?>
			</p>

			<?php if ( $description ) : ?>
				<div class="bp-detail__description"><?php echo wp_kses_post( wpautop( esc_html( $description ) ) ); ?></div>
			<?php endif; ?>

			<?php if ( $summary ) : ?>
				<p class="bp-blurb">
					<?php echo Blueprint_Registry_Ui::icon( 'sparkle', 'bp-blurb__mark' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span><?php echo wp_kses( $summary, array( 'strong' => array() ) ); ?></span>
				</p>
			<?php endif; ?>

			<?php if ( $selected_release ) : ?>
				<div class="bp-detail__actions">
					<a class="bp-btn bp-btn--primary bp-btn--lg" href="<?php echo esc_url( $run_url ); ?>" rel="noreferrer noopener">
						<?php echo Blueprint_Registry_Ui::icon( 'play' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php esc_html_e( 'Run in Playground', 'blueprint-registry' ); ?>
					</a>
					<a class="bp-btn bp-btn--lg" href="<?php echo esc_url( $bundle_url ); ?>">
						<?php echo Blueprint_Registry_Ui::icon( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php esc_html_e( 'Download', 'blueprint-registry' ); ?>
					</a>
					<?php if ( Blueprint_Registry_Capabilities::can_contribute() ) : ?>
						<a class="bp-btn bp-btn--lg" href="<?php echo esc_url( Blueprint_Registry_Frontend::new_editor_url( $blueprint_id, 'fork' ) ); ?>">
							<?php echo Blueprint_Registry_Ui::icon( 'fork' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo $is_current ? esc_html__( 'Fork', 'blueprint-registry' ) : esc_html__( 'Fork current revision', 'blueprint-registry' ); ?>
						</a>
						<?php if ( $can_edit ) : ?>
							<a class="bp-btn bp-btn--lg" href="<?php echo esc_url( Blueprint_Registry_Frontend::new_editor_url( $blueprint_id, 'update' ) ); ?>">
								<?php echo Blueprint_Registry_Ui::icon( 'edit' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php esc_html_e( 'Edit', 'blueprint-registry' ); ?>
							</a>
						<?php endif; ?>
					<?php else : ?>
						<a class="bp-btn bp-btn--lg" href="<?php echo esc_url( wp_login_url( Blueprint_Registry_Frontend::new_editor_url( $blueprint_id, 'fork' ) ) ); ?>"><?php echo $is_current ? esc_html__( 'Fork', 'blueprint-registry' ) : esc_html__( 'Fork current revision', 'blueprint-registry' ); ?></a>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>

		<div class="bp-detail__preview">
			<div class="bp-detail__preview-frame">
				<span class="bp-detail__preview-dots"><span></span><span></span><span></span></span>
				<span class="bp-detail__preview-url">playground.wordpress.net</span>
			</div>
			<div class="bp-detail__preview-image">
				<?php if ( has_post_thumbnail( $blueprint_id ) ) : ?>
					<?php echo get_the_post_thumbnail( $blueprint_id, 'large', array( 'loading' => 'eager', 'alt' => '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php else : ?>
					<span class="bp-detail__preview-empty"><?php echo esc_html( strtoupper( substr( get_the_title(), 0, 1 ) ) ); ?></span>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<?php if ( ! $selected_release ) : ?>
		<div class="bp-panel">
			<?php
			Blueprint_Registry_Ui::empty_state(
				'inbox',
				__( 'No published revisions yet', 'blueprint-registry' ),
				__( 'This Blueprint has not been through review, so there is nothing to run or download.', 'blueprint-registry' )
			);
			?>
		</div>
	<?php else : ?>
		<div class="bp-detail__layout">
			<div>


				<?php if ( $comparing ) : ?>
					<?php
					Blueprint_Registry_Diff::render_releases(
						$selected_release->ID,
						$current_release_id,
						/* translators: 1: selected revision, 2: current revision. */
						sprintf( __( 'Revision %1$d compared with revision %2$d', 'blueprint-registry' ), $selected_number, $current_number )
					);
					?>
				<?php elseif ( $declaration ) : ?>
					<?php $declaration_lines = explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", $declaration ) ); ?>
					<section class="bp-panel">
						<div class="bp-panel__head">
							<h2>blueprint.json</h2>
							<span class="bp-panel__head-actions">
								<span class="bp-panel__head-note"><?php echo esc_html( sprintf( _n( '%d line', '%d lines', count( $declaration_lines ), 'blueprint-registry' ), count( $declaration_lines ) ) ); ?></span>
								<button type="button" class="bp-btn bp-btn--sm bp-btn--ghost" data-bp-copy="<?php echo esc_attr( $declaration ); ?>">
									<?php echo Blueprint_Registry_Ui::icon( 'copy' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<span data-copied="<?php esc_attr_e( 'Copied', 'blueprint-registry' ); ?>"><?php esc_html_e( 'Copy', 'blueprint-registry' ); ?></span>
								</button>
							</span>
						</div>
						<div class="bp-code bp-code--capped">
							<table>
								<tbody>
									<?php foreach ( $declaration_lines as $index => $line ) : ?>
										<tr>
											<td class="bp-code__ln"><?php echo esc_html( $index + 1 ); ?></td>
											<td class="bp-code__line"><?php echo '' === $line ? ' ' : Blueprint_Registry_Ui::highlight_json( $line ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</section>
				<?php endif; ?>

				<section class="bp-panel">
					<div class="bp-panel__head">
						<h2><?php echo esc_html( sprintf( __( 'Files in revision %d', 'blueprint-registry' ), $selected_number ) ); ?></h2>
						<span class="bp-panel__head-note"><?php echo esc_html( size_format( $total_size ) ); ?></span>
					</div>
					<?php if ( is_wp_error( $files ) ) : ?>
						<div class="bp-panel__body"><p><?php echo esc_html( $files->get_error_message() ); ?></p></div>
					<?php else : ?>
						<table class="bp-files">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Path', 'blueprint-registry' ); ?></th>
									<th><?php esc_html_e( 'Size', 'blueprint-registry' ); ?></th>
									<th><span class="bp-visually-hidden"><?php esc_html_e( 'Actions', 'blueprint-registry' ); ?></span></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $files as $file ) : ?>
									<?php
									$read_url     = 'blueprint.json' === $file['path']
										? Blueprint_Registry_Routes::release_file_url( $blueprint_id, $selected_number, 'blueprint.json' )
										: Blueprint_Registry_Routes::release_file_url( $blueprint_id, $selected_number, $file['path'] );
									$download_url = add_query_arg( 'download', '1', $read_url );
									?>
									<tr>
										<td>
											<a class="bp-file-name" href="<?php echo esc_url( $read_url ); ?>">
												<?php echo Blueprint_Registry_Ui::icon( Blueprint_Registry_Ui::file_icon( $file['path'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
												<?php echo esc_html( $file['path'] ); ?>
											</a>
										</td>
										<td class="bp-files__size"><?php echo esc_html( size_format( $file['size'] ) ); ?></td>
										<td>
											<span class="bp-row-actions">
												<a class="bp-btn bp-btn--sm bp-btn--ghost" href="<?php echo esc_url( $read_url ); ?>"><?php esc_html_e( 'View', 'blueprint-registry' ); ?></a>
												<a class="bp-btn bp-btn--sm bp-btn--ghost" href="<?php echo esc_url( $download_url ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Download %s', 'blueprint-registry' ), $file['path'] ) ); ?>">
													<?php echo Blueprint_Registry_Ui::icon( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
												</a>
											</span>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</section>
			</div>

			<aside>
				<section class="bp-panel bp-panel--overflow">
					<div class="bp-panel__head"><h2><?php esc_html_e( 'Use this Blueprint', 'blueprint-registry' ); ?></h2></div>
					<div class="bp-panel__body">
						<p style="margin:0 0 .625rem;color:var(--bp-ink-3);font-size:.8125rem;line-height:1.55">
							<?php esc_html_e( 'Point any Playground instance at this bundle URL.', 'blueprint-registry' ); ?>
						</p>
						<?php Blueprint_Registry_Ui::copy_field( $bundle_url, __( 'Copy the bundle URL', 'blueprint-registry' ) ); ?>
						<dl class="bp-meta-list" style="margin-top:1rem">
							<div>
								<dt><?php esc_html_e( 'Revision', 'blueprint-registry' ); ?></dt>
								<dd>
									<?php if ( count( $releases ) > 1 ) : ?>
										<details class="bp-picker">
											<summary>
												<span><?php echo esc_html( sprintf( __( '%1$d (from %2$s)', 'blueprint-registry' ), $selected_number, get_the_date( 'Y-m-d', $selected_release ) ) ); ?></span>
												<?php echo Blueprint_Registry_Ui::icon( 'chevron' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
											</summary>
											<div class="bp-picker__menu">
												<?php foreach ( $releases as $release ) : ?>
													<?php $number = (int) get_post_meta( $release->ID, '_bp_release_number', true ); ?>
													<a class="bp-menu__item<?php echo $number === $selected_number ? ' is-selected' : ''; ?>" href="<?php echo esc_url( Blueprint_Registry_Routes::release_detail_url( $blueprint_id, $number ) ); ?>">
														<span><?php echo esc_html( sprintf( __( 'Revision %d', 'blueprint-registry' ), $number ) ); ?></span>
														<span class="bp-menu__count">
															<?php echo esc_html( get_the_date( 'Y-m-d', $release ) ); ?><?php echo $number === $current_number ? ' · ' . esc_html__( 'current', 'blueprint-registry' ) : ''; ?>
														</span>
													</a>
												<?php endforeach; ?>
											</div>
										</details>
									<?php else : ?>
										<?php echo esc_html( sprintf( __( '%1$d (from %2$s)', 'blueprint-registry' ), $selected_number, get_the_date( 'Y-m-d', $selected_release ) ) ); ?>
									<?php endif; ?>
								</dd>
							</div>
							<div>
								<dt><?php esc_html_e( 'Bundle size', 'blueprint-registry' ); ?></dt>
								<dd><?php echo esc_html( size_format( $total_size ) ); ?></dd>
							</div>
						</dl>
						<?php if ( ! $is_current ) : ?>
							<a class="bp-btn bp-btn--sm bp-revisions__compare" href="<?php echo esc_url( $comparing ? Blueprint_Registry_Routes::release_detail_url( $blueprint_id, $selected_number ) : add_query_arg( 'compare', '1', Blueprint_Registry_Routes::release_detail_url( $blueprint_id, $selected_number ) ) ); ?>">
								<?php echo Blueprint_Registry_Ui::icon( 'diff' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<?php
								echo $comparing
									? esc_html__( 'Stop comparing', 'blueprint-registry' )
									/* translators: %d: the current revision number. */
									: esc_html( sprintf( __( 'Compare with revision %d', 'blueprint-registry' ), $current_number ) );
								?>
							</a>
						<?php endif; ?>


					</div>
				</section>

			</aside>
		</div>
	<?php endif; ?>
</main>
<?php
Blueprint_Registry_Ui::close();
