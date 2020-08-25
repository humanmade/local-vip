<?php
/**
 * Local Server for WordPress VIP Projects.
 *
 * @package humanmade/local-vip
 */

namespace HM\Local_VIP;

/**
 * Configure environment for local server.
 */
function bootstrap() {
	// Try reading HTTP Host from environment
	if ( empty( $_SERVER['HTTP_HOST'] ) ) {
		$_SERVER['HTTP_HOST'] = getenv( 'HTTP_HOST' );
	}
	// fall back to {project name}.local
	if ( empty( $_SERVER['HTTP_HOST'] ) ) {
		$_SERVER['HTTP_HOST'] = getenv( 'COMPOSE_PROJECT_NAME' ) . '.local';
	}

	// Use logic borrowed from altis/cms to determine if we should set the
	// WP_INITIAL_INSTALL constant.
	if ( CLI\is_initial_install() ) {
		define( 'WP_INITIAL_INSTALL', true );
	}

	if ( ! CLI\is_initial_install() && is_subdomain_install() ) {
		define( 'SUBDOMAIN_INSTALL', 1 );
	}

	defined( 'DB_HOST' ) or define( 'DB_HOST', getenv( 'DB_HOST' ) );
	defined( 'DB_USER' ) or define( 'DB_USER', getenv( 'DB_USER' ) );
	defined( 'DB_PASSWORD' ) or define( 'DB_PASSWORD', getenv( 'DB_PASSWORD' ) );
	defined( 'DB_NAME' ) or define( 'DB_NAME', getenv( 'DB_NAME' ) );

	define( 'ELASTICSEARCH_HOST', getenv( 'ELASTICSEARCH_HOST' ) );
	define( 'ELASTICSEARCH_PORT', getenv( 'ELASTICSEARCH_PORT' ) );

	if ( ! defined( 'AWS_XRAY_DAEMON_IP_ADDRESS' ) ) {
		define( 'AWS_XRAY_DAEMON_IP_ADDRESS', gethostbyname( getenv( 'AWS_XRAY_DAEMON_HOST' ) ) );
	}

	add_filter( 'qm/output/file_path_map', __NAMESPACE__ . '\\set_file_path_map', 1 );

	// Configure PECL Memcached integration to load on a very early hook.
	add_action( 'enable_wp_debug_mode_checks', __NAMESPACE__ . '\\load_object_cache_memcached' );
}

/**
 * Check an environment variable to determine if the server believes itself to
 * be a WordPress Multisite "Subdomain" install.
 */
function is_subdomain_install() : bool {
	return in_array( getenv( 'SUBDOMAIN_INSTALL' ), [ true, 1 ] );
}

/**
 * Load the Memcached Object Cache dropin.
 * Borrowed from humanmade/altis-cloud.
 */
function load_object_cache_memcached() {
	wp_using_ext_object_cache( true );
	require dirname( ABSPATH ) . '/vendor/humanmade/wordpress-pecl-memcached-object-cache/object-cache.php';

	// cache must be initted once it's included, else we'll get a fatal.
	wp_cache_init();
}

/**
 * Enables Query Monitor to map paths to their original values on the host.
 *
 * @param array $map Map of guest path => host path.
 * @return array Adjusted mapping of folders.
 */
function set_file_path_map( array $map ) : array {
	if ( ! getenv( 'HOST_PATH' ) ) {
		return $map;
	}
	$map['/usr/src/app'] = rtrim( getenv( 'HOST_PATH' ), DIRECTORY_SEPARATOR );
	return $map;
}

/**
 * Add new submenus to Tools admin menu.
 */
function tools_submenus() {
	$links = [
		[
			'label' => 'Kibana',
			'url' => network_site_url( '/kibana' ),
		],
		[
			'label' => 'MailHog',
			'url' => network_site_url( '/mailhog' ),
		],
	];

	foreach ( $links as $link ) {
		add_management_page( $link['label'], $link['label'], 'manage_options', $link['url'] );
	}
}
