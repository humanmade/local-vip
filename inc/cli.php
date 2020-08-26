<?php
/**
 * Local VIP WP-CLI integration.
 *
 * This file exists mainly to make WP-CLI commands execute without error if WP
 * is not yet available.
 *
 * Derived from https://github.com/humanmade/altis-cms/blob/master/inc/cli/namespace.php
 *
 * @package humanmade/local-vip
 */

namespace HM\Local_VIP\CLI;

use WP_CLI;

/**
 * VaultPress and Jetpack scream into your debug log at the top of their lungs
 * if they get loaded as mu-plugins before WP is installed. This happens
 * during installation-related WP-CLI commands unless we use this hack to
 * prevent all MU Plugins from loading.
 *
 * @return void
 */
function disable_mu_plugins() : void {
	defined( 'WPMU_PLUGIN_DIR' ) or define( 'WPMU_PLUGIN_DIR', ABSPATH . 'should-not-exist-under-any-circumstances' );
}

/**
 * Check if the current process is the initial WordPress installation.
 *
 * @return boolean
 */
function is_initial_install() : bool {
	// Support for PHPUnit & direct calls to install.php.
	if ( php_sapi_name() === 'cli' && basename( $_SERVER['PHP_SELF'] ) === 'install.php' ) {
		return true;
	}

	if ( ! defined( 'WP_CLI' ) ) {
		return false;
	}

	$runner = WP_CLI::get_runner();

	// Check whether the `core` command is being invoked.
	if ( $runner->arguments[0] !== 'core' ) {
		return false;
	}

	// If it's the `is-installed` command and --network is set, then
	// allow MULTISITE to be defined.
	if ( $runner->arguments[1] === 'is-installed' && isset( $runner->assoc_args['network'] ) ) {
		return false;
	}

	// Check it's an install-related command.
	$commands = [ 'is-installed', 'install', 'multisite-install', 'multisite-convert' ];
	if ( ! in_array( $runner->arguments[1], $commands, true ) ) {
		return false;
	}

	// If we're running an install command, misdeclare the WPMU_PLUGINS_DIR
	// to prevent VIP's mu-plugins from loading.
	disable_mu_plugins();

	return true;
}
