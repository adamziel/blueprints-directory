<?php

defined( 'ABSPATH' ) || exit;

final class Blueprint_Registry_Routes {
	public function __construct() {
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		add_filter( 'redirect_canonical', array( $this, 'disable_artifact_canonical_redirects' ), 10, 2 );
		add_action( 'template_redirect', array( $this, 'serve_artifact' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_gallery_assets' ) );
		add_action( 'pre_get_posts', array( $this, 'order_gallery' ) );
		add_filter( 'template_include', array( $this, 'gallery_template' ) );
	}

	public function enqueue_gallery_assets() {
		if ( is_post_type_archive( 'blueprint' ) || is_singular( 'blueprint' ) || get_query_var( 'bp_release_file' ) ) {
			wp_enqueue_style( 'blueprint-registry-gallery', BLUEPRINT_REGISTRY_URL . 'assets/gallery.css', array(), BLUEPRINT_REGISTRY_VERSION );
		}
	}

	public function gallery_template( $template ) {
		if ( is_post_type_archive( 'blueprint' ) ) {
			return BLUEPRINT_REGISTRY_DIR . 'templates/archive-blueprint.php';
		}
		if ( get_query_var( 'bp_release_file' ) ) {
			return BLUEPRINT_REGISTRY_DIR . 'templates/blueprint-file.php';
		}
		if ( is_singular( 'blueprint' ) ) {
			return BLUEPRINT_REGISTRY_DIR . 'templates/single-blueprint.php';
		}

		return $template;
	}

	public function order_gallery( $query ) {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_post_type_archive( 'blueprint' ) ) {
			return;
		}

		$query->set( 'meta_key', '_bp_gallery_order' );
		$query->set( 'orderby', 'meta_value_num' );
		$query->set( 'order', 'ASC' );
	}

	public function add_rewrite_rules() {
		self::register_rewrite_rules();
	}

	public static function register_rewrite_rules() {
		add_rewrite_rule( '^blueprints/([^/]+)/releases/([0-9]+)/blueprint\\.json$', 'index.php?bp_blueprint_slug=$matches[1]&bp_release_number=$matches[2]&bp_artifact=json', 'top' );
		add_rewrite_rule( '^blueprints/([^/]+)/releases/([0-9]+)/bundle\\.zip$', 'index.php?bp_blueprint_slug=$matches[1]&bp_release_number=$matches[2]&bp_artifact=bundle', 'top' );
		add_rewrite_rule( '^blueprints/([^/]+)/releases/([0-9]+)/files/(.+)/?$', 'index.php?bp_blueprint_slug=$matches[1]&bp_release_number=$matches[2]&bp_release_file=$matches[3]', 'top' );
		add_rewrite_rule( '^blueprints/drafts/([0-9]+)/preview\\.zip$', 'index.php?bp_preview_change=$matches[1]', 'top' );
	}

	public function add_query_vars( $vars ) {
		$vars[] = 'bp_blueprint_slug';
		$vars[] = 'bp_release_number';
		$vars[] = 'bp_artifact';
		$vars[] = 'bp_release_file';
		$vars[] = 'bp_preview_change';
		return $vars;
	}

	public function disable_artifact_canonical_redirects( $redirect_url, $requested_url ) {
		if ( get_query_var( 'bp_artifact' ) || get_query_var( 'bp_preview_change' ) || get_query_var( 'bp_release_file' ) ) {
			return false;
		}

		return $redirect_url;
	}

	public function serve_artifact() {
		$preview_change = (int) get_query_var( 'bp_preview_change' );
		if ( $preview_change ) {
			$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
			$path  = Blueprint_Registry_Bundles::get_preview_path( $preview_change, $token );
			if ( is_wp_error( $path ) ) {
				wp_die( esc_html( $path->get_error_message() ), esc_html__( 'Preview unavailable', 'blueprint-registry' ), array( 'response' => 404 ) );
			}
			$this->send_file( $path, 'application/zip', 'private-blueprint-preview.zip', true );
		}

		$release_file = get_query_var( 'bp_release_file' );
		if ( $release_file && isset( $_GET['download'] ) ) {
			$release = self::find_release( sanitize_title_for_query( get_query_var( 'bp_blueprint_slug' ) ), (int) get_query_var( 'bp_release_number' ) );
			if ( ! $release ) {
				wp_die( esc_html__( 'Blueprint release not found.', 'blueprint-registry' ), esc_html__( 'Not found', 'blueprint-registry' ), array( 'response' => 404 ) );
			}
			Blueprint_Registry_Bundles::stream_release_file( $release->ID, rawurldecode( $release_file ) );
		}

		$artifact = get_query_var( 'bp_artifact' );
		if ( ! $artifact ) {
			return;
		}

		$release = self::find_release( sanitize_title_for_query( get_query_var( 'bp_blueprint_slug' ) ), (int) get_query_var( 'bp_release_number' ) );
		if ( ! $release ) {
			wp_die( esc_html__( 'Blueprint release not found.', 'blueprint-registry' ), esc_html__( 'Not found', 'blueprint-registry' ), array( 'response' => 404 ) );
		}

		if ( 'json' === $artifact ) {
			nocache_headers();
			header( 'Content-Type: application/json; charset=utf-8' );
			if ( isset( $_GET['download'] ) ) {
				header( 'Content-Disposition: attachment; filename="blueprint.json"' );
			}
			header( 'Access-Control-Allow-Origin: *' );
			header( 'Cache-Control: public, max-age=31536000, immutable' );
			echo get_post_field( 'post_content', $release->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}

		$bundle_id = (int) get_post_meta( $release->ID, '_bp_bundle_attachment_id', true );
		$path      = get_attached_file( $bundle_id );
		if ( ! is_readable( $path ) ) {
			wp_die( esc_html__( 'Blueprint bundle is unavailable.', 'blueprint-registry' ), esc_html__( 'Not found', 'blueprint-registry' ), array( 'response' => 404 ) );
		}
		$this->send_file( $path, 'application/zip', sanitize_file_name( $release->post_name ?: 'blueprint-release' ) . '.zip' );
	}

	public function register_rest_routes() {
		register_rest_route(
			'blueprints/v1',
			'/blueprints',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'rest_blueprints' ),
			)
		);
		register_rest_route(
			'blueprints/v1',
			'/blueprints/(?P<slug>[a-z0-9-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'rest_blueprint' ),
			)
		);
	}

	public function rest_blueprints() {
		$posts = get_posts(
			array(
				'post_type'      => 'blueprint',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'meta_key'       => '_bp_gallery_order',
				'orderby'        => 'meta_value_num',
				'order'          => 'ASC',
			)
		);
		return array_map( array( $this, 'blueprint_response' ), $posts );
	}

	public function rest_blueprint( WP_REST_Request $request ) {
		$blueprint = get_page_by_path( $request['slug'], OBJECT, 'blueprint' );
		if ( ! $blueprint || 'publish' !== $blueprint->post_status ) {
			return new WP_Error( 'blueprint_not_found', __( 'Blueprint not found.', 'blueprint-registry' ), array( 'status' => 404 ) );
		}
		return $this->blueprint_response( $blueprint, true );
	}

	public static function release_url( $blueprint_id, $release_number, $artifact ) {
		$slug = get_post_field( 'post_name', $blueprint_id );
		$file = 'json' === $artifact ? 'blueprint.json' : 'bundle.zip';
		return home_url( sprintf( '/blueprints/%s/releases/%d/%s', $slug, $release_number, $file ) );
	}

	public static function release_detail_url( $blueprint_id, $release_number ) {
		return add_query_arg( 'release', (int) $release_number, get_permalink( $blueprint_id ) );
	}

	public static function release_file_url( $blueprint_id, $release_number, $path ) {
		$slug = get_post_field( 'post_name', $blueprint_id );
		$path = str_replace( '%2F', '/', rawurlencode( ltrim( (string) $path, '/' ) ) );
		return home_url( sprintf( '/blueprints/%s/releases/%d/files/%s', $slug, (int) $release_number, $path ) );
	}

	public static function releases( $blueprint_id, $order = 'DESC' ) {
		return get_posts(
			array(
				'post_type'      => 'blueprint_release',
				'post_parent'    => (int) $blueprint_id,
				'post_status'    => 'private',
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'ASC' === $order ? 'ASC' : 'DESC',
			)
		);
	}

	public static function find_release( $slug, $number ) {
		$blueprint = get_page_by_path( $slug, OBJECT, 'blueprint' );
		if ( ! $blueprint || 'publish' !== $blueprint->post_status ) {
			return null;
		}
		$releases = get_posts(
			array(
				'post_type'      => 'blueprint_release',
				'post_parent'    => $blueprint->ID,
				'post_status'    => 'private',
				'posts_per_page' => 1,
				'meta_key'       => '_bp_release_number',
				'meta_value'     => $number,
			)
		);
		return $releases ? $releases[0] : null;
	}

	private function blueprint_response( $blueprint, $include_releases = false ) {
		$release_id = (int) get_post_meta( $blueprint->ID, '_bp_current_release_id', true );
		$number     = (int) get_post_meta( $release_id, '_bp_release_number', true );
		$response   = array(
			'id'               => $blueprint->ID,
			'slug'             => $blueprint->post_name,
			'title'            => get_the_title( $blueprint ),
			'description'      => $blueprint->post_content,
			'url'              => get_permalink( $blueprint ),
			'current_release'  => $number,
			'blueprint_url'    => $release_id ? self::release_url( $blueprint->ID, $number, 'json' ) : null,
			'bundle_url'       => $release_id ? self::release_url( $blueprint->ID, $number, 'bundle' ) : null,
			'categories'       => wp_get_post_terms( $blueprint->ID, 'blueprint_category', array( 'fields' => 'names' ) ),
		);

		if ( $include_releases ) {
			$response['releases'] = array_map(
				static function ( $release ) use ( $blueprint ) {
					$number = (int) get_post_meta( $release->ID, '_bp_release_number', true );
					return array(
						'number'       => $number,
						'checksum'     => get_post_meta( $release->ID, '_bp_checksum', true ),
						'blueprint_url' => self::release_url( $blueprint->ID, $number, 'json' ),
						'bundle_url'    => self::release_url( $blueprint->ID, $number, 'bundle' ),
						'url'          => self::release_detail_url( $blueprint->ID, $number ),
						'files'        => Blueprint_Registry_Bundles::release_file_entries( $release->ID ),
					);
				},
				self::releases( $blueprint->ID, 'ASC' )
			);
		}

		return $response;
	}

	private function send_file( $path, $mime_type, $filename, $private = false ) {
		nocache_headers();
		header( 'Content-Type: ' . $mime_type );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Access-Control-Allow-Origin: *' );
		header( $private ? 'Cache-Control: private, no-store' : 'Cache-Control: public, max-age=31536000, immutable' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile
		exit;
	}
}
