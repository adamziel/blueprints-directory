<?php
/**
 * Shared proposal comparison view for contributors and reviewers.
 *
 * Both audiences see the same thing: a summary of the size of the change, then
 * the Blueprint JSON, then every bundle file that moved.
 *
 * @package BlueprintRegistry
 */

defined( 'ABSPATH' ) || exit;

final class Blueprint_Registry_Diff {
	public static function render_change( $change_id, $heading = null ) {
		$base_release_id = (int) get_post_meta( $change_id, '_bp_base_release_id', true );
		if ( ! $base_release_id ) {
			self::render_new_blueprint(
				$change_id,
				Blueprint_Registry_Workflow::source( $change_id ),
				Blueprint_Registry_Bundles::get_change_files( $change_id ),
				static function ( $key ) use ( $change_id ) {
					return Blueprint_Registry_Admin::bundle_file_url( $change_id, $key, true );
				}
			);
			return;
		}

		self::render_comparison(
			$base_release_id,
			self::canonical_source( Blueprint_Registry_Workflow::source( $change_id ) ),
			Blueprint_Registry_Bundles::diff_release_to_change( $base_release_id, $change_id ),
			static function ( $path ) use ( $base_release_id, $change_id ) {
				return Blueprint_Registry_Bundles::text_file_pair( $base_release_id, $change_id, $path );
			},
			$heading,
			static function ( $key, $download ) use ( $change_id ) {
				return Blueprint_Registry_Admin::bundle_file_url( $change_id, $key, $download );
			}
		);
	}

	/**
	 * Renders the fixed copy that a reviewer can accept, request changes on, or reject.
	 */
	public static function render_submission( $submission_id, $heading = null ) {
		$base_release_id = (int) get_post_meta( $submission_id, '_bp_base_release_id', true );
		if ( ! $base_release_id ) {
			self::render_new_blueprint(
				$submission_id,
				(string) get_post_field( 'post_content', $submission_id ),
				Blueprint_Registry_Bundles::get_submission_files( $submission_id ),
				static function ( $key ) use ( $submission_id ) {
					return Blueprint_Registry_Admin::submission_file_url( $submission_id, $key, true );
				}
			);
			return;
		}

		self::render_comparison(
			$base_release_id,
			self::canonical_source( (string) get_post_field( 'post_content', $submission_id ) ),
			Blueprint_Registry_Bundles::diff_release_to_submission( $base_release_id, $submission_id ),
			static function ( $path ) use ( $base_release_id, $submission_id ) {
				return Blueprint_Registry_Bundles::text_file_pair_submission( $base_release_id, $submission_id, $path );
			},
			$heading,
			static function ( $key, $download ) use ( $submission_id ) {
				return Blueprint_Registry_Admin::submission_file_url( $submission_id, $key, $download );
			}
		);
	}

	/**
	 * Compares one published revision against another.
	 */
	public static function render_releases( $base_release_id, $target_release_id, $heading = null ) {
		self::render_comparison(
			$base_release_id,
			self::canonical_source( (string) get_post_field( 'post_content', $target_release_id ) ),
			Blueprint_Registry_Bundles::diff_release_to_release( $base_release_id, $target_release_id ),
			static function ( $path ) use ( $base_release_id, $target_release_id ) {
				return Blueprint_Registry_Bundles::text_file_pair_releases( $base_release_id, $target_release_id, $path );
			},
			$heading,
			static function () {
				return '';
			}
		);
	}

