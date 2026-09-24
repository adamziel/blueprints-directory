<?php
/**
 * Reads one file out of an immutable release, alongside the rest of the bundle.
 *
 * @package BlueprintRegistry
 */

defined( 'ABSPATH' ) || exit;

$slug    = sanitize_title_for_query( get_query_var( 'bp_blueprint_slug' ) );
$number  = (int) get_query_var( 'bp_release_number' );
$path    = rawurldecode( (string) get_query_var( 'bp_release_file' ) );
$release = Blueprint_Registry_Routes::find_release( $slug, $number );

if ( ! $release ) {
	wp_die( esc_html__( 'Blueprint revision not found.', 'blueprint-registry' ), esc_html__( 'Not found', 'blueprint-registry' ), array( 'response' => 404 ) );
}

$blueprint = get_post( $release->post_parent );
$contents  = Blueprint_Registry_Bundles::release_file_contents( $release->ID, $path );
$is_text   = ! is_wp_error( $contents ) && ! str_contains( $contents, "\0" );
$file_url  = Blueprint_Registry_Routes::release_file_url( $blueprint->ID, $number, $path );
$siblings  = Blueprint_Registry_Bundles::release_file_entries( $release->ID );
$siblings  = is_wp_error( $siblings ) ? array() : $siblings;
$lines     = $is_text ? explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", $contents ) ) : array();
$is_json   = 'json' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
$size      = 0;

foreach ( $siblings as $sibling ) {
	if ( $sibling['path'] === $path ) {
		$size = (int) $sibling['size'];
	}
}

Blueprint_Registry_Ui::open( 'bp-screen-file' );
?>
<main class="bp-app__main" id="main-content">
	<a class="bp-back" href="<?php echo esc_url( Blueprint_Registry_Routes::release_detail_url( $blueprint->ID, $number ) ); ?>">
		<?php echo Blueprint_Registry_Ui::icon( 'arrow-left' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php echo esc_html( get_the_title( $blueprint ) ); ?>
	</a>

	<div class="bp-page-head">
		<div class="bp-page-head__text">
			<h1><?php echo esc_html( basename( $path ) ); ?></h1>
			<div class="bp-page-head__meta">
				<span class="bp-chip bp-chip--plain"><?php echo esc_html( sprintf( __( 'Revision %d', 'blueprint-registry' ), $number ) ); ?></span>
				<?php if ( basename( $path ) !== $path ) : ?><span class="bp-chip bp-chip--plain"><?php echo esc_html( $path ); ?></span><?php endif; ?>
				<?php if ( $size ) : ?><span class="bp-chip bp-chip--plain"><?php echo esc_html( size_format( $size ) ); ?></span><?php endif; ?>
				<?php if ( $is_text ) : ?><span class="bp-chip bp-chip--plain"><?php echo esc_html( sprintf( _n( '%d line', '%d lines', count( $lines ), 'blueprint-registry' ), count( $lines ) ) ); ?></span><?php endif; ?>
			</div>
		</div>
		<div class="bp-page-head__actions">
			<a class="bp-btn" href="<?php echo esc_url( add_query_arg( 'download', '1', $file_url ) ); ?>">
				<?php echo Blueprint_Registry_Ui::icon( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php esc_html_e( 'Download', 'blueprint-registry' ); ?>
			</a>
		</div>
	</div>

	<div class="bp-viewer">
		<nav class="bp-panel" aria-label="<?php esc_attr_e( 'Files in this revision', 'blueprint-registry' ); ?>">
			<div class="bp-panel__head"><h2><?php esc_html_e( 'Bundle', 'blueprint-registry' ); ?></h2></div>
			<ul class="bp-filetree">
				<?php foreach ( $siblings as $sibling ) : ?>
					<li>
						<a href="<?php echo esc_url( Blueprint_Registry_Routes::release_file_url( $blueprint->ID, $number, $sibling['path'] ) ); ?>"<?php echo $sibling['path'] === $path ? ' aria-current="page"' : ''; ?>>
							<?php echo Blueprint_Registry_Ui::icon( Blueprint_Registry_Ui::file_icon( $sibling['path'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<?php echo esc_html( $sibling['path'] ); ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</nav>

		<section class="bp-panel">
			<div class="bp-panel__head">
				<h2><?php echo esc_html( $path ); ?></h2>
				<?php if ( $is_text ) : ?>
					<button type="button" class="bp-btn bp-btn--sm bp-btn--ghost" data-bp-copy="<?php echo esc_attr( $contents ); ?>">
						<?php echo Blueprint_Registry_Ui::icon( 'copy' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span data-copied="<?php esc_attr_e( 'Copied', 'blueprint-registry' ); ?>"><?php esc_html_e( 'Copy', 'blueprint-registry' ); ?></span>
					</button>
				<?php endif; ?>
			</div>
			<?php if ( is_wp_error( $contents ) ) : ?>
				<div class="bp-binary">
					<?php echo Blueprint_Registry_Ui::icon( 'alert' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<p><?php echo esc_html( $contents->get_error_message() ); ?></p>
				</div>
			<?php elseif ( ! $is_text ) : ?>
				<div class="bp-binary">
					<?php echo Blueprint_Registry_Ui::icon( 'archive' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<p><?php esc_html_e( 'This is a binary file and cannot be shown here.', 'blueprint-registry' ); ?></p>
					<a class="bp-btn bp-btn--sm" href="<?php echo esc_url( add_query_arg( 'download', '1', $file_url ) ); ?>"><?php esc_html_e( 'Download to inspect it', 'blueprint-registry' ); ?></a>
				</div>
			<?php else : ?>
				<div class="bp-code">
					<table>
						<tbody>
							<?php foreach ( $lines as $index => $line ) : ?>
								<tr>
									<td class="bp-code__ln"><?php echo esc_html( $index + 1 ); ?></td>
									<td class="bp-code__line"><?php
										if ( '' === $line ) {
											echo ' ';
										} elseif ( $is_json ) {
											echo Blueprint_Registry_Ui::highlight_json( $line ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
										} else {
											echo esc_html( $line );
										}
									?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</section>
	</div>
</main>
<?php
Blueprint_Registry_Ui::close();
