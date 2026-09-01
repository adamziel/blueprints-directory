<?php

defined( 'ABSPATH' ) || exit;

final class Blueprint_Registry_Capabilities {
	public static function register() {
		$administrator = get_role( 'administrator' );
		$change_caps   = array(
			'edit_blueprint_changes',
			'publish_blueprint_changes',
			'delete_blueprint_changes',
		);
		$review_caps   = array(
			'edit_blueprint_changes',
			'edit_others_blueprint_changes',
			'publish_blueprint_changes',
			'read_private_blueprint_changes',
			'delete_blueprint_changes',
			'delete_others_blueprint_changes',
			'edit_blueprint_releases',
			'edit_others_blueprint_releases',
			'read_private_blueprint_releases',
			'review_blueprints',
			'publish_blueprint_releases',
		);

		foreach ( array_keys( wp_roles()->roles ) as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			if ( ! $role->has_cap( 'read' ) ) {
				continue;
			}
			foreach ( $change_caps as $capability ) {
				$role->add_cap( $capability );
			}
		}

		if ( $administrator ) {
			foreach ( $review_caps as $capability ) {
				$administrator->add_cap( $capability );
			}
		}
	}

	public static function can_contribute() {
		return is_user_logged_in() && current_user_can( 'read' );
	}

	public static function can_review() {
		return current_user_can( 'review_blueprints' );
	}
}
