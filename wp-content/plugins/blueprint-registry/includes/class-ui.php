<?php
/**
 * Shared presentation helpers: the app shell, the icon set, and the small
 * pieces of chrome that every Blueprint Registry screen repeats.
 *
 * @package BlueprintRegistry
 */

defined( 'ABSPATH' ) || exit;

final class Blueprint_Registry_Ui {
	/**
	 * Inline 16x16 icons on a 24-unit grid, drawn with currentColor so they
	 * inherit the surrounding text colour in both light and dark palettes.
	 */
	private static function paths() {
		return array(
			'logo'      => '<path d="M4 7.5 12 3l8 4.5v9L12 21l-8-4.5z"/><path d="M4 7.5 12 12l8-4.5M12 12v9"/>',
			'search'    => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
			'grid'      => '<rect x="3.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.5"/>',
			'list'      => '<path d="M4 6h16M4 12h16M4 18h16"/>',
			'close'     => '<path d="M6 6l12 12M18 6L6 18"/>',
			'chevron'   => '<path d="m6 9 6 6 6-6"/>',
			'arrow-left'=> '<path d="M19 12H5m6-7-7 7 7 7"/>',
			'play'      => '<path d="M7 4.5v15l12-7.5z"/>',
			'download'  => '<path d="M12 3v12m0 0 4.5-4.5M12 15l-4.5-4.5"/><path d="M4 17v2.5A1.5 1.5 0 0 0 5.5 21h13a1.5 1.5 0 0 0 1.5-1.5V17"/>',
			'copy'      => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h9"/>',
			'file'      => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
			'code'      => '<path d="m9 8-5 4 5 4M15 8l5 4-5 4"/>',
			'archive'   => '<rect x="3" y="4" width="18" height="5" rx="1.5"/><path d="M5 9v10a1.5 1.5 0 0 0 1.5 1.5h11A1.5 1.5 0 0 0 19 19V9M10 13h4"/>',
			'image'     => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9.5" r="1.5"/><path d="m5 18 5-5 4 4 2-2 3 3"/>',
			'fork'      => '<circle cx="6.5" cy="5" r="2.5"/><circle cx="17.5" cy="5" r="2.5"/><circle cx="12" cy="19" r="2.5"/><path d="M6.5 7.5v2A2.5 2.5 0 0 0 9 12h6a2.5 2.5 0 0 0 2.5-2.5v-2M12 12v4.5"/>',
			'edit'      => '<path d="M4 20h4L20 8l-4-4L4 16z"/>',
			'plus'      => '<path d="M12 5v14M5 12h14"/>',
			'check'     => '<path d="m4.5 12.5 5 5 10-11"/>',
			'alert'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5M12 16.2v.3"/>',
			'info'      => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.8v.3"/>',
			'clock'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
			'tag'       => '<path d="M3.5 11V4.5a1 1 0 0 1 1-1H11l9.5 9.5-7.5 7.5z"/><circle cx="7.75" cy="7.75" r="1.25"/>',
			'inbox'     => '<path d="M3.5 13.5h4l1.5 3h6l1.5-3h4"/><path d="M5.6 4.5h12.8l2.1 9v5a1.5 1.5 0 0 1-1.5 1.5H5a1.5 1.5 0 0 1-1.5-1.5v-5z"/>',
			'diff'      => '<path d="M7 3v18M7 8h10M12 3v10M17 16v5M14.5 18.5h5"/>',
			'sparkle'   => '<path d="M12 3.5 13.8 9l5.7 1.8-5.7 1.8L12 18l-1.8-5.4L4.5 10.8 10.2 9z"/>',
			'plugin'    => '<path d="M9 3v3H6.5A1.5 1.5 0 0 0 5 7.5V10h-.5a2.5 2.5 0 0 0 0 5H5v2.5A1.5 1.5 0 0 0 6.5 19H9v-.5a2.5 2.5 0 0 1 5 0v.5h3.5a1.5 1.5 0 0 0 1.5-1.5V14"/><path d="M19 10V7.5A1.5 1.5 0 0 0 17.5 6H15v-.5a2.5 2.5 0 0 0-5 0V6"/>',
			'theme'     => '<circle cx="12" cy="12" r="9"/><circle cx="9" cy="9.5" r="1.2"/><circle cx="14.8" cy="9.5" r="1.2"/><path d="M12 21a2.6 2.6 0 0 1 0-5.2 2.3 2.3 0 0 0 0-4.6"/>',
			'content'   => '<path d="M5 4.5h14v15H5z"/><path d="M8 9h8M8 12.5h8M8 16h4"/>',
			'settings'  => '<circle cx="12" cy="12" r="3"/><path d="M12 2.5v2.2M12 19.3v2.2M4.9 4.9l1.6 1.6M17.5 17.5l1.6 1.6M2.5 12h2.2M19.3 12h2.2M4.9 19.1l1.6-1.6M17.5 6.5l1.6-1.6"/>',
			'external'  => '<path d="M14 4h6v6"/><path d="m20 4-9 9"/><path d="M18 14v5a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 4 19V8a1.5 1.5 0 0 1 1.5-1.5H10"/>',
			'trash'     => '<path d="M4.5 6.5h15M9.5 6.5V4.8A1.3 1.3 0 0 1 10.8 3.5h2.4a1.3 1.3 0 0 1 1.3 1.3v1.7"/><path d="M6.5 6.5 7.4 20a1.5 1.5 0 0 0 1.5 1.4h6.2a1.5 1.5 0 0 0 1.5-1.4l.9-13.5"/>',
			'send'      => '<path d="M21 3 10.5 13.5M21 3l-6.8 18-3.7-7.5L3 9.8z"/>',
		);
	}

