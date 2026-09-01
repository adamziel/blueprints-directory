<?php
/**
 * Public Blueprint gallery archive.
 *
 * @package BlueprintRegistry
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main class="bp-gallery" id="main-content">
	<header class="bp-gallery__header">
		<p class="bp-gallery__eyebrow"><?php esc_html_e( 'WordPress Playground', 'blueprint-registry' ); ?></p>
		<h1><?php esc_html_e( 'Blueprint Gallery', 'blueprint-registry' ); ?></h1>
		<p class="bp-gallery__description"><?php esc_html_e( 'Open a complete WordPress setup in your browser, then fork it or propose a better version.', 'blueprint-registry' ); ?></p>
		<?php if ( Blueprint_Registry_Capabilities::can_contribute() ) : ?>
			<p class="bp-gallery__header-action"><a class="bp-gallery__manage-link" href="<?php echo esc_url( Blueprint_Registry_Frontend::dashboard_url() ); ?>"><?php esc_html_e( 'My Blueprints', 'blueprint-registry' ); ?></a></p>
		<?php endif; ?>
	</header>

	<?php if ( have_posts() ) : ?>
		<section class="bp-gallery__grid" aria-label="<?php esc_attr_e( 'Published Blueprints', 'blueprint-registry' ); ?>">
			<?php while ( have_posts() ) : the_post(); ?>
				<?php
				$blueprint_id = get_the_ID();
				$release_id   = (int) get_post_meta( $blueprint_id, '_bp_current_release_id', true );
				$release       = (int) get_post_meta( $release_id, '_bp_release_number', true );
				$bundle_url    = Blueprint_Registry_Routes::release_url( $blueprint_id, $release, 'bundle' );
				$run_url       = 'https://playground.wordpress.net/?blueprint-url=' . rawurlencode( $bundle_url );
				$categories    = wp_get_post_terms( $blueprint_id, 'blueprint_category', array( 'fields' => 'names' ) );
				?>
				<article <?php post_class( 'bp-tile' ); ?>>
					<a class="bp-tile__image" href="<?php the_permalink(); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'View %s', 'blueprint-registry' ), get_the_title() ) ); ?>">
						<?php if ( has_post_thumbnail() ) : ?>
							<?php the_post_thumbnail( 'large', array( 'loading' => 'lazy' ) ); ?>
						<?php else : ?>
							<span class="bp-tile__placeholder" aria-hidden="true"><?php echo esc_html( strtoupper( substr( get_the_title(), 0, 1 ) ) ); ?></span>
						<?php endif; ?>
					</a>
					<div class="bp-tile__body">
						<?php if ( $categories ) : ?><p class="bp-tile__categories"><?php echo esc_html( implode( ' · ', array_slice( $categories, 0, 2 ) ) ); ?></p><?php endif; ?>
						<h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
						<p class="bp-tile__description"><?php echo esc_html( wp_trim_words( get_the_content(), 24 ) ); ?></p>
						<div class="bp-tile__footer">
							<span><?php echo esc_html( sprintf( __( 'Release %d', 'blueprint-registry' ), $release ) ); ?></span>
							<div class="bp-tile__actions">
								<a class="bp-tile__button" href="<?php the_permalink(); ?>"><?php esc_html_e( 'Details', 'blueprint-registry' ); ?><span class="screen-reader-text">: <?php the_title(); ?></span></a>
								<a class="bp-tile__button bp-tile__button--primary" href="<?php echo esc_url( $run_url ); ?>"><?php esc_html_e( 'Run', 'blueprint-registry' ); ?><span class="screen-reader-text">: <?php the_title(); ?></span></a>
							</div>
						</div>
					</div>
				</article>
			<?php endwhile; ?>
		</section>
	<?php else : ?>
		<p class="bp-gallery__empty"><?php esc_html_e( 'There are no public Blueprints yet.', 'blueprint-registry' ); ?></p>
	<?php endif; ?>
</main>
<?php
get_footer();
