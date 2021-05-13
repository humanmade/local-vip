<?php
/**
 * This file is loaded using composer.json's autoload.files option.
 *
 * In a similar manner to a WordPress plugin, it then manually requires local
 * server namespace files and calls those namespaces' initialization methods.
 *
 * @package humanmade/local-vip
 *
 * phpcs:disable PSR1.Files.SideEffects
 */

require_once __DIR__ . '/inc/namespace.php';
require_once __DIR__ . '/inc/cli.php';
require_once __DIR__ . '/inc/cron.php';

if ( defined( 'PHP_SAPI' ) && PHP_SAPI === 'cli' ) {
	ini_set( 'display_errors', 'on' );
}

// If running WordPress.
if ( function_exists( 'add_filter' ) ) {
	// Initialize server logic.
	HM\Local_VIP\bootstrap();
}
