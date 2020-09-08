<?php
/**
 * Enable WP Cron requests to resolve inside Docker containers.
 *
 * @package humanmade/local-vip
 */

namespace HM\Local_VIP\Cron;

const HOST_QUERY_PARAM = 'docker_cron_hostname';

/**
 * Redirect PHP-originated Cron HTTP requests to point at the nginx container.
 * This is necessary because site domain names do not resolve within the PHP
 * container, where the nginx container is exposed as nginx:{port}.
 *
 * @param array $cron_request_array An array of cron request URL arguments.
 * @return void
 */
function filter_cron_url( array $cron_request_array ) {
	// Re-point requests to {hostname} to target the nginx docker container.
	$url = $cron_request_array['url'];
	$hostname = preg_replace( '#^https?://|/.*$#', '', $url );
	$url = str_replace( "https://$hostname", 'https://nginx:8443/wordpress', $url );
	$url = str_replace( "http://$hostname", 'http://nginx:8080/wordpress', $url );

	// Append a query parameter to detect where the request originated.
	$url = $url . ( strpos( $url, '?' ) ? '&' : '?' ) . HOST_QUERY_PARAM . '=' . $hostname;
	$cron_request_array['url'] = $url;

	// Self-signed certificate will not verify (this is the default, but
	// it is also explicitly safe to do this within our own container.)
	$cron_request_array['args']['sslverify'] = false;

	return $cron_request_array;
}

/**
 * Return the value of the query parameter specifying the hostname of the
 * originating site for a WP Cron request.
 *
 * @return string
 */
function get_cron_hostname_override() : string {
	if ( ! empty( $_GET['docker_cron_hostname'] ) ) {
		return strip_tags( $_GET['docker_cron_hostname'] );
	}
	return '';
}

/**
 * Try reading HTTP_HOST value from a query argument to enable cron requests
 * to short-circuit normal HTTP_HOST resolution.
 *
 * @param string|bool $hostname HTTP_HOST value.
 * @return string
 */
function apply_cron_hostname( $hostname ) {
	$cron_hostname = get_cron_hostname_override();
	if ( ! empty( $cron_hostname ) ) {
		return $cron_hostname;
	}
	return $hostname;
}
