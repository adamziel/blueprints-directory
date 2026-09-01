<?php

/**
 * Shared proposal comparison view for contributors and reviewers.
 *
 * @package BlueprintRegistry
 */

defined( 'ABSPATH' ) || exit;

final class Blueprint_Registry_Diff {
	public static function render_change( $change_id, $heading = null ) {
		$base_release_id = (int) get_post_meta( $change_id, '_bp_base_release_id', true );
		if ( ! $base_release_id ) {
			echo '<p>' . esc_html__( 'This is a new Blueprint and has no base release.', 'blueprint-registry' ) . '</p>';
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
			static function ( $attachment_id, $download ) use ( $change_id ) {
				return Blueprint_Registry_Admin::bundle_file_url( $change_id, $attachment_id, $download );
			}
		);
	}

	/**
	 * Renders the fixed copy that a reviewer can accept, request changes on, or reject.
	 */
	public static function render_submission( $submission_id, $heading = null ) {
		$base_release_id = (int) get_post_meta( $submission_id, '_bp_base_release_id', true );
		if ( ! $base_release_id ) {
			echo '<p>' . esc_html__( 'This is a new Blueprint and has no base release.', 'blueprint-registry' ) . '</p>';
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
			static function ( $attachment_id, $download ) use ( $submission_id ) {
				return Blueprint_Registry_Admin::bundle_file_url( (int) get_post_field( 'post_parent', $submission_id ), $attachment_id, $download );
			}
		);
	}

	private static function render_comparison( $base_release_id, $proposal_source, array $file_diff, $text_pair_for_path, $heading, $file_url ) {
		$base_source = self::canonical_source( (string) get_post_field( 'post_content', $base_release_id ) );
		$source_diff = self::render_text_diff( $base_source, $proposal_source );
		$changed_files = array_filter(
			$file_diff,
			static function ( $file ) {
				return 'blueprint.json' !== $file['path'] && 'unchanged' !== $file['status'];
			}
		);
		?>
		<section class="bp-change-diff">
			<?php if ( $heading ) : ?><h2><?php echo esc_html( $heading ); ?></h2><?php endif; ?>
			<h3><?php esc_html_e( 'Blueprint JSON', 'blueprint-registry' ); ?></h3>
			<?php echo $source_diff ? $source_diff : '<p>' . esc_html__( 'No changes to Blueprint JSON.', 'blueprint-registry' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<h3><?php esc_html_e( 'Bundle files', 'blueprint-registry' ); ?></h3>
				<?php if ( $changed_files ) : ?>
					<table class="widefat striped bp-change-diff__files">
						<thead><tr><th><?php esc_html_e( 'Path', 'blueprint-registry' ); ?></th><th><?php esc_html_e( 'Change', 'blueprint-registry' ); ?></th><th><?php esc_html_e( 'File', 'blueprint-registry' ); ?></th></tr></thead>
						<tbody>
							<?php foreach ( $changed_files as $file ) : ?>
								<?php $attachment_id = (int) $file['proposal_attachment_id']; ?>
								<tr><td><?php if ( $attachment_id ) : ?><a href="<?php echo esc_url( $file_url( $attachment_id, false ) ); ?>"><code><?php echo esc_html( $file['path'] ); ?></code></a><?php else : ?><code><?php echo esc_html( $file['path'] ); ?></code><?php endif; ?></td><td><?php echo esc_html( self::file_status_label( $file['status'] ) ); ?></td><td><?php if ( $attachment_id ) : ?><a href="<?php echo esc_url( $file_url( $attachment_id, false ) ); ?>"><?php esc_html_e( 'Open', 'blueprint-registry' ); ?></a> <a href="<?php echo esc_url( $file_url( $attachment_id, true ) ); ?>"><?php esc_html_e( 'Download', 'blueprint-registry' ); ?></a><?php else : ?>&mdash;<?php endif; ?></td></tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php foreach ( $changed_files as $file ) : ?>
					<?php if ( 'changed' === $file['status'] && ( $text_pair = $text_pair_for_path( $file['path'] ) ) ) : ?>
						<details class="bp-change-diff__file">
							<summary><?php echo esc_html( sprintf( __( 'Show text diff: %s', 'blueprint-registry' ), $file['path'] ) ); ?></summary>
							<?php echo self::render_text_diff( $text_pair['base'], $text_pair['proposal'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</details>
					<?php endif; ?>
				<?php endforeach; ?>
			<?php else : ?>
				<p><?php esc_html_e( 'The bundle files have not changed from the base release.', 'blueprint-registry' ); ?></p>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Keeps the source text literal: wp_text_diff() trims it and merges repeated whitespace.
	 */
	private static function render_text_diff( $base, $proposal ) {
		if ( ! class_exists( 'WP_Text_Diff_Renderer_Table', false ) ) {
			require_once ABSPATH . WPINC . '/wp-diff.php';
		}

		$diff = new Text_Diff(
			self::lines_with_visible_trailing_whitespace( $base ),
			self::lines_with_visible_trailing_whitespace( $proposal )
		);
		$output = ( new WP_Text_Diff_Renderer_Table( array( 'show_split_view' => false ) ) )->render( $diff );

		if ( ! $output ) {
			return '';
		}

		return "<table class='diff'>\n<tbody>\n{$output}</tbody>\n</table>";
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

	private static function file_status_label( $status ) {
		$labels = array(
			'added'   => __( 'Added', 'blueprint-registry' ),
			'changed' => __( 'Changed', 'blueprint-registry' ),
			'removed' => __( 'Removed', 'blueprint-registry' ),
		);
		return $labels[ $status ] ?? __( 'Unchanged', 'blueprint-registry' );
	}
}
