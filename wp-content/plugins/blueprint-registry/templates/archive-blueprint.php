<?php
/**
 * Public Blueprint gallery.
 *
 * Laid out as a DataViews list: a toolbar carrying the view state, then either
 * a grid or a table of the same records.
 *
 * @package BlueprintRegistry
 */

defined( 'ABSPATH' ) || exit;

$search     = Blueprint_Registry_Routes::gallery_search();
$category   = Blueprint_Registry_Routes::gallery_category();
$sort       = Blueprint_Registry_Routes::gallery_sort();
$layout     = Blueprint_Registry_Routes::gallery_layout();
$categories = get_terms(
	array(
		'taxonomy'   => 'blueprint_category',
		'hide_empty' => true,
		'orderby'    => 'count',
		'order'      => 'DESC',
	)
);
$categories = is_wp_error( $categories ) ? array() : $categories;
$total      = (int) $GLOBALS['wp_query']->found_posts;
$sort_labels = array(
	'featured' => __( 'Featured', 'blueprint-registry' ),
	'title'    => __( 'Name', 'blueprint-registry' ),
	'updated'  => __( 'Recently updated', 'blueprint-registry' ),
);

Blueprint_Registry_Ui::open( 'bp-screen-gallery' );
?>
<main class="bp-app__main" id="main-content">
	<div class="bp-hero">
		<div class="bp-hero__row">
			<div>
				<h1><?php esc_html_e( 'Blueprint Gallery', 'blueprint-registry' ); ?></h1>
				<p><?php esc_html_e( 'Complete WordPress setups that open in your browser in seconds. Run one, or fork it into a Blueprint of your own.', 'blueprint-registry' ); ?></p>
			</div>
			<a class="bp-btn bp-btn--primary" href="<?php echo esc_url( Blueprint_Registry_Frontend::new_editor_url() ); ?>">
				<?php echo Blueprint_Registry_Ui::icon( 'plus' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php esc_html_e( 'Propose a new Blueprint', 'blueprint-registry' ); ?>
			</a>
		</div>
	</div>

	<?php Blueprint_Registry_Ui::notice(); ?>

	<div class="bpv<?php echo 'grid' === $layout ? ' bpv--cards' : ''; ?>">
		<form class="bpv__toolbar" method="get" action="<?php echo esc_url( get_post_type_archive_link( 'blueprint' ) ); ?>">
			<div class="bpv__search">
				<?php echo Blueprint_Registry_Ui::icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<label class="bp-visually-hidden" for="bp-gallery-search"><?php esc_html_e( 'Search Blueprints', 'blueprint-registry' ); ?></label>
				<input type="search" id="bp-gallery-search" name="bp_q" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search Blueprints…', 'blueprint-registry' ); ?>">
			</div>
			<?php if ( $category ) : ?><input type="hidden" name="bp_category" value="<?php echo esc_attr( $category ); ?>"><?php endif; ?>
			<?php if ( 'featured' !== $sort ) : ?><input type="hidden" name="bp_sort" value="<?php echo esc_attr( $sort ); ?>"><?php endif; ?>
			<?php if ( 'grid' !== $layout ) : ?><input type="hidden" name="bp_layout" value="<?php echo esc_attr( $layout ); ?>"><?php endif; ?>

			<div class="bpv__toolbar-group" data-bp-menu>
				<button type="button" class="bpv__control<?php echo $category ? ' is-active' : ''; ?>" aria-expanded="false" data-bp-menu-toggle>
					<?php echo Blueprint_Registry_Ui::icon( 'tag' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php esc_html_e( 'Category', 'blueprint-registry' ); ?>
					<?php echo Blueprint_Registry_Ui::icon( 'chevron' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</button>
				<div class="bp-menu" data-bp-menu-panel hidden>
					<a class="bp-menu__item<?php echo $category ? '' : ' is-selected'; ?>" href="<?php echo esc_url( Blueprint_Registry_Routes::gallery_url( array( 'bp_category' => '' ) ) ); ?>"><?php esc_html_e( 'All categories', 'blueprint-registry' ); ?></a>
					<?php foreach ( $categories as $term ) : ?>
						<a class="bp-menu__item<?php echo $category === $term->slug ? ' is-selected' : ''; ?>" href="<?php echo esc_url( Blueprint_Registry_Routes::gallery_url( array( 'bp_category' => $term->slug ) ) ); ?>">
							<span><?php echo esc_html( $term->name ); ?></span>
							<span class="bp-menu__count"><?php echo esc_html( $term->count ); ?></span>
						</a>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="bpv__toolbar-group" data-bp-menu>
				<button type="button" class="bpv__control" aria-expanded="false" data-bp-menu-toggle>
					<?php echo Blueprint_Registry_Ui::icon( 'list' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo esc_html( $sort_labels[ $sort ] ); ?>
					<?php echo Blueprint_Registry_Ui::icon( 'chevron' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</button>
				<div class="bp-menu" data-bp-menu-panel hidden>
					<?php foreach ( $sort_labels as $key => $label ) : ?>
						<a class="bp-menu__item<?php echo $sort === $key ? ' is-selected' : ''; ?>" href="<?php echo esc_url( Blueprint_Registry_Routes::gallery_url( array( 'bp_sort' => 'featured' === $key ? '' : $key ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="bpv__toolbar-spacer"></div>

			<div class="bpv__layouts" role="group" aria-label="<?php esc_attr_e( 'Layout', 'blueprint-registry' ); ?>">
				<a class="bpv__layout" href="<?php echo esc_url( Blueprint_Registry_Routes::gallery_url( array( 'bp_layout' => '' ) ) ); ?>" aria-pressed="<?php echo 'grid' === $layout ? 'true' : 'false'; ?>" title="<?php esc_attr_e( 'Grid', 'blueprint-registry' ); ?>">
					<?php echo Blueprint_Registry_Ui::icon( 'grid' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span class="bp-visually-hidden"><?php esc_html_e( 'Grid', 'blueprint-registry' ); ?></span>
				</a>
				<a class="bpv__layout" href="<?php echo esc_url( Blueprint_Registry_Routes::gallery_url( array( 'bp_layout' => 'table' ) ) ); ?>" aria-pressed="<?php echo 'table' === $layout ? 'true' : 'false'; ?>" title="<?php esc_attr_e( 'Table', 'blueprint-registry' ); ?>">
					<?php echo Blueprint_Registry_Ui::icon( 'list' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<span class="bp-visually-hidden"><?php esc_html_e( 'Table', 'blueprint-registry' ); ?></span>
				</a>
			</div>
		</form>

		<?php if ( $search || $category ) : ?>
			<div class="bpv__filters">
				<?php if ( $search ) : ?>
					<span class="bpv__filter">
						<?php echo esc_html( sprintf( __( 'Search: %s', 'blueprint-registry' ), $search ) ); ?>
						<a class="bpv__filter-remove" href="<?php echo esc_url( Blueprint_Registry_Routes::gallery_url( array( 'bp_q' => '' ) ) ); ?>" aria-label="<?php esc_attr_e( 'Clear search', 'blueprint-registry' ); ?>"><?php echo Blueprint_Registry_Ui::icon( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
					</span>
				<?php endif; ?>
				<?php if ( $category ) : ?>
					<?php $term = get_term_by( 'slug', $category, 'blueprint_category' ); ?>
					<span class="bpv__filter">
						<?php echo esc_html( $term ? $term->name : $category ); ?>
						<a class="bpv__filter-remove" href="<?php echo esc_url( Blueprint_Registry_Routes::gallery_url( array( 'bp_category' => '' ) ) ); ?>" aria-label="<?php esc_attr_e( 'Clear category', 'blueprint-registry' ); ?>"><?php echo Blueprint_Registry_Ui::icon( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
					</span>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ( ! have_posts() ) : ?>
			<?php
			Blueprint_Registry_Ui::empty_state(
				'search',
				__( 'No Blueprints match', 'blueprint-registry' ),
				__( 'Try a different search term, or clear the filters to see everything in the gallery.', 'blueprint-registry' ),
				sprintf( '<a class="bp-btn" href="%s">%s</a>', esc_url( get_post_type_archive_link( 'blueprint' ) ), esc_html__( 'Clear filters', 'blueprint-registry' ) )
			);
			?>
		<?php elseif ( 'table' === $layout ) : ?>
			<div class="bpv__table-wrap">
				<table class="bpv__table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Blueprint', 'blueprint-registry' ); ?></th>
							<th><?php esc_html_e( 'Categories', 'blueprint-registry' ); ?></th>
							<th><?php esc_html_e( 'Updated', 'blueprint-registry' ); ?></th>
							<th><span class="bp-visually-hidden"><?php esc_html_e( 'Actions', 'blueprint-registry' ); ?></span></th>
						</tr>
					</thead>
					<tbody>
						<?php while ( have_posts() ) : the_post(); ?>
							<?php $card = bp_gallery_card_data( get_the_ID() ); ?>
							<tr>
								<td>
									<div class="bpv__primary">
										<span class="bpv__thumb">
											<?php if ( has_post_thumbnail() ) : ?>
												<?php the_post_thumbnail( 'medium', array( 'loading' => 'lazy', 'alt' => '' ) ); ?>
											<?php else : ?>
												<span class="bpv__thumb-letter"><?php echo esc_html( $card['initial'] ); ?></span>
											<?php endif; ?>
										</span>
										<span class="bpv__primary-text">
											<a class="bpv__title" href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
											<span class="bpv__subtitle"><?php echo esc_html( $card['excerpt'] ); ?></span>
										</span>
									</div>
								</td>
								<td><?php echo esc_html( $card['categories'] ? implode( ', ', $card['categories'] ) : '—' ); ?></td>
								<td class="bpv__cell--tight"><?php echo esc_html( get_the_modified_date( get_option( 'date_format' ) ) ); ?></td>
								<td>
									<span class="bp-row-actions">
										<a class="bp-btn bp-btn--sm" href="<?php the_permalink(); ?>"><?php esc_html_e( 'Details', 'blueprint-registry' ); ?></a>
										<a class="bp-btn bp-btn--sm bp-btn--primary" href="<?php echo esc_url( $card['run_url'] ); ?>" rel="noreferrer noopener">
											<?php echo Blueprint_Registry_Ui::icon( 'play' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
											<?php esc_html_e( 'Run', 'blueprint-registry' ); ?>
										</a>
									</span>
								</td>
							</tr>
						<?php endwhile; ?>
					</tbody>
				</table>
			</div>
		<?php else : ?>
			<div class="bpv__grid">
				<?php while ( have_posts() ) : the_post(); ?>
					<?php $card = bp_gallery_card_data( get_the_ID() ); ?>
					<article class="bpv__card">
						<span class="bpv__card-media">
							<?php if ( has_post_thumbnail() ) : ?>
								<?php the_post_thumbnail( 'large', array( 'loading' => 'lazy', 'alt' => '' ) ); ?>
							<?php else : ?>
								<span class="bpv__card-media-empty"><?php echo esc_html( $card['initial'] ); ?></span>
							<?php endif; ?>
						</span>
						<div class="bpv__card-body">
							<h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
							<?php if ( $card['excerpt'] ) : ?><p class="bpv__card-excerpt"><?php echo esc_html( $card['excerpt'] ); ?></p><?php endif; ?>
							<div class="bpv__card-footer">
								<span class="bpv__card-meta">
									<?php echo get_avatar( get_the_author_meta( 'ID' ), 36, '', '', array( 'class' => 'bpv__card-avatar' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<span><?php echo esc_html( get_the_author() ); ?></span>
								</span>
								<a class="bp-btn bp-btn--sm" href="<?php the_permalink(); ?>"><?php esc_html_e( 'Details', 'blueprint-registry' ); ?><span class="bp-visually-hidden">: <?php the_title(); ?></span></a>
								<a class="bp-btn bp-btn--sm bp-btn--primary" href="<?php echo esc_url( $card['run_url'] ); ?>" rel="noreferrer noopener">
									<?php echo Blueprint_Registry_Ui::icon( 'play' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
									<?php esc_html_e( 'Run', 'blueprint-registry' ); ?><span class="bp-visually-hidden">: <?php the_title(); ?></span>
								</a>
							</div>
						</div>
					</article>
				<?php endwhile; ?>
			</div>
		<?php endif; ?>

		<?php if ( have_posts() ) : ?>
			<div class="bpv__footer">
				<span><?php echo esc_html( sprintf( _n( '%d Blueprint', '%d Blueprints', $total, 'blueprint-registry' ), $total ) ); ?></span>
				<span class="bp-pagination">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'mid_size'  => 1,
								'prev_text' => esc_html__( 'Previous', 'blueprint-registry' ),
								'next_text' => esc_html__( 'Next', 'blueprint-registry' ),
							)
						)
					);
					?>
				</span>
			</div>
		<?php endif; ?>
	</div>
</main>
<?php
Blueprint_Registry_Ui::close();

/**
 * Collects the fields the gallery shows for one Blueprint.
 */
function bp_gallery_card_data( $blueprint_id ) {
	$release_id = (int) get_post_meta( $blueprint_id, '_bp_current_release_id', true );
	$release    = (int) get_post_meta( $release_id, '_bp_release_number', true );
	$categories = wp_get_post_terms( $blueprint_id, 'blueprint_category', array( 'fields' => 'names' ) );
	$bundle_url = Blueprint_Registry_Routes::release_url( $blueprint_id, $release, 'bundle' );

	return array(
		'categories' => is_wp_error( $categories ) ? array() : $categories,
		'excerpt'    => wp_trim_words( wp_strip_all_tags( get_post_field( 'post_content', $blueprint_id ) ), 20 ),
		'initial'    => strtoupper( substr( get_the_title( $blueprint_id ), 0, 1 ) ),
		'run_url'    => 'https://playground.wordpress.net/?blueprint-url=' . rawurlencode( $bundle_url ),
	);
}
