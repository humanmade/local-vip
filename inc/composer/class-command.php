<?php
/**
 * Local VIP Composer Command.
 *
 * phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
 *
 * @package humanmade/local-vip
 */

namespace HM\Local_VIP\Composer;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Process\Process;

/**
 * Local VIP Composer Command.
 *
 * @package humanmade/local-vip
 */
class Command extends BaseCommand {

	/**
	 * Command configuration.
	 *
	 * @return void
	 */
	protected function configure() {
		$this
			->setName( 'server' )
			->setDescription( 'Local Server for VIP Projects' )
			->setDefinition( [
				new InputArgument( 'subcommand', null, 'start, stop, restart, cli, exec, shell, status, db, logs.' ),
				new InputArgument( 'options', InputArgument::IS_ARRAY ),
			] )
			->setAliases( [ 'local-server', 'local-vip' ] )
			->setHelp(
				<<<EOT
Run the local development server.

Default command - start the local development server:
	start [--xdebug]              passing --xdebug starts the server with xdebug enabled
Stop the local development server:
	stop
Restart the local development server:
	restart [--xdebug]            passing --xdebug restarts the server with xdebug enabled
Destroy the local development server:
	destroy
View status of the local development server:
	status
Run WP CLI command:
	cli -- <command>              eg: cli -- post list --debug
Run any shell command from the PHP container:
	exec -- <command>             eg: exec -- vendor/bin/phpcs
Open a shell:
	shell
Database commands:
	db                            Log into MySQL on the Database server
	db sequel                     Generates an SPF file for Sequel Pro
	db info                       Prints out Database connection details
View the logs
	logs <service>                <service> can be php, nginx, db, elasticsearch, xray
EOT
			)
			->addOption( 'xdebug' );
	}

	/**
	 * Get the common docker-composer command prefix.
	 *
	 * @return string
	 */
	private function get_base_command_prefix() : string {
		return sprintf(
			'cd %s; VOLUME=%s COMPOSE_PROJECT_NAME=%s',
			'vendor/humanmade/local-vip/docker',
			escapeshellarg( getcwd() ),
			$this->get_project_name()
		);
	}

	/**
	 * Execute the given command.
	 *
	 * @param InputInterface $input Command input object.
	 * @param OutputInterface $output Command output object.
	 * @return int|null
	 */
	protected function execute( InputInterface $input, OutputInterface $output ) {
		$subcommand = $input->getArgument( 'subcommand' );

		if ( $subcommand === 'start' ) {
			return $this->start( $input, $output );
		} elseif ( $subcommand === 'stop' ) {
			return $this->stop( $input, $output );
		} elseif ( $subcommand === 'restart' ) {
			return $this->restart( $input, $output );
		} elseif ( $subcommand === 'destroy' ) {
			return $this->destroy( $input, $output );
		} elseif ( $subcommand === 'cli' ) {
			return $this->exec( $input, $output, 'wp' );
		} elseif ( $subcommand === 'exec' ) {
			return $this->exec( $input, $output );
		} elseif ( $subcommand === 'db' ) {
			return $this->db( $input, $output );
		} elseif ( $subcommand === 'status' ) {
			return $this->status( $input, $output );
		} elseif ( $subcommand === 'logs' ) {
			return $this->logs( $input, $output );
		} elseif ( $subcommand === 'shell' ) {
			return $this->shell( $input, $output );
		} elseif ( $subcommand === null ) {
			// Default to start command.
			return $this->start( $input, $output );
		}

		$output->writeln( '<error>' . $subcommand . ' command not found.</>' );
		return 1;
	}

