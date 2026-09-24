<?php
/**
 * Diff renderer that marks skipped context.
 *
 * Autoloaded rather than declared alongside Blueprint_Registry_Diff: its parent
 * only exists once WordPress has loaded wp-diff.php.
 *
 * @package BlueprintRegistry
 */

defined( 'ABSPATH' ) || exit;

class Blueprint_Registry_Diff_Renderer extends WP_Text_Diff_Renderer_Table {
	/**
	 * WordPress renders diff blocks with no separator, so a reader cannot tell
	 * that unchanged lines were skipped between them. This says so.
	 */
	public function _startBlock( $header ) { // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore
		return Blueprint_Registry_Diff::hunk_header( $header );
	}
}
