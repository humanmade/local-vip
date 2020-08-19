<?php
/**
 * This file will always be included if local-server is installed.
 * We only want to do anything if we are actually running in the
 * local-server context. Therefore, we only define ENV_ARCHITECTURE
 * if we are in that context.
 *
 * This module will only then enable it's self if the architecture is local-server.
 *
 * @package humanmade/local-vip
 */

/* phpcs:disable PSR1.Files.SideEffects */
if ( defined( 'PHP_SAPI' ) && PHP_SAPI === 'cli' ) {
	ini_set( 'display_errors', 'on' );
}

if ( getenv( 'ENV_ARCHITECTURE' ) === 'local-server' ) {
	define( 'ENV_ARCHITECTURE', getenv( 'ENV_ARCHITECTURE' ) );
}
/* phpcs:enable PSR1.Files.SideEffects */
