<?php
/**
 * Main config file for running a Local VIP project.
 *
 * DO NOT EDIT THIS FILE.
 *
 * This file is copied into this location from vendor/humanmade/local-vip on
 * `composer install`. All configuration should be done either in your
 * composer.json, or else a `local-config.php` file in this same directory.
 *
 * phpcs:disable PSR1.Files.SideEffects
 */

// Load an escape hatch early load file, if it exists.
if ( is_readable( __DIR__ . '/local-config.php' ) ) {
	require_once __DIR__ . '/local-config.php';
}

// Load the plugin API (like add_action etc) early, so everything loaded
// via the Composer autoloaders can using actions.
require_once __DIR__ . '/wordpress/wp-includes/plugin.php';

// Load the whole autoloader very early, this will also include all
// `autoload.files` from all modules.
require_once __DIR__ . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/wordpress/' );
}

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', __DIR__ . '/wp-content' );
}

if ( ! defined( 'WP_CONTENT_URL' ) && isset( $_SERVER['HTTP_HOST'] ) ) {
	$protocol = ! empty( $_SERVER['HTTPS'] ) ? 'https' : 'http';
	define( 'WP_CONTENT_URL', $protocol . '://' . $_SERVER['HTTP_HOST'] . '/wp-content' );
}

if ( ! defined( 'WP_INITIAL_INSTALL' ) || ! constant( 'WP_INITIAL_INSTALL' ) ) {
	// Multisite is always enabled, unless some spooky
	// early loading code tried to change that of course.
	if ( ! defined( 'MULTISITE' ) ) {
		define( 'MULTISITE', true );
	}
}

if ( ! isset( $table_prefix ) ) {
	$table_prefix = getenv( 'TABLE_PREFIX' ) ?: 'wp_';
}

/*
 * Environment-specific DB constants must be available by the time we're done
 * executing this file.
 */
$required_constants = [
	'DB_HOST',
	'DB_NAME',
	'DB_USER',
	'DB_PASSWORD',
];

foreach ( $required_constants as $constant ) {
	$env_value = getenv( $constant ) ?? null;
	if ( ! defined( $constant ) && $env_value ) {
        // Permit constant injection via environment variables of the same name.
		define( $constant, $env_value );
	}
	if ( ! defined( $constant ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		die( "$constant constant is not defined." );
	}
}

// Set up global to enable the memcached connection.
// This is only used by PHP 7.4 images.
global $memcached_servers;
// "memcached" is the hostname of the relevant network container.
$memcached_servers = [ [ 'memcached', 11211 ] ];

// Set up global to enable redis connection.
// This is only used by PHP 8+ images.
global $redis_server;
$redis_server = [
	'host' => getenv( 'REDIS_HOST' ),
	'port' => getenv( 'REDIS_PORT' ),
];

// Load VIP configuration and constants.
if ( is_readable( WP_CONTENT_DIR . '/vip-config/vip-config.php' ) ) {
	require_once WP_CONTENT_DIR . '/vip-config/vip-config.php';
}

if ( ! getenv( 'WP_PHPUNIT__TESTS_CONFIG' ) ) {
	require_once ABSPATH . 'wp-settings.php';
}
