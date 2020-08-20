<?php
/**
 * Local VIP Composer Command Provider.
 *
 * @package humanmade/local-vip
 */

namespace HM\Local_VIP\Composer;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;

/**
 * Local VIP Composer Command Provider.
 *
 * @package humanmade/local-vip
 */
class Command_Provider implements CommandProviderCapability {
	/**
	 * Return available commands.
	 *
	 * @return array
	 */
	public function getCommands() {
		return [ new Command ];
	}
}
