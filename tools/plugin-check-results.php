<?php
/**
 * Plugin Check result parsing helpers.
 *
 * @package PressQueue\Tools
 */

declare(strict_types=1);

/**
 * Collect warning and error results from Plugin Check output.
 *
 * @param mixed $value Plugin Check output.
 * @return array<int,mixed>
 */
function pressqueue_collect_plugin_check_failures( $value ): array {
	if ( ! is_array( $value ) ) {
		return array();
	}

	$failures = array();
	$type     = strtoupper( (string) ( $value['type'] ?? '' ) );
	$severity = strtolower( (string) ( $value['severity'] ?? '' ) );

	if (
		in_array( $type, array( 'ERROR', 'WARNING' ), true )
		|| in_array( $severity, array( 'error', 'warning' ), true )
	) {
		$failures[] = $value;
	}

	foreach ( $value as $child ) {
		if ( is_array( $child ) ) {
			$failures = array_merge( $failures, pressqueue_collect_plugin_check_failures( $child ) );
		}
	}

	return $failures;
}

/**
 * Determine whether Plugin Check emitted a non-JSON clean success message.
 *
 * @param string $output Command output.
 * @return bool Whether the output is an explicit clean success.
 */
function pressqueue_plugin_check_output_is_success( string $output ): bool {
	return false !== strpos( $output, 'Plugin Check passed' )
		|| false !== strpos( $output, 'Success:' );
}