	/**
	 * Get environment variables to pass to docker-compose and other subcommands.
	 *
	 * @return array Map of var name => value.
	 */
	protected function get_env() : array {
		return [
			'VOLUME' => getcwd(),
			'COMPOSE_PROJECT_NAME' => $this->get_project_name(),
			'HTTP_HOST' => $this->get_project_hostname(),
			'PATH' => getenv( 'PATH' ),
			'ES_MEM_LIMIT' => getenv( 'ES_MEM_LIMIT' ) ?: '1g',
			// Pass subdomain config through to docker, and from there on to PHP.
			// This is necessary to enable subdomains, because the --subdomains
			// argument to multisite-install has no effect.
			'SUBDOMAIN_INSTALL' => ( $this->get_server_config()['subdomains'] ?? false ) ? 1 : 0,
		];
	}

	/**
	 * Start the application.
	 *
	 * @param InputInterface $input Command input object.
	 * @param OutputInterface $output Command output object.
	 * @return int|null
	 */
	protected function start( InputInterface $input, OutputInterface $output ) {
		$output->writeln( '<info>Starting...</>' );

		$env = $this->get_env();
		if ( $input->getOption( 'xdebug' ) ) {
			$env['PHP_IMAGE'] = 'humanmade/altis-local-server-php:4.0.0-dev';
			if ( in_array( php_uname( 's' ), [ 'BSD', 'Linux', 'Solaris', 'Unknown' ], true ) ) {
				$env['XDEBUG_REMOTE_HOST'] = '172.17.0.1';
			}
		}

		$compose = new Process( 'docker-compose up -d', 'vendor/humanmade/local-vip/docker', $env );
		$compose->setTty( true );
		$compose->setTimeout( 0 );
		$failed = $compose->run( function ( $type, $buffer ) {
			echo $buffer;
		} );

		if ( $failed ) {
			$output->writeln( '<error>Services failed to start successfully.</>' );
			return $failed;
		}

		$cli = $this->getApplication()->find( 'local-server' );

		// Check if WP is already installed.
		$is_installed = $cli->run( new ArrayInput( [
			'subcommand' => 'cli',
			'options' => [
				'core',
				'is-installed',
				'--quiet',
			],
		] ), $output ) === 0;

		if ( ! $is_installed ) {
			$server_config = $this->get_server_config();
			$hostname = $this->get_project_hostname();

			$install_failed = $cli->run( new ArrayInput( [
				'subcommand' => 'cli',
				'options' => [
					'core',
					'multisite-install',
					'--title=' . ( $server_config['title'] ?? 'VIP Project' ),
					'--admin_user=wordpress',
					'--admin_password=password',
					'--admin_email=no-reply@' . $hostname,
					'--skip-email',
					'--skip-config',
					'--quiet',
				],
			] ), $output );

			// Check install was successful.
			if ( $install_failed ) {
				$output->writeln( sprintf( '<error>WordPress install failed. Exited with error code %d</>', $install_failed ) );
				return $install_failed;
			}

			$sites = $server_config['sites'] ?? [];
			if ( is_array( $sites ) && ! empty( $sites ) ) {
				foreach ( $sites as $slug => $title ) {
					if ( ! is_string( $slug ) ) {
						$slug = $title;
					}
					$site_options = [
						'site',
						'create',
						'--slug=' . preg_replace( '/[^A-Za-z0-9\-\_]/', '', $slug ),
						'--quiet',
					];
					if ( is_string( $title ) && $title !== $slug ) {
						$site_options[] = '--title=' . $title;
					}
					$site_created = $cli->run( new ArrayInput( [
						'subcommand' => 'cli',
						'options' => $site_options,
					] ), $output ) === 0;

					if ( $site_created ) {
						$output->writeln( sprintf( 'Created network site %s', $slug ) );
					} else {
						$output->writeLn( sprintf( '<warning>Failed to create network site %s</>', $slug ) );
					}
				}
			}

			$output->writeln( '<info>WP Username:</>	<comment>wordpress</>' );
			$output->writeln( '<info>WP Password:</>	<comment>password</>' );
		}

		$site_url = 'https://' . $this->get_project_hostname();
		$output->writeln( '<info>Startup completed.</>' );
		$output->writeln( '<info>To access your site visit:</> <comment>' . $site_url . '</>' );

	}

