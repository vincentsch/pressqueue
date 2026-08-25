<?php
/**
 * PressQueue uninstall cleanup.
 *
 * @package PressQueue
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$pressqueue_autoload = __DIR__ . '/vendor/autoload.php';

if ( file_exists( $pressqueue_autoload ) ) {
	require_once $pressqueue_autoload;
}

if ( class_exists( 'PressQueue\Privacy\UninstallPolicy' ) ) {
	PressQueue\Privacy\UninstallPolicy::uninstall();
}