	public static function icon( $name, $classes = '' ) {
		$paths = self::paths();
		if ( ! isset( $paths[ $name ] ) ) {
			return '';
		}

		$fill = 'play' === $name || 'sparkle' === $name ? 'currentColor' : 'none';

		return sprintf(
			'<svg class="%1$s" viewBox="0 0 24 24" fill="%2$s" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%3$s</svg>',
			esc_attr( $classes ),
			esc_attr( $fill ),
			$paths[ $name ]
		);
	}

	/**
	 * Opens the plugin's own document shell.
	 *
	 * The registry screens are an application rather than theme content, so
	 * they render their own chrome instead of inheriting the theme's header —
	 * the admin bar and every enqueued asset still load through wp_head().
	 */
	public static function open( $body_class = '' ) {
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'bp-app ' . $body_class ); ?>>
	<a class="bp-skip-link" href="#main-content"><?php esc_html_e( 'Skip to content', 'blueprint-registry' ); ?></a>
		<?php self::header(); ?>
		<?php
	}

	public static function close() {
		?>
	<footer class="bp-app__footer">
		<?php
		printf(
			/* translators: %s: link to the WordPress Playground Blueprint documentation. */
			esc_html__( 'Blueprints are portable WordPress setups. %s', 'blueprint-registry' ),
			'<a href="https://wordpress.github.io/wordpress-playground/blueprints/" rel="noreferrer noopener">' . esc_html__( 'Read the Blueprint documentation', 'blueprint-registry' ) . '</a>'
		);
		?>
	</footer>
	<?php wp_footer(); ?>
</body>
</html>
		<?php
	}

	private static function header() {
		$gallery   = get_post_type_archive_link( 'blueprint' );
		$dashboard = Blueprint_Registry_Frontend::dashboard_url();
		$current   = self::current_section();
		?>
	<header class="bp-app__header">
		<div class="bp-app__header-inner">
			<a class="bp-app__brand" href="<?php echo esc_url( $gallery ); ?>">
				<span class="bp-app__mark"><?php echo self::icon( 'logo' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<?php esc_html_e( 'Blueprints', 'blueprint-registry' ); ?>
			</a>
			<nav class="bp-app__nav" aria-label="<?php esc_attr_e( 'Blueprint Registry', 'blueprint-registry' ); ?>">
				<a href="<?php echo esc_url( $gallery ); ?>"<?php echo 'gallery' === $current ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'Gallery', 'blueprint-registry' ); ?></a>
				<?php if ( Blueprint_Registry_Capabilities::can_contribute() ) : ?>
					<a href="<?php echo esc_url( $dashboard ); ?>"<?php echo 'workspace' === $current ? ' aria-current="page"' : ''; ?>><?php esc_html_e( 'My work', 'blueprint-registry' ); ?></a>
				<?php endif; ?>
				<?php if ( Blueprint_Registry_Capabilities::can_review() ) : ?>
					<?php $pending = Blueprint_Registry_Workflow::pending_review_count(); ?>
					<a href="<?php echo esc_url( Blueprint_Registry_Admin::review_queue_url() ); ?>">
						<?php esc_html_e( 'Review queue', 'blueprint-registry' ); ?>
						<?php if ( $pending ) : ?><span class="bpv__control-count" style="margin-left:.375rem"><?php echo esc_html( $pending ); ?></span><?php endif; ?>
					</a>
				<?php endif; ?>
			</nav>
			<div class="bp-app__header-end">
				<?php if ( Blueprint_Registry_Capabilities::can_contribute() ) : ?>
					<details class="bp-picker bp-account">
						<summary><?php echo esc_html( wp_get_current_user()->display_name ); ?> <?php echo self::icon( 'chevron' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></summary>
						<div class="bp-picker__menu"><a class="bp-menu__item" href="<?php echo esc_url( wp_logout_url( $gallery ) ); ?>"><?php esc_html_e( 'Sign out', 'blueprint-registry' ); ?></a></div>
					</details>
				<?php else : ?>
					<a class="bp-btn bp-btn--sm" href="<?php echo esc_url( wp_login_url( self::current_url() ) ); ?>"><?php esc_html_e( 'Sign in', 'blueprint-registry' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
	</header>
		<?php
	}

	private static function current_section() {
		if ( get_query_var( 'bp_manage' ) ) {
			return 'workspace';
		}

		return 'gallery';
	}

	private static function current_url() {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/blueprints/';
		return home_url( esc_url_raw( $request_uri ) );
	}

	/**
	 * Renders a workflow status chip.
	 */
	public static function chip( $status, $label ) {
		printf(
			'<span class="bp-chip bp-chip--%1$s">%2$s</span>',
			esc_attr( $status ),
			esc_html( $label )
		);
	}

	/**
	 * Renders a notice built from the redirect query arguments.
	 */
	public static function notice() {
		if ( empty( $_GET['bp_notice'] ) || empty( $_GET['bp_message'] ) ) {
			return;
		}

		$is_error = 'error' === sanitize_key( wp_unslash( $_GET['bp_notice'] ) );
		printf(
			'<div class="bp-notice bp-notice--%1$s" role="status">%2$s<div>%3$s</div></div>',
			$is_error ? 'error' : 'success',
			self::icon( $is_error ? 'alert' : 'check' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			esc_html( sanitize_text_field( wp_unslash( $_GET['bp_message'] ) ) )
		);
	}

	/**
	 * Renders a full-width empty state inside a DataViews container.
	 */
	public static function empty_state( $icon, $title, $description, $action_html = '' ) {
		?>
		<div class="bpv__empty">
			<?php echo self::icon( $icon ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<h3><?php echo esc_html( $title ); ?></h3>
			<p><?php echo esc_html( $description ); ?></p>
			<?php echo $action_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
		<?php
	}

	/**
	 * Colours one line of JSON.
	 *
	 * Server-rendered listings have no editor to lean on, so the line is
	 * tokenised here and each token wrapped for the stylesheet. Text outside a
	 * token is escaped as-is, so the output is safe to echo.
	 */
	public static function highlight_json( $line ) {
		$pattern = '/(?P<key>"(?:\\\\.|[^"\\\\])*"(?=\s*:))|(?P<string>"(?:\\\\.|[^"\\\\])*")|(?P<number>-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)|(?P<literal>\btrue\b|\bfalse\b|\bnull\b)|(?P<punct>[{}\[\],:])/';

		if ( ! preg_match_all( $pattern, (string) $line, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			return esc_html( $line );
		}

		$output = '';
		$cursor = 0;

		foreach ( $matches as $match ) {
			list( $text, $position ) = $match[0];

			$type = 'punct';
			foreach ( array( 'key', 'string', 'number', 'literal', 'punct' ) as $candidate ) {
				if ( isset( $match[ $candidate ] ) && -1 !== $match[ $candidate ][1] ) {
					$type = $candidate;
					break;
				}
			}

			$output .= esc_html( substr( $line, $cursor, $position - $cursor ) );
			$output .= sprintf( '<span class="bp-j-%1$s">%2$s</span>', esc_attr( $type ), esc_html( $text ) );
			$cursor  = $position + strlen( $text );
		}

		return $output . esc_html( substr( $line, $cursor ) );
	}

	/**
	 * Picks a file-type icon from a bundle path.
	 */
	public static function file_icon( $path ) {
		$extension = strtolower( pathinfo( (string) $path, PATHINFO_EXTENSION ) );

		if ( in_array( $extension, array( 'zip', 'tar', 'gz' ), true ) ) {
			return 'archive';
		}
		if ( in_array( $extension, array( 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg' ), true ) ) {
			return 'image';
		}
		if ( in_array( $extension, array( 'json', 'xml', 'php', 'js', 'css', 'sql' ), true ) ) {
			return 'code';
		}

		return 'file';
	}

	/**
	 * Renders a read-only URL with a copy-to-clipboard button.
	 */
	public static function copy_field( $url, $label ) {
		?>
		<div class="bp-copy">
			<input
				class="bp-copy__field"
				type="text"
				value="<?php echo esc_attr( $url ); ?>"
				readonly
				spellcheck="false"
				data-bp-select-all
				aria-label="<?php echo esc_attr( $label ); ?>"
			>
			<button type="button" class="bp-btn bp-btn--sm bp-btn--ghost" data-bp-copy="<?php echo esc_attr( $url ); ?>" aria-label="<?php echo esc_attr( $label ); ?>">
				<?php echo self::icon( 'copy' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<span><?php esc_html_e( 'Copy', 'blueprint-registry' ); ?></span>
			</button>
		</div>
		<?php
	}

	/**
	 * Says what a Blueprint does, in a sentence.
	 *
	 * Six short facts laid out as label/value rows take a screen's worth of
	 * height to carry a paragraph's worth of information. They read better as
	 * one line of prose with the specifics emphasised, so this returns the
	 * finished sentence — already escaped, with <strong> around the specifics.
	 */
	public static function summarise( $source ) {
		$data = json_decode( (string) $source, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			return '';
		}

		$plugins = array();
		$themes  = array();
		$content = 0;
		$options = array();
		$scripts = 0;
		$resets  = false;

		foreach ( (array) ( $data['steps'] ?? array() ) as $step ) {
			if ( is_string( $step ) ) {
				$step = array( 'step' => $step );
			}
			if ( ! is_array( $step ) || empty( $step['step'] ) || ! is_string( $step['step'] ) ) {
				continue;
			}

			switch ( $step['step'] ) {
				case 'installPlugin':
				case 'activatePlugin':
					$name = self::resource_name( $step, array( 'pluginData', 'pluginZipFile' ), array( 'pluginName', 'slug' ) );
					if ( $name ) {
						$plugins[ strtolower( $name ) ] = $name;
					}
					break;
				case 'installTheme':
				case 'activateTheme':
					$name = self::resource_name( $step, array( 'themeData', 'themeZipFile' ), array( 'themeFolderName', 'slug' ) );
					if ( $name ) {
						$themes[ strtolower( $name ) ] = $name;
					}
					break;
				case 'importWxr':
				case 'importWordPressFiles':
				case 'importThemeStarterContent':
					$content++;
					break;
				case 'setSiteOptions':
					foreach ( array_keys( (array) ( $step['options'] ?? array() ) ) as $option ) {
						$options[ $option ] = true;
					}
					break;
				case 'defineWpConfigConsts':
				case 'updateUserMeta':
				case 'setSiteLanguage':
					$options[ $step['step'] ] = true;
					break;
				case 'resetData':
					$resets = true;
					break;
				default:
					$scripts++;
			}
		}

		foreach ( array_keys( (array) ( $data['siteOptions'] ?? array() ) ) as $option ) {
			$options[ $option ] = true;
		}

		$clauses = array();

		if ( $plugins ) {
			$clauses[] = sprintf(
				/* translators: %s: list of plugin names. */
				_n( 'installs the %s plugin', 'installs the %s plugins', count( $plugins ), 'blueprint-registry' ),
				self::emphasise_list( $plugins )
			);
		}
		if ( $themes ) {
			$clauses[] = sprintf(
				/* translators: %s: list of theme names. */
				_n( 'installs the %s theme', 'installs the %s themes', count( $themes ), 'blueprint-registry' ),
				self::emphasise_list( $themes )
			);
		}
		if ( 1 === $content ) {
			$clauses[] = __( 'imports content', 'blueprint-registry' );
		} elseif ( $content > 1 ) {
			$clauses[] = sprintf(
				/* translators: %s: number of content files imported. */
				__( 'imports content from %s files', 'blueprint-registry' ),
				number_format_i18n( $content )
			);
		}
		if ( $resets ) {
			$clauses[] = __( 'removes the default posts and pages', 'blueprint-registry' );
		}
		if ( $options ) {
			$clauses[] = sprintf(
				/* translators: %s: number of site settings changed. */
				_n( 'changes %s site setting', 'changes %s site settings', count( $options ), 'blueprint-registry' ),
				number_format_i18n( count( $options ) )
			);
		}
		if ( $scripts ) {
			$clauses[] = sprintf(
				/* translators: %s: number of remaining setup steps. */
				_n( 'runs %s more setup step', 'runs %s more setup steps', $scripts, 'blueprint-registry' ),
				number_format_i18n( $scripts )
			);
		}
		if ( ! empty( $data['preferredVersions'] ) && is_array( $data['preferredVersions'] ) ) {
			$names    = array( 'php' => 'PHP', 'wp' => 'WordPress', 'wordpress' => 'WordPress' );
			$versions = array();
			foreach ( $data['preferredVersions'] as $key => $value ) {
				if ( is_scalar( $value ) ) {
					$key        = strtolower( (string) $key );
					$versions[] = ( $names[ $key ] ?? ucfirst( $key ) ) . ' ' . $value;
				}
			}
			if ( $versions ) {
				/* translators: %s: WordPress and PHP versions. */
				$clauses[] = sprintf( __( 'runs on %s', 'blueprint-registry' ), self::emphasise_list( $versions ) );
			}
		}
		if ( ! empty( $data['login'] ) ) {
			$clauses[] = __( 'logs you in as an administrator', 'blueprint-registry' );
		}

		if ( ! $clauses ) {
			return '';
		}

		// wp_sprintf's %l joins a list the way the current locale expects.
		return sprintf(
			/* translators: %s: a list of things the Blueprint does. */
			__( 'This Blueprint %s.', 'blueprint-registry' ),
			wp_sprintf( '%l', $clauses )
		);
	}

	/**
	 * Emphasises each name and joins them the way the locale expects.
	 */
	private static function emphasise_list( array $names ) {
		$marked = array_map(
			static function ( $name ) {
				return '<strong>' . esc_html( $name ) . '</strong>';
			},
			array_values( $names )
		);

		return wp_sprintf( '%l', $marked );
	}

	/**
	 * Joins names, naming the first few and counting the rest.
	 */
	private static function name_list( array $names, $limit = 4 ) {
		$names = array_values( $names );
		$shown = array_slice( $names, 0, $limit );
		$rest  = count( $names ) - count( $shown );

		if ( $rest > 0 ) {
			/* translators: 1: comma-separated list of names, 2: number of remaining names. */
			return sprintf( __( '%1$s and %2$d more', 'blueprint-registry' ), implode( ', ', $shown ), $rest );
		}

		return implode( ', ', $shown );
	}

	/**
	 * Reads a readable plugin or theme name out of a step.
	 *
	 * A directory slug names the thing, so it is worth prettifying. A bundled
	 * file only names the file — `theme.zip` says nothing, so the file name is
	 * kept verbatim rather than dressed up as a title.
	 */
	private static function resource_name( array $step, array $resource_keys, array $direct_keys ) {
		foreach ( $direct_keys as $key ) {
			if ( ! empty( $step[ $key ] ) && is_string( $step[ $key ] ) ) {
				return self::humanise_slug( $step[ $key ] );
			}
		}

		foreach ( $resource_keys as $key ) {
			if ( empty( $step[ $key ] ) || ! is_array( $step[ $key ] ) ) {
				continue;
			}
			if ( ! empty( $step[ $key ]['slug'] ) && is_string( $step[ $key ]['slug'] ) ) {
				return self::humanise_slug( $step[ $key ]['slug'] );
			}
			foreach ( array( 'path', 'url' ) as $inner ) {
				if ( ! empty( $step[ $key ][ $inner ] ) && is_string( $step[ $key ][ $inner ] ) ) {
					$path = parse_url( $step[ $key ][ $inner ], PHP_URL_PATH );
					return basename( $path ?: $step[ $key ][ $inner ] );
				}
			}
		}

		return '';
	}

	/**
	 * Turns a directory slug into a name.
	 *
	 * Word-capitalising a slug gets most of them right and a handful of very
	 * common ones wrong, so those are spelled out.
	 */
	private static function humanise_slug( $slug ) {
		$slug = trim( (string) $slug );
		if ( '' === $slug ) {
			return '';
		}

		$known = array(
			'woocommerce'    => 'WooCommerce',
			'wordpress-seo'  => 'Yoast SEO',
			'bbpress'        => 'bbPress',
			'buddypress'     => 'BuddyPress',
			'wp-super-cache' => 'WP Super Cache',
			'gutenberg'      => 'Gutenberg',
			'jetpack'        => 'Jetpack',
			'akismet'        => 'Akismet',
		);

		if ( isset( $known[ strtolower( $slug ) ] ) ) {
			return $known[ strtolower( $slug ) ];
		}

		if ( preg_match( '/[A-Z ]/', $slug ) ) {
			return $slug;
		}

		return ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
	}
}
