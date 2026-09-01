<?php
/**
 * Public Blueprint detail page with immutable release history.
 *
 * @package BlueprintRegistry
 */

defined( 'ABSPATH' ) || exit;

get_header();

$blueprint_id      = get_the_ID();
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
$files           = $selected_release ? Blueprint_Registry_Bundles::release_file_entries( $selected_release->ID ) : array();
$forked_from     = (int) get_post_meta( $blueprint_id, '_bp_forked_from_blueprint_id', true );
$run_url         = $selected_release ? 'https://playground.wordpress.net/?blueprint-url=' . rawurlencode( Blueprint_Registry_Routes::release_url( $blueprint_id, $selected_number, 'bundle' ) ) : '';
$can_edit        = Blueprint_Registry_Capabilities::can_contribute() && (int) get_post_field( 'post_author', $blueprint_id ) === get_current_user_id();
?>
<main class="bp-blueprint" id="main-content">
	<div class="bp-blueprint__hero">
		<header class="bp-blueprint__header">
			<p><a href="<?php echo esc_url( get_post_type_archive_link( 'blueprint' ) ); ?>">&larr; <?php esc_html_e( 'Blueprint Gallery', 'blueprint-registry' ); ?></a></p>
			<h1><?php the_title(); ?></h1>
			<p class="bp-blueprint__byline"><?php echo esc_html( sprintf( __( 'Created by %s', 'blueprint-registry' ), get_the_author_meta( 'display_name', (int) get_post_field( 'post_author', $blueprint_id ) ) ) ); ?></p>
			<?php if ( get_post_field( 'post_content', $blueprint_id ) ) : ?><div class="bp-blueprint__description"><?php echo wp_kses_post( wpautop( get_post_field( 'post_content', $blueprint_id ) ) ); ?></div><?php endif; ?>
			<?php if ( $forked_from ) : ?><p class="bp-blueprint__forked-from"><?php echo esc_html( sprintf( __( 'Forked from %s.', 'blueprint-registry' ), get_the_title( $forked_from ) ) ); ?> <a href="<?php echo esc_url( get_permalink( $forked_from ) ); ?>"><?php esc_html_e( 'View original', 'blueprint-registry' ); ?></a></p><?php endif; ?>
		</header>
		<div class="bp-blueprint__thumbnail">
			<?php if ( has_post_thumbnail( $blueprint_id ) ) : ?>
				<?php echo get_the_post_thumbnail( $blueprint_id, 'large', array( 'loading' => 'eager' ) ); ?>
			<?php else : ?>
				<span class="bp-blueprint__thumbnail-placeholder" aria-hidden="true"><?php echo esc_html( strtoupper( substr( get_the_title( $blueprint_id ), 0, 1 ) ) ); ?></span>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( $selected_release ) : ?>
		<section class="bp-blueprint__actions" aria-label="<?php esc_attr_e( 'Blueprint actions', 'blueprint-registry' ); ?>">
			<a class="bp-blueprint__button bp-blueprint__button--primary" href="<?php echo esc_url( $run_url ); ?>"><?php esc_html_e( 'Run in Playground', 'blueprint-registry' ); ?></a>
			<a class="bp-blueprint__button" href="<?php echo esc_url( Blueprint_Registry_Routes::release_url( $blueprint_id, $selected_number, 'json' ) ); ?>"><?php esc_html_e( 'View JSON', 'blueprint-registry' ); ?></a>
			<a class="bp-blueprint__button" href="<?php echo esc_url( Blueprint_Registry_Routes::release_url( $blueprint_id, $selected_number, 'bundle' ) ); ?>"><?php esc_html_e( 'Download', 'blueprint-registry' ); ?></a>
			<?php if ( Blueprint_Registry_Capabilities::can_contribute() ) : ?>
				<a class="bp-blueprint__button" href="<?php echo esc_url( Blueprint_Registry_Frontend::new_editor_url( $blueprint_id, 'fork' ) ); ?>"><?php esc_html_e( 'Fork', 'blueprint-registry' ); ?></a>
				<?php if ( $can_edit ) : ?>
					<a class="bp-blueprint__button" href="<?php echo esc_url( Blueprint_Registry_Frontend::new_editor_url( $blueprint_id, 'update' ) ); ?>"><?php esc_html_e( 'Edit', 'blueprint-registry' ); ?></a>
				<?php endif; ?>
			<?php else : ?>
				<a class="bp-blueprint__button" href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>"><?php esc_html_e( 'Sign in to contribute', 'blueprint-registry' ); ?></a>
			<?php endif; ?>
		</section>
	<?php endif; ?>

	<div class="bp-blueprint__layout">
		<section class="bp-blueprint__main">
			<?php if ( $selected_release ) : ?>
				<section class="bp-blueprint__panel">
					<h2><?php echo esc_html( sprintf( __( 'Files in release %d', 'blueprint-registry' ), $selected_number ) ); ?></h2>
					<p><?php esc_html_e( 'Every listed file is part of this immutable release.', 'blueprint-registry' ); ?></p>
					<?php if ( is_wp_error( $files ) ) : ?>
						<p><?php echo esc_html( $files->get_error_message() ); ?></p>
					<?php else : ?>
						<table class="bp-blueprint__files">
							<thead><tr><th><?php esc_html_e( 'Path', 'blueprint-registry' ); ?></th><th><?php esc_html_e( 'Size', 'blueprint-registry' ); ?></th><th><span class="screen-reader-text"><?php esc_html_e( 'Open', 'blueprint-registry' ); ?></span></th></tr></thead>
						<tbody>
							<?php foreach ( $files as $file ) : ?>
								<?php $read_url = 'blueprint.json' === $file['path'] ? Blueprint_Registry_Routes::release_url( $blueprint_id, $selected_number, 'json' ) : Blueprint_Registry_Routes::release_file_url( $blueprint_id, $selected_number, $file['path'] ); $download_url = add_query_arg( 'download', '1', $read_url ); ?>
								<tr>
									<td><a href="<?php echo esc_url( $read_url ); ?>"><code><?php echo esc_html( $file['path'] ); ?></code></a></td>
									<td><?php echo esc_html( size_format( $file['size'] ) ); ?></td>
									<td><span class="bp-blueprint__file-actions"><a href="<?php echo esc_url( $read_url ); ?>"><?php esc_html_e( 'Open', 'blueprint-registry' ); ?></a><a href="<?php echo esc_url( $download_url ); ?>"><?php esc_html_e( 'Download', 'blueprint-registry' ); ?></a></span></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</section>
			<?php else : ?>
				<p><?php esc_html_e( 'This Blueprint has no published releases yet.', 'blueprint-registry' ); ?></p>
			<?php endif; ?>
		</section>
		<aside class="bp-blueprint__releases" aria-label="<?php esc_attr_e( 'Release history', 'blueprint-registry' ); ?>">
			<h2><?php esc_html_e( 'Release history', 'blueprint-registry' ); ?></h2>
			<ol>
				<?php foreach ( $releases as $release ) : ?>
					<?php $number = (int) get_post_meta( $release->ID, '_bp_release_number', true ); ?>
					<li class="<?php echo $number === $selected_number ? 'is-selected' : ''; ?>"><a href="<?php echo esc_url( Blueprint_Registry_Routes::release_detail_url( $blueprint_id, $number ) ); ?>"><?php echo esc_html( sprintf( __( 'Release %d', 'blueprint-registry' ), $number ) ); ?></a><span class="bp-blueprint__release-meta"><?php if ( $number === $current_number ) : ?><strong><?php esc_html_e( 'Current', 'blueprint-registry' ); ?></strong><?php endif; ?><time datetime="<?php echo esc_attr( get_the_date( 'c', $release ) ); ?>"><?php echo esc_html( get_the_date( get_option( 'date_format' ), $release ) ); ?></time></span></li>
				<?php endforeach; ?>
			</ol>
		</aside>
	</div>
</main>
<?php
get_footer();
