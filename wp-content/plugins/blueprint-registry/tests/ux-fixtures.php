<?php
/** Local browser-test records. Never changes existing users or Blueprints. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'localhost' !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
	throw new RuntimeException( 'UX fixtures are only for local WP-CLI tests.' );
}

$mode = $args[0] ?? '';
if ( 'cleanup' === $mode ) {
	$users = get_option( 'bp_ux_test_users', array() );
	foreach ( $users as $id ) {
		$posts = get_posts( array( 'post_type' => array( 'blueprint', 'blueprint_change', 'blueprint_submission', 'blueprint_release', 'attachment', 'revision' ), 'post_status' => 'any', 'author' => $id, 'numberposts' => -1 ) );
		foreach ( $posts as $post ) {
			if ( 'attachment' === $post->post_type ) {
				wp_delete_attachment( $post->ID, true );
			} else {
				wp_delete_post( $post->ID, true );
			}
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $id );
	}
	delete_option( 'bp_ux_test_users' );
	echo "Cleaned up UX test records.\n";
	return;
}
if ( 'setup' !== $mode || get_option( 'bp_ux_test_users' ) ) {
	throw new RuntimeException( 'Run cleanup before setting up UX fixtures.' );
}

$fixture = array();
$ids = array();
foreach ( array( 'contributor' => 'subscriber', 'reviewer' => 'administrator' ) as $name => $role ) {
	$login = 'ux-' . $name . '-' . wp_generate_password( 8, false );
	$password = wp_generate_password( 24, false );
	$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => $password, 'user_email' => $login . '@example.test', 'role' => $role, 'display_name' => 'UX ' . ucfirst( $name ) ) );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
	$ids[] = $id;
	update_option( 'bp_ux_test_users', $ids, false );
	$fixture[ $name ] = array( 'id' => $id, 'login' => $login, 'password' => $password );
}
wp_set_current_user( $fixture['contributor']['id'] );
$change = Blueprint_Registry_Workflow::create_change( array( 'title' => 'UX Coffee Shop', 'author_id' => get_current_user_id() ) );
Blueprint_Registry_Workflow::set_source( $change, wp_json_encode( array( '$schema' => Blueprint_Registry_Validator::SCHEMA_URL, 'meta' => array( 'title' => 'UX Coffee Shop', 'description' => 'A small coffee shop for trying the contribution flow.' ), 'login' => true ), JSON_PRETTY_PRINT ) );
Blueprint_Registry_Bundles::add_change_file( $change, "Coffee menu\nEspresso\n", 'menu.txt' );
$result = Blueprint_Registry_Workflow::submit( $change );
if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); }
wp_set_current_user( $fixture['reviewer']['id'] );
$result = Blueprint_Registry_Workflow::review( $change, 'approved', get_current_user_id(), 'Ready for the gallery.' );
if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_message() ); }
$fixture['blueprint'] = (int) get_post_meta( $change, '_bp_target_blueprint_id', true );
$fixture['url'] = get_permalink( $fixture['blueprint'] );
echo wp_json_encode( $fixture );
