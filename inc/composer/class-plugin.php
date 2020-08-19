<?php
/**
 * Local Server Composer Plugin.
 *
 * @package humanmade/local-vip
 */

namespace Altis\Local_Server\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;

/**
 * Altis Local Server Composer Plugin.
 *
 * @package humanmade/local-vip
 */
class Plugin implements PluginInterface, Capable, EventSubscriberInterface {
	/**
	 * Plugin activation callback.
	 *
	 * @param Composer $composer Composer object.
	 * @param IOInterface $io Composer disk interface.
	 * @return void
	 */
	public function activate( Composer $composer, IOInterface $io ) {
		$this->composer = $composer;
	}

	/**
	 * Return plugin capabilities.
	 *
	 * @return array
	 */
	public function getCapabilities() {
		return [
			'Composer\Plugin\Capability\CommandProvider' => __NAMESPACE__ . '\\Command_Provider',
		];
	}

	/**
	 * Register the composer events we want to run on.
	 *
	 * @return array
	 */
	public static function getSubscribedEvents() : array {
		return [
			'post-update-cmd' => [ 'install_files' ],
			'post-install-cmd' => [ 'install_files' ],
		];
	}

	/**
	 * Install additional files to the project on update / install
	 */
	public function install_files() {
		$dest   = dirname( $this->composer->getConfig()->get( 'vendor-dir' ) );

		// Update the .gitignore to include the wp-config.php, WordPress, the index.php
		// as these files should not be included in VCS.
		if ( ! is_readable( $dest . '/.gitignore' ) ) {
			$entries = [
				'# Local Server',
				'/wordpress',
				'/index.php',
				'/wp-config.php',
				'/vendor',
				'/wp-content/uploads',
			];
			file_put_contents( $dest . '/.gitignore', implode( "\n", $entries ) );
		}

		$directories = [
			'/wp-content',
			'/wp-content/plugins',
			'/wp-content/themes',
			'/wp-content/uploads',
		];

		foreach ( $directories as $required_directory ) {
			if ( ! is_dir( $dest . $required_directory ) ) {
				mkdir( $dest . $required_directory );
			}
		}
	}
}