	/**
	 * Stop the application.
	 *
	 * @param InputInterface $input Command input object.
	 * @param OutputInterface $output Command output object.
	 * @return int
	 */
	protected function stop( InputInterface $input, OutputInterface $output ) {
		$output->writeln( '<info>Stopping...</>' );

		$compose = new Process( 'docker-compose stop', 'vendor/humanmade/local-vip/docker', $this->get_env() );
		$return_val = $compose->run( function ( $type, $buffer ) {
			echo $buffer;
		} );

		if ( $return_val === 0 ) {
			$output->writeln( '<info>Stopped.</>' );
		} else {
			$output->writeln( '<error>Failed to stop services.</>' );
		}

		return $return_val;
	}

	/**
	 * Destroys the application.
	 *
	 * @param InputInterface $input Command input object.
	 * @param OutputInterface $output Command output object.
	 * @return int
	 */
	protected function destroy( InputInterface $input, OutputInterface $output ) {
		$helper = $this->getHelper( 'question' );
		$question = new ConfirmationQuestion( 'Are you sure you want to destroy the server? [y/N] ', false );
		if ( ! $helper->ask( $input, $output, $question ) ) {
			return false;
		}

		$output->writeln( '<error>Destroying...</>' );

		$compose = new Process( 'docker-compose down -v', 'vendor/humanmade/local-vip/docker', $this->get_env() );
		$return_val = $compose->run( function ( $type, $buffer ) {
			echo $buffer;
		} );

		if ( $return_val === 0 ) {
			$output->writeln( '<error>Destroyed.</>' );
		} else {
			$output->writeln( '<error>Failed to destroy services.</>' );
		}

		return $return_val;
	}

	/**
	 * Restart the application.
	 *
	 * @param InputInterface $input Command input object.
	 * @param OutputInterface $output Command output object.
	 * @return int
	 */
	protected function restart( InputInterface $input, OutputInterface $output ) {
		$output->writeln( '<info>Restarting...</>' );

		$options = $input->getArgument( 'options' );
		if ( isset( $options[0] ) ) {
			$service = $options[0];
		} else {
			$service = '';
		}
		$compose = new Process( "docker-compose restart $service", 'vendor/humanmade/local-vip/docker', $this->get_env() );
		$return_val = $compose->run( function ( $type, $buffer ) {
			echo $buffer;
		} );

		if ( $return_val === 0 ) {
			$output->writeln( '<info>Restarted.</>' );
		} else {
			$output->writeln( '<error>Failed to restart services.</>' );
		}

		return $return_val;
	}

	/**
	 * Execute a command on the PHP container.
	 *
	 * @param InputInterface $input Command input object.
	 * @param OutputInterface $output Command output object.
	 * @param string|null $program The default program to pass input to.
	 * @return int
	 */
	protected function exec( InputInterface $input, OutputInterface $output, ?string $program = null ) {
		$site_url = 'https://' . $this->get_project_hostname();
		$options = $input->getArgument( 'options' );

		$passed_url = false;
		foreach ( $options as $option ) {
			if ( strpos( $option, '--url=' ) === 0 ) {
				$passed_url = true;
				break;
			}
		}

		if ( ! $passed_url && $program === 'wp' ) {
			$options[] = '--url=' . $site_url;
		}

		// Escape all options. Because the shell is going to strip the
		// initial escaping like "My string" => My String, then we need
		// to reapply escaping.
		foreach ( $options as &$option ) {
			if ( ! strpos( $option, '=' ) ) {
				if ( strpos( $option, '--' ) === 0 ) {
					continue;
				}
				$option = escapeshellarg( $option );
			} else {
				$arg = strtok( $option, '=' );
				$option = $arg . '=' . escapeshellarg( substr( $option, strlen( $arg ) + 1 ) );
			}
		}

		$container_id = exec( sprintf( 'docker ps --filter name=%s_php_1 -q', $this->get_project_name() ) );
		if ( ! $container_id ) {
			$output->writeln( '<error>PHP container not found to run command.</>' );
			return 1;
		}

		$columns = exec( 'tput cols' );
		$lines = exec( 'tput lines' );
		$has_stdin = ! posix_isatty( STDIN );
		$has_stdout = ! posix_isatty( STDOUT );
		$command = sprintf(
			'docker exec -e COLUMNS=%d -e LINES=%d -u www-data %s %s %s %s',
			$columns,
			$lines,
			( ! $has_stdin && ! $has_stdout ) && $program === 'wp' ? '-ti' : '', // forward wp-cli's isPiped detection.
			$container_id,
			$program ?? '',
			implode( ' ', $options )
		);

		passthru( $command, $return_val );

		return $return_val;
	}

