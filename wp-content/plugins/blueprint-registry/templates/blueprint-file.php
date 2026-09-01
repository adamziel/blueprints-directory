<?php
/**
 * Public browser for one immutable bundled resource.
 *
 * @package BlueprintRegistry
 */

defined( 'ABSPATH' ) || exit;

$slug    = sanitize_title_for_query( get_query_var( 'bp_blueprint_slug' ) );
$number  = (int) get_query_var( 'bp_release_number' );
$path    = rawurldecode( (string) get_query_var( 'bp_release_file' ) );
$release = Blueprint_Registry_Routes::find_release( $slug, $number );
if ( ! $release ) {
	wp_die( esc_html__( 'Blueprint release not found.', 'blueprint-registry' ), esc_html__( 'Not found', 'blueprint-registry' ), array( 'response' => 404 ) );
}
$blueprint = get_post( $release->post_parent );
$contents  = Blueprint_Registry_Bundles::release_file_contents( $release->ID, $path );
$is_text   = ! is_wp_error( $contents ) && ! str_contains( $contents, "\0" );
$file_url  = Blueprint_Registry_Routes::release_file_url( $blueprint->ID, $number, $path );

get_header();
?>
<main class="bp-blueprint bp-blueprint--file" id="main-content">
	<header class="bp-blueprint__header">
		<p><a href="<?php echo esc_url( Blueprint_Registry_Routes::release_detail_url( $blueprint->ID, $number ) ); ?>">&larr; <?php echo esc_html( sprintf( __( 'Back to release %d', 'blueprint-registry' ), $number ) ); ?></a></p>
		<h1><code><?php echo esc_html( $path ); ?></code></h1>
		<p class="bp-blueprint__byline"><?php echo esc_html( get_the_title( $blueprint ) ); ?></p>
	</header>
	<section class="bp-blueprint__actions">
		<a class="bp-blueprint__button" href="<?php echo esc_url( add_query_arg( 'download', '1', $file_url ) ); ?>"><?php esc_html_e( 'Download file', 'blueprint-registry' ); ?></a>
	</section>
	<section class="bp-blueprint__panel bp-blueprint__file-content">
		<?php if ( is_wp_error( $contents ) ) : ?>
			<p><?php echo esc_html( $contents->get_error_message() ); ?></p>
		<?php elseif ( ! $is_text ) : ?>
			<p><?php esc_html_e( 'This is a binary file. Download it to inspect it.', 'blueprint-registry' ); ?></p>
		<?php else : ?>
			<pre><?php echo esc_html( $contents ); ?></pre>
		<?php endif; ?>
	</section>
</main>
<?php
get_footer();
