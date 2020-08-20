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
	if ( empty( $_SERVER['HTTP_HOST'] ) ) {
		// Try reading HTTP Host from environment, and fall back to {project name}.local
		$_SERVER['HTTP_HOST'] = getenv( 'HTTP_HOST' );
		if ( empty( $_SERVER['HTTP_HOST'] ) ) {
			$_SERVER['HTTP_HOST'] = getenv( 'COMPOSE_PROJECT_NAME' ) . '.local';
		}
	}

	define( 'DB_HOST', getenv( 'DB_HOST' ) );
	define( 'DB_USER', getenv( 'DB_USER' ) );
	define( 'DB_PASSWORD', getenv( 'DB_PASSWORD' ) );
	define( 'DB_NAME', getenv( 'DB_NAME' ) );

	define( 'ELASTICSEARCH_HOST', getenv( 'ELASTICSEARCH_HOST' ) );
	define( 'ELASTICSEARCH_PORT', getenv( 'ELASTICSEARCH_PORT' ) );

	if ( ! defined( 'AWS_XRAY_DAEMON_IP_ADDRESS' ) ) {
		define( 'AWS_XRAY_DAEMON_IP_ADDRESS', gethostbyname( getenv( 'AWS_XRAY_DAEMON_HOST' ) ) );
	}

	global $redis_server;
	$redis_server = [
		'host' => getenv( 'REDIS_HOST' ),
		'port' => getenv( 'REDIS_PORT' ),
	];

	add_filter( 'qm/output/file_path_map', __NAMESPACE__ . '\\set_file_path_map', 1 );

	// Filter ES package IDs for local.
	add_filter( 'altis.search.packages_dir', __NAMESPACE__ . '\\set_search_packages_dir' );
	add_filter( 'altis.search.create_package_id', __NAMESPACE__ . '\\set_search_package_id', 10, 3 );
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

/**
 * Override Elasticsearch package storage location to es-packages volume.
 *
 * This directory is shared with the Elasticsearch container.
 *
 * @return string
 */
function set_search_packages_dir() : string {
	return sprintf( 's3://%s/uploads/es-packages', S3_UPLOADS_BUCKET );
}

/**
 * Override the derived ES package file name for local server.
 *
 * @param string|null $id The package ID used for the file path in ES.
 * @param string $slug The package slug.
 * @param string $file The package file path on S3.
 * @return string|null
 */
function set_search_package_id( $id, string $slug, string $file ) : ?string {
	$id = sprintf( 'packages/%s', basename( $file ) );
	return $id;
}