	/**
	 * Get the status of all application containers.
	 *
	 * @param InputInterface $input Command input object.
	 * @param OutputInterface $output Command output object.
	 * @return int
	 */
	protected function status( InputInterface $input, OutputInterface $output ) {
		$compose = new Process( 'docker-compose ps', 'vendor/humanmade/local-vip/docker', $this->get_env() );
		return $compose->run( function ( $type, $buffer ) {
			echo $buffer;
		} );
	}

	/**
	 * Fetch the logs for a given container.
	 *
	 * @param InputInterface $input Command input object.
	 * @param OutputInterface $output Command output object.
	 * @return int
	 */
	protected function logs( InputInterface $input, OutputInterface $output ) {
		$log = $input->getArgument( 'options' )[0];
		$compose = new Process( 'docker-compose logs --tail=100 -f ' . $log, 'vendor/humanmade/local-vip/docker', $this->get_env() );
		$compose->setTimeout( 0 );
		return $compose->run( function ( $type, $buffer ) {
			echo $buffer;
		} );
	}

	/**
	 * SSH into the PHP container.
	 *
	 * @param InputInterface $input Command input object.
	 * @param OutputInterface $output Command output object.
	 * @return int
	 */
	protected function shell( InputInterface $input, OutputInterface $output ) {
		$columns = exec( 'tput cols' );
		$lines = exec( 'tput lines' );
		$command_prefix = $this->get_base_command_prefix();
		passthru( sprintf(
			"$command_prefix docker-compose exec -e COLUMNS=%d -e LINES=%d php /bin/bash",
			$columns,
			$lines
		), $return_val );

		return $return_val;
	}

	/**
	 * Access the database or database connection information.
	 *
	 * @param InputInterface $input Command input object.
	 * @param OutputInterface $output Command output object.
	 * @return int
	 */
	protected function db( InputInterface $input, OutputInterface $output ) {
		$db = $input->getArgument( 'options' )[0] ?? null;
		$columns = exec( 'tput cols' );
		$lines = exec( 'tput lines' );

		$base_command_prefix = $this->get_base_command_prefix();

		$base_command = sprintf(
			"$base_command_prefix docker-compose exec -e COLUMNS=%d -e LINES=%d db",
			$columns,
			$lines
		);

		$return_val = 0;

		switch ( $db ) {
			case 'info':
				$connection_data = $this->get_db_connection_data();

				$db_info = <<<EOT
<info>Root password</info>:  ${connection_data['MYSQL_ROOT_PASSWORD']}

<info>Database</info>:       ${connection_data['MYSQL_DATABASE']}
<info>User</info>:           ${connection_data['MYSQL_USER']}
<info>Password</info>:       ${connection_data['MYSQL_PASSWORD']}

<info>Host</info>:           ${connection_data['HOST']}
<info>Port</info>:           ${connection_data['PORT']}

<comment>Version</comment>:        ${connection_data['MARIADB_VERSION']}

EOT;
				$output->write( $db_info );
				break;
			case 'sequel':
				if ( php_uname( 's' ) !== 'Darwin' ) {
					$output->writeln( '<error>This command is only supported on MacOS, use composer server db info to see the database connection details.</error>' );
					return 1;
				}

				$connection_data = $this->get_db_connection_data();
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$spf_file_contents = file_get_contents( dirname( __DIR__, 2 ) . '/templates/sequel.xml' );
				foreach ( $connection_data as $field_name => $field_value ) {
					$spf_file_contents = preg_replace( "/(<%=\s)($field_name)(\s%>)/i", $field_value, $spf_file_contents );
				}
				$output_file_path = sprintf( '/tmp/%s.spf', $this->get_project_name() );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
				file_put_contents( $output_file_path, $spf_file_contents );

				exec( "open $output_file_path", $null, $return_val );
				if ( $return_val !== 0 ) {
					$output->writeln( '<error>You must have Sequel Pro (https://www.sequelpro.com) installed to use this command</error>' );
				}

				break;
			case null:
				// phpcs:ignore WordPress.WP.CapitalPDangit.Misspelled
				passthru( "$base_command mysql --database=wordpress --user=root -pwordpress", $return_val );
				break;
			default:
				$output->writeln( "<error>The subcommand $db is not recognized</error>" );
				$return_val = 1;
		}

		return $return_val;
	}

