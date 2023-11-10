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
	// Determine HTTP_HOST.
	add_filter( 'local_vip/http_host', __NAMESPACE__ . '\\Cron\\apply_cron_hostname', 1, 1 );
	add_filter( 'local_vip/http_host', __NAMESPACE__ . '\\apply_env_hostname', 11, 1 );
	add_filter( 'local_vip/http_host', __NAMESPACE__ . '\\apply_default_hostname', 20, 1 );

	if ( empty( $_SERVER['HTTP_HOST'] ) ) {
		$_SERVER['HTTP_HOST'] = getenv( 'COMPOSE_PROJECT_NAME' ) . '.' . getenv( 'COMPOSE_PROJECT_TLD' );
	}

	$_SERVER['HTTP_HOST'] = apply_filters( 'local_vip/http_host', $_SERVER['HTTP_HOST'] );

	// Use logic borrowed from altis/cms to determine if we should set the
	// WP_INITIAL_INSTALL constant.
	if ( CLI\is_initial_install() ) {
		define( 'WP_INITIAL_INSTALL', true );
	} else if ( is_subdomain_install() ) {
		define( 'SUBDOMAIN_INSTALL', 1 );

		// Some environments may use Redis instead, so load Memcached conditionally.
		if ( PHP_VERSION_ID < 80000 ) {
			// Configure PECL Memcached integration to load on a very early hook.
			add_action( 'enable_wp_debug_mode_checks', __NAMESPACE__ . '\\load_object_cache_memcached' );
		} else {
			add_action( 'enable_wp_debug_mode_checks', __NAMESPACE__ . '\\load_object_cache_redis' );
		}
	}

	defined( 'DB_HOST' ) or define( 'DB_HOST', getenv( 'DB_HOST' ) );
	defined( 'DB_USER' ) or define( 'DB_USER', getenv( 'DB_USER' ) );
	defined( 'DB_PASSWORD' ) or define( 'DB_PASSWORD', getenv( 'DB_PASSWORD' ) );
	defined( 'DB_NAME' ) or define( 'DB_NAME', getenv( 'DB_NAME' ) );

	define( 'ELASTICSEARCH_HOST', getenv( 'ELASTICSEARCH_HOST' ) );
	define( 'ELASTICSEARCH_PORT', getenv( 'ELASTICSEARCH_PORT' ) );

	// Set up VIP specific constants to use Enterprise Search.
	define( 'VIP_ELASTICSEARCH_ENDPOINTS', [ getenv( 'VIP_ELASTICSEARCH_ENDPOINT' ) ] );
	define( 'VIP_ELASTICSEARCH_USERNAME', getenv( 'VIP_ELASTICSEARCH_USERNAME' ) );
	define( 'VIP_ELASTICSEARCH_PASSWORD', getenv( 'VIP_ELASTICSEARCH_PASSWORD' ) );
	define( 'FILES_CLIENT_SITE_ID', getenv( 'FILES_CLIENT_SITE_ID' ) ); // Mocks the site id on VIP.

	// Set "development" unless overridden in `local-config.php`.
	defined( 'WP_ENVIRONMENT_TYPE' ) or define( 'WP_ENVIRONMENT_TYPE', 'local' );

	if ( ! defined( 'AWS_XRAY_DAEMON_IP_ADDRESS' ) ) {
		define( 'AWS_XRAY_DAEMON_IP_ADDRESS', gethostbyname( getenv( 'AWS_XRAY_DAEMON_HOST' ) ) );
	}

	add_filter( 'qm/output/file_path_map', __NAMESPACE__ . '\\set_file_path_map', 1 );

	add_filter( 'pecl_memcached/warn_on_flush', '__return_false' );

	add_filter( 'cron_request', __NAMESPACE__ . '\\Cron\\filter_cron_url' );
}

/**
 * Try reading HTTP_HOST value from an environment variable.
 *
 * @param string|bool $hostname HTTP_HOST value.
 * @return string
 */
function apply_env_hostname( $hostname ) {
	// Try reading HTTP Host from environment
	if ( empty( $hostname ) ) {
		$env_hostname = getenv( 'HTTP_HOST' );
		if ( ! empty( $env_hostname ) ) {
			return $env_hostname;
		}
	}
	return $hostname;
}

/**
 * Default HTTP_HOST to {project name}.local if not set. Hooked to
 * 'local_vip/http_host' at a low priority.
 *
 * @param string|bool $hostname HTTP_HOST value.
 * @return string
 */
function apply_default_hostname( $hostname ) {
	if ( empty( $hostname ) ) {
		$project_name = getenv( 'COMPOSE_PROJECT_NAME' );
		if ( ! empty( $project_name ) ) {
			return $project_name;
		}
	}
	return $hostname;
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
 * Load the Redis Object Cache dropin.
 * Borrowed from humanmade/altis-cloud.
 */
function load_object_cache_redis() {
	wp_using_ext_object_cache( true );
	if ( ! defined( 'WP_REDIS_DISABLE_FAILBACK_FLUSH' ) ) {
		define( 'WP_REDIS_DISABLE_FAILBACK_FLUSH', true );
	}
	require dirname( ABSPATH ) . '/vendor/humanmade/wp-redis/object-cache.php';

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