	/**
	 * With no earlier release, the complete JSON and file list are the review.
	 */
	private static function render_new_blueprint( $post_id, $source, $files, $file_url ) {
		$lines = explode( "\n", self::canonical_source( $source ) );
		?>
		<section class="bp-panel bp-diff">
			<div class="bp-panel__head">
				<h2><?php esc_html_e( 'New Blueprint contents', 'blueprint-registry' ); ?></h2>
				<span class="bp-panel__head-note"><?php echo esc_html( sprintf( _n( '%d file', '%d files', count( $files ) + 1, 'blueprint-registry' ), count( $files ) + 1 ) ); ?></span>
			</div>
			<div class="bp-diff__file-head"><span class="bp-diff__file-path">blueprint.json</span></div>
			<div class="bp-code bp-code--capped" tabindex="0" aria-label="<?php esc_attr_e( 'Blueprint JSON', 'blueprint-registry' ); ?>">
				<table><tbody>
					<?php foreach ( $lines as $index => $line ) : ?>
						<tr><td class="bp-code__ln"><?php echo esc_html( $index + 1 ); ?></td><td class="bp-code__line"><?php echo Blueprint_Registry_Ui::highlight_json( $line ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
					<?php endforeach; ?>
				</tbody></table>
			</div>
			<?php if ( $files ) : ?>
				<table class="bp-files"><tbody>
					<?php foreach ( $files as $file ) : ?>
						<tr>
							<td>
								<?php $contents = Blueprint_Registry_Storage::read( $post_id, $file, 262144 ); ?>
								<?php if ( ! is_wp_error( $contents ) && ! str_contains( $contents, "\0" ) && preg_match( '//u', $contents ) ) : ?>
									<details class="bp-file-inspect"><summary class="bp-file-name"><?php echo esc_html( $file['path'] ); ?></summary><pre><?php echo esc_html( $contents ); ?></pre></details>
								<?php else : ?>
									<a class="bp-file-name" href="<?php echo esc_url( $file_url( $file['key'] ) ); ?>"><?php echo esc_html( $file['path'] ); ?></a>
								<?php endif; ?>
							</td>
							<td class="bp-files__size"><?php echo esc_html( size_format( $file['size'] ) ); ?></td>
							<td><a class="bp-btn bp-btn--sm" href="<?php echo esc_url( $file_url( $file['key'] ) ); ?>"><?php esc_html_e( 'Download', 'blueprint-registry' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
				</tbody></table>
			<?php endif; ?>
		</section>
		<?php
	}

	private static function render_comparison( $base_release_id, $proposal_source, array $file_diff, $text_pair_for_path, $heading, $file_url ) {
		$base_source   = self::canonical_source( (string) get_post_field( 'post_content', $base_release_id ) );
		$source_diff   = self::text_diff( $base_source, $proposal_source );
		$changed_files = array_values(
			array_filter(
				$file_diff,
				static function ( $file ) {
					return 'blueprint.json' !== $file['path'] && 'unchanged' !== $file['status'];
				}
			)
		);

		$added   = $source_diff['added'];
		$removed = $source_diff['removed'];
		$pairs   = array();

		foreach ( $changed_files as $file ) {
			$pair = $text_pair_for_path( $file['path'] );
			if ( ! $pair ) {
				continue;
			}
			$pairs[ $file['path'] ] = self::text_diff( $pair['base'], $pair['proposal'] );
			$added                 += $pairs[ $file['path'] ]['added'];
			$removed               += $pairs[ $file['path'] ]['removed'];
		}

		$file_count = count( $changed_files ) + ( $source_diff['html'] ? 1 : 0 );
		?>
		<section class="bp-panel bp-diff">
			<?php if ( $heading ) : ?>
				<div class="bp-panel__head">
					<h2><?php echo esc_html( $heading ); ?></h2>
					<span class="bp-panel__head-note"><?php echo esc_html( sprintf( __( 'Base: revision %d', 'blueprint-registry' ), (int) get_post_meta( $base_release_id, '_bp_release_number', true ) ) ); ?></span>
				</div>
			<?php endif; ?>

			<div class="bp-diff__summary">
				<span class="bp-diff__stat">
					<?php echo Blueprint_Registry_Ui::icon( 'diff' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo esc_html( sprintf( _n( '%d file changed', '%d files changed', $file_count, 'blueprint-registry' ), $file_count ) ); ?>
				</span>
				<?php if ( $added || $removed ) : ?>
					<span class="bp-diff__stat">
						<span class="bp-diff__added">+<?php echo esc_html( $added ); ?></span>
						<span class="bp-diff__removed">&minus;<?php echo esc_html( $removed ); ?></span>
					</span>
				<?php endif; ?>
			</div>

			<?php if ( ! $file_count ) : ?>
				<?php
				Blueprint_Registry_Ui::empty_state(
					'check',
					__( 'Identical to the base revision', 'blueprint-registry' ),
					__( 'Nothing has changed yet, so there is nothing for a reviewer to decide on.', 'blueprint-registry' )
				);
				?>
			<?php endif; ?>

			<?php if ( $source_diff['html'] ) : ?>
				<div class="bp-diff__file">
					<div class="bp-diff__file-head">
						<?php echo Blueprint_Registry_Ui::icon( 'code' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span class="bp-diff__file-path">blueprint.json</span>
						<span class="bp-diff__added">+<?php echo esc_html( $source_diff['added'] ); ?></span>
						<span class="bp-diff__removed">&minus;<?php echo esc_html( $source_diff['removed'] ); ?></span>
						<span class="bp-diff__badge bp-diff__badge--changed"><?php esc_html_e( 'Changed', 'blueprint-registry' ); ?></span>
					</div>
					<div class="bp-diff__body"><?php echo $source_diff['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
				</div>
			<?php endif; ?>

			<?php foreach ( $changed_files as $file ) : ?>
				<?php $file_key = (string) ( $file['proposal_key'] ?? '' ); ?>
				<div class="bp-diff__file">
					<div class="bp-diff__file-head">
						<?php echo Blueprint_Registry_Ui::icon( Blueprint_Registry_Ui::file_icon( $file['path'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<span class="bp-diff__file-path"><?php echo esc_html( $file['path'] ); ?></span>
						<?php if ( isset( $pairs[ $file['path'] ] ) ) : ?>
							<span class="bp-diff__added">+<?php echo esc_html( $pairs[ $file['path'] ]['added'] ); ?></span>
							<span class="bp-diff__removed">&minus;<?php echo esc_html( $pairs[ $file['path'] ]['removed'] ); ?></span>
						<?php endif; ?>
						<span class="bp-diff__badge bp-diff__badge--<?php echo esc_attr( $file['status'] ); ?>"><?php echo esc_html( self::file_status_label( $file['status'] ) ); ?></span>
						<?php if ( $file_key ) : ?>
							<a class="bp-btn bp-btn--sm bp-btn--ghost" href="<?php echo esc_url( $file_url( $file_key, true ) ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Download %s', 'blueprint-registry' ), $file['path'] ) ); ?>">
								<?php echo Blueprint_Registry_Ui::icon( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</a>
						<?php endif; ?>
					</div>
					<?php if ( ! empty( $pairs[ $file['path'] ]['html'] ) ) : ?>
						<div class="bp-diff__body"><?php echo $pairs[ $file['path'] ]['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
					<?php elseif ( 'added' === $file['status'] ) : ?>
						<p class="bp-diff__empty"><?php esc_html_e( 'New file in this proposal.', 'blueprint-registry' ); ?></p>
					<?php elseif ( 'removed' === $file['status'] ) : ?>
						<p class="bp-diff__empty"><?php esc_html_e( 'Removed from the bundle.', 'blueprint-registry' ); ?></p>
					<?php else : ?>
						<p class="bp-diff__empty"><?php esc_html_e( 'Binary file. Download it to compare.', 'blueprint-registry' ); ?></p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</section>
		<?php
	}

	/**
	 * Renders a unified diff and counts the lines that moved.
	 *
	 * Keeps the source text literal: wp_text_diff() trims it and merges
	 * repeated whitespace.
	 */
	private static function text_diff( $base, $proposal ) {
		if ( ! class_exists( 'WP_Text_Diff_Renderer_Table', false ) ) {
			require_once ABSPATH . WPINC . '/wp-diff.php';
		}

		$base_lines     = '' === $base ? array() : self::lines_with_visible_trailing_whitespace( $base );
		$proposal_lines = '' === $proposal ? array() : self::lines_with_visible_trailing_whitespace( $proposal );
		$diff           = new Text_Diff( $base_lines, $proposal_lines );
		$renderer       = new Blueprint_Registry_Diff_Renderer(
			array(
				'show_split_view' => false,
				// WordPress renders whole files by default; a reviewer only needs
				// the lines around each edit.
				'leading_context_lines'  => 4,
				'trailing_context_lines' => 4,
			)
		);
		$output         = $renderer->render( $diff );

		if ( ! $output ) {
			return array(
				'html'    => '',
				'added'   => 0,
				'removed' => 0,
			);
		}

		$added   = 0;
		$removed = 0;
		foreach ( $diff->getDiff() as $edit ) {
			if ( $edit instanceof Text_Diff_Op_add ) {
				$added += count( $edit->final );
			} elseif ( $edit instanceof Text_Diff_Op_delete ) {
				$removed += count( $edit->orig );
			} elseif ( $edit instanceof Text_Diff_Op_change ) {
				$added   += count( $edit->final );
				$removed += count( $edit->orig );
			}
		}

		return array(
			'html'    => "<table class='diff'>\n<tbody>\n{$output}</tbody>\n</table>",
			'added'   => $added,
			'removed' => $removed,
		);
	}

	private static function lines_with_visible_trailing_whitespace( $source ) {
		$source = str_replace( array( "\r\n", "\r" ), "\n", (string) $source );
		$source = preg_replace_callback(
			'/[ \t]+$/m',
			static function ( $matches ) {
				return strtr( $matches[0], array( ' ' => '·', "\t" => '⇥' ) );
			},
			$source
		);

		return explode( "\n", $source );
	}

	private static function canonical_source( $source ) {
		$canonical = Blueprint_Registry_Validator::canonical_json( $source );
		return is_wp_error( $canonical ) ? $source : $canonical;
	}

	/**
	 * Marks where unchanged lines were left out.
	 *
	 * Trimming context without saying so reads as if the file were that short.
	 */
	public static function hunk_header( $header ) {
		// Text_Diff hands over a range pair such as "11,19c11,20". Only the
		// proposal side is worth showing, and only as plain line numbers.
		$proposal = substr( (string) $header, strcspn( (string) $header, 'acd' ) + 1 );
		$bounds   = array_map( 'intval', explode( ',', $proposal ) );
		$label    = count( $bounds ) > 1 && $bounds[1] > $bounds[0]
			/* translators: 1: first line number, 2: last line number. */
			? sprintf( __( 'Lines %1$d to %2$d', 'blueprint-registry' ), $bounds[0], $bounds[1] )
			/* translators: %d: line number. */
			: sprintf( __( 'Line %d', 'blueprint-registry' ), $bounds[0] );

		return sprintf( '<tr class="bp-diff__hunk"><td>%s</td></tr>', esc_html( $label ) );
	}

	private static function file_status_label( $status ) {
		$labels = array(
			'added'   => __( 'Added', 'blueprint-registry' ),
			'changed' => __( 'Changed', 'blueprint-registry' ),
			'removed' => __( 'Removed', 'blueprint-registry' ),
		);
		return $labels[ $status ] ?? __( 'Unchanged', 'blueprint-registry' );
	}
}