	/**
	 * Return the Database connection details.
	 *
	 * @return array
	 */
	private function get_db_connection_data() {
		$command_prefix = $this->get_base_command_prefix();
		$columns = exec( 'tput cols' );
		$lines = exec( 'tput lines' );

		$base_command = sprintf(
			"$command_prefix docker-compose exec -e COLUMNS=%d -e LINES=%d db",
			$columns,
			$lines
		);

		// Env variables.
		$env_variables = preg_split( '/\r\n|\r|\n/', shell_exec( "$base_command printenv" ) );
		$values = array_reduce( $env_variables, function( $values, $env_variable_text ) {
			$env_variable = explode( '=', $env_variable_text );
			$values[ $env_variable[0] ] = $env_variable[1] ?? '';
			return $values;
		}, [] );

		$keys = [
			'MYSQL_ROOT_PASSWORD',
			'MYSQL_PASSWORD',
			'MYSQL_USER',
			'MYSQL_DATABASE',
			'MYSQL_VERSION',
		];

		array_walk( $values, function ( $value, $key ) use ( $keys ) {
			return in_array( $key, $keys, true ) ? $value : false;
		} );

		$db_container_id = shell_exec( "$command_prefix docker-compose ps -q db" );

		// Retrieve the forwarded ports using Docker and the container ID.
		$ports = shell_exec( sprintf( "$command_prefix docker ps --format '{{.Ports}}' --filter id=%s", $db_container_id ) );
		preg_match( '/([\d.]+):([\d]+)->.*/', $ports, $ports_matches );

		return array_merge(
			array_filter( $values ),
			[
				'HOST' => $ports_matches[1],
				'PORT' => $ports_matches[2],
			]
		);
	}

	/**
	 * Get the local-vip configuration object from the composer.json
	 *
	 * @return array
	 */
	protected function get_server_config() : array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$composer_json = json_decode( file_get_contents( getcwd() . '/composer.json' ), true );
		if ( isset( $composer_json['extra']['local-vip'] ) ) {
			return $composer_json['extra']['local-vip'];
		}
		return [];
	}

	/**
	 * Get the name of the project
	 *
	 * @return string
	 */
	protected function get_project_name() : string {
		$server_config = $this->get_server_config();

		if ( isset( $server_config['name'] ) ) {
			$project_name = $server_config['name'];
		} else {
			$project_name = basename( getcwd() );
		}

		return preg_replace( '/[^A-Za-z0-9\-\_]/', '', $project_name );
	}

	/**
	 * Get the full hostname of the project
	 *
	 * @return string
	 */
	protected function get_project_hostname() : string {
		$server_config = $this->get_server_config();

		if ( isset( $server_config['host'] ) ) {
			return preg_replace( '/[^A-Za-z0-9\-\_.]/', '', $server_config['host'] );
		}

		// Use {configured project name or project directory name}.local as fallback.
		return $this->get_project_name() . '.local';
	}
}
