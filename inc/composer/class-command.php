<?php
/**
 * Local VIP Composer Command.
 *
 * phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
 * phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
 * phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
 * phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.system_calls_passthru
 *
 * @package humanmade/local-vip
 */

namespace HM\Local_VIP\Composer;

use Composer\Command\BaseCommand;
use Composer\Composer;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
				new InputArgument( 'subcommand', null, 'start, stop, restart, cli, exec, shell, status, db, ssl, set, logs.' ),
				new InputArgument( 'options', InputArgument::IS_ARRAY ),
			] )
			->setAliases( [ 'local-server', 'local-vip' ] )
			->setHelp(
				<<<EOT
Run the local development server.

Default command - start the local development server:
	start [--xdebug=<mode>] [--mutagen]
	                              Passing --xdebug starts the server with xdebug enabled
	                              optionally set the xdebug mode by assigning a value.
	                              Passing --mutagen will start the server using Mutagen
	                              for file sharing.
Stop the local development server or specific service:
	stop [<service>] [--clean]                passing --clean will also stop the proxy container
Restart the local development server:
	restart [--xdebug=<mode>] [<service>]     passing --xdebug restarts the server with xdebug enabled
Destroy the local development server:
	destroy [--clean]                         passing --clean will also destroy the proxy container
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
	db exec -- "<query>"          Run and output the result of a SQL query.
SSL commands:
	ssl                           Show status on generated SSL certificates
	ssl install                   Installs and trusts Root Certificate Authority
	ssl generate [domains]        Generate SSL certificates for configured domains
	ssl exec -- "command"         Executes an arbitrary mkcert command
View the logs
	logs <service>                <service> can be php, nginx, db, elasticsearch, xray
EOT
			)
			->addOption( 'xdebug', null, InputOption::VALUE_OPTIONAL, 'Start the server with Xdebug', 'debug' )
			->addOption( 'mutagen', null, InputOption::VALUE_NONE, 'Start the server with Mutagen file sharing' )
			->addOption( 'clean', null, InputOption::VALUE_NONE, 'Remove or stop the proxy container when destroying or stopping the server' );

		require getcwd() . '/vendor/autoload.php';
	}

	/**
	 * Whether the command is proxied.
	 *
	 * @return boolean
	 */
	public function isProxyCommand() {
		return true;
	}

	/**
	 * Get the common docker-compose command prefix.
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

		// Collect args to pass to the docker compose file generator.
		$settings = [
			'xdebug' => 'off',
			'mutagen' => 'off',
			'secure' => $this->get_composer_config()['secure'] ?? true,
		];

		// If Xdebug switch is passed add to docker compose args.
		if ( $input->hasParameterOption( '--xdebug' ) ) {
			$settings['xdebug'] = $input->getOption( 'xdebug' );
		}

		// Use mutagen if available.
		if ( $input->hasParameterOption( '--mutagen' ) ) {
			if ( $this->is_mutagen_installed() ) {
				$settings['mutagen'] = 'on';
			} else {
				$output->writeln( '<error>Mutagen Beta is not installed.</>' );
				$output->writeln( '<info>For installation instructions see the Development Channel section here https://mutagen.io/documentation/introduction/installation.</>' );
				return 1;
			}
		}

		// Refresh the docker-compose.yml file.
		$this->generate_docker_compose( $settings );

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
		} elseif ( $subcommand === 'ssl' ) {
			return $this->ssl( $input, $output );
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
			'COMPOSE_PROJECT_TLD' => $this->get_project_tld(),
			'DOCKER_CLIENT_TIMEOUT' => 120,
			'COMPOSE_HTTP_TIMEOUT' => 120,
			'PATH' => getenv( 'PATH' ),
			'ES_MEM_LIMIT' => getenv( 'ES_MEM_LIMIT' ) ?: '1g',
			'HOME' => getenv( 'HOME' ),
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
	 * @return int
	 */
	protected function start( InputInterface $input, OutputInterface $output ) {
		$output->writeln( '<info>Starting...</>' );

		// Check for changed project name.
		$tld = $this->get_project_tld();
		$name = $this->get_project_name();
		$host = @file_get_contents( 'vendor/host' );
		$is_new_host = $host && ( $host !== "$name.$tld" );

		// Halt if the project name is changed, to avoid orphan containers.
		if ( $is_new_host ) {
			$output->writeln( '<error>Detected changed domain, proceeding will result in orphan containers. Please revert the name change and destroy older containers before moving on.</error>' );
			return 1;
		}

		// Generate SSL certificate if not found.
		if ( ! file_exists( 'vendor/ssl-cert.pem' ) ) {
			// Create the certificate programmatically.
			$not_generated = $this->getApplication()->find( 'local-server' )->run( new ArrayInput( [
				'subcommand' => 'ssl',
				'options' => [
					'generate',
					'*.vip.local', // default domain, configured names will be automatically added.
				],
			] ), $output );

			if ( $not_generated ) {
				return $not_generated;
			}
		}

		// Save a reference to the host for later runs.
		file_put_contents( 'vendor/host', "$name.$tld" );

		$proxy = $this->process( $this->get_compose_command( '-f proxy.yml up -d' ), 'vendor/humanmade/local-vip/docker' );
		$proxy->setTimeout( 0 );
		$proxy->setTty( posix_isatty( STDOUT ) );
		$proxy_failed = $proxy->run( function ( $type, $buffer ) {
			echo $buffer;
		} );

		if ( $proxy_failed ) {
			$output->writeln( '<error>Could not start traefik proxy.</>' );
			return $proxy_failed;
		}

		$env = $this->get_env();

		$compose = $this->process( $this->get_compose_command( 'up -d --remove-orphans', true ), 'vendor/humanmade/local-vip/docker', $env );
		$compose->setTty( posix_isatty( STDOUT ) );
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
			$hostname = $this->get_project_domain() . $this->get_project_tld();

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

		$site_url = $this->get_project_url();
		$output->writeln( '<info>Startup completed.</>' );
		$output->writeln( '<info>To access your site visit:</> <comment>' . $site_url . '</>' );

		$this->check_host_entries( $input, $output );

		// Symfony complains if we don't return an integer.
		return 0;
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

		$options = $input->getArgument( 'options' );
		if ( isset( $options[0] ) ) {
			$service = $options[0];
		} else {
			$service = '';
		}

		$compose = $this->process( $this->get_compose_command( "stop $service", true ), 'vendor/humanmade/local-vip/docker', $this->get_env() );
		$compose->setTimeout( 0 );
		$compose->setTty( posix_isatty( STDOUT ) );
		$return_val = $compose->run( function ( $type, $buffer ) {
			echo $buffer;
		} );

		if ( $service === '' && $input->hasParameterOption( '--clean' ) ) {
			$output->writeln( '<info>Stopping proxy container...</>' );
			$proxy = $this->process( $this->get_compose_command( '-f proxy.yml stop' ), 'vendor/humanmade/local-vip/docker' );
			$proxy->setTimeout( 0 );
			$proxy->setTty( posix_isatty( STDOUT ) );
			$proxy->run( function ( $type, $buffer ) {
				echo $buffer;
			} );
		}

		if ( $return_val === 0 ) {
			$output->writeln( '<info>Stopped.</>' );
		} else {
			$output->writeln( '<error>Failed to stop service(s).</>' );
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

		$compose = $this->process( $this->get_compose_command( 'down -v --remove-orphans', true ), 'vendor/humanmade/local-vip/docker', $this->get_env() );
		$compose->setTty( posix_isatty( STDOUT ) );
		$return_val = $compose->run( function ( $type, $buffer ) {
			echo $buffer;
		} );

		// Check whether to remove the proxy container too.
		$remove_proxy = $input->hasParameterOption( '--clean' );
		if ( ! $remove_proxy ) {
			$question = new ConfirmationQuestion( "Do you want to remove the shared proxy container too?\n<comment>Warning:</> Only do this if you have no other instances of Local Server. [y/N] ", false );
			if ( $helper->ask( $input, $output, $question ) ) {
				$remove_proxy = true;
			}
		}

		if ( $remove_proxy ) {
			$output->writeln( '<error>Destroying proxy container...</>' );
			$proxy = $this->process( $this->get_compose_command( '-f proxy.yml down -v' ), 'vendor/humanmade/local-vip/docker' );
			$proxy->setTty( posix_isatty( STDOUT ) );
			$proxy->run( function ( $type, $buffer ) {
				echo $buffer;
			} );
		}

		if ( $return_val === 0 ) {
			$output->writeln( '<error>Destroyed.</>' );
		} else {
			$output->writeln( '<error>Failed to destroy services.</>' );
		}

		// Remove the host reference file, and SSL certificate and key.
		@unlink( 'vendor/host' );
		@unlink( 'vendor/ssl-cert.pem' );
		@unlink( 'vendor/ssl-key.pem' );

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

		$proxy = $this->process( $this->get_compose_command( '-f proxy.yml restart' ), 'vendor/humanmade/local-vip/docker' );
		$proxy->setTty( posix_isatty( STDOUT ) );
		$proxy->run( function ( $type, $buffer ) {
			echo $buffer;
		} );

		$options = $input->getArgument( 'options' );
		if ( isset( $options[0] ) ) {
			$service = $options[0];
		} else {
			$service = '';
		}
		$compose = $this->process( $this->get_compose_command( "restart $service", true ), 'vendor/humanmade/local-vip/docker', $this->get_env() );
		$compose->setTty( posix_isatty( STDOUT ) );
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
		$site_url = $this->get_project_url();
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

		$container_id = exec( sprintf( 'docker ps --filter name=%s-php -q', $this->get_project_name() ) );
		if ( ! $container_id ) {
			$output->writeln( '<error>PHP container not found to run command.</>' );
			$output->writeln( '<info>You may need to run `composer server start` again if you have recently updated Docker.</>' );
			return 1;
		}

		$columns = exec( 'tput cols' );
		$lines = exec( 'tput lines' );
		$has_stdin = ! posix_isatty( STDIN );
		$has_stdout = ! posix_isatty( STDOUT );
		if ( self::is_linux() ) {
			$user = posix_getuid();
		} else {
			$user = 'www-data';
		}
		$command = sprintf(
			'docker exec -e COLUMNS=%d -e LINES=%d -u %s %s %s %s %s',
			$columns,
			$lines,
			$user,
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
		$compose = $this->process( $this->get_compose_command( 'ps' ), 'vendor/humanmade/local-vip/docker', $this->get_env() );
		$compose->setTty( posix_isatty( STDOUT ) );
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
		$compose = $this->process( $this->get_compose_command( 'logs --tail=100 -f ' . $log ), 'vendor/humanmade/local-vip/docker', $this->get_env() );
		$compose->setTty( posix_isatty( STDOUT ) );
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

		$php_container_id = shell_exec( sprintf(
			'%s %s',
			$command_prefix,
			$this->get_compose_command( 'ps -q php' )
		) );

		passthru( sprintf(
			"$command_prefix %s exec -it -e COLUMNS=%d -e LINES=%d %s /bin/bash",
			'docker',
			$columns,
			$lines,
			trim( $php_container_id )
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

		$base_command = sprintf(
			// phpcs:ignore WordPress.WP.CapitalPDangit.Misspelled
			'docker exec -it -u root -e COLUMNS=%d -e LINES=%d -e MYSQL_PWD=wordpress %s-db',
			$columns,
			$lines,
			$this->get_project_name()
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

<comment>Version</comment>:        ${connection_data['MYSQL_MAJOR']}
<comment>MySQL link</comment>:     mysql://${connection_data['MYSQL_USER']}:${connection_data['MYSQL_PASSWORD']}@${connection_data['HOST']}:${connection_data['PORT']}/${connection_data['MYSQL_DATABASE']}

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
			case 'exec':
				$query = $input->getArgument( 'options' )[1] ?? null;
				if ( empty( $query ) ) {
					$output->writeln( '<error>No query specified: pass a query via `db exec -- "sql query..."`</error>' );
					break;
				}
				if ( substr( $query, -1 ) !== ';' ) {
					$query = "$query;";
				}
				// phpcs:ignore WordPress.WP.CapitalPDangit.Misspelled
				passthru( "$base_command mysql --database=wordpress --user=root -e \"$query\"", $return_val );
				break;
			case null:
				// phpcs:ignore WordPress.WP.CapitalPDangit.Misspelled
				passthru( "$base_command mysql --database=wordpress --user=root", $return_val );
				break;
			default:
				$output->writeln( "<error>The subcommand $db is not recognized</error>" );
				$return_val = 1;
		}

		return $return_val;
	}

	/**
	 * Generate SSL certificates for development environment.
	 *
	 * @param InputInterface $input Command input object.
	 * @param OutputInterface $output Command output object.
	 * @return int
	 */
	protected function ssl( InputInterface $input, OutputInterface $output ) {
		$subcommand = $input->getArgument( 'options' )[0] ?? null;

		$mkcert = $this->get_mkcert_binary();

		if ( $subcommand !== 'install' && ! $mkcert ) {
			// Install mkcert programmatically if not yet available.
			$not_installed = $this->getApplication()->find( 'local-server' )->run( new ArrayInput( [
				'subcommand' => 'ssl',
				'options' => [
					'install',
				],
			] ), $output );

			$mkcert = $this->get_mkcert_binary();

			if ( $not_installed || ! $mkcert ) {
				$output->writeln( "<error>mkcert could not be installed automatically, trying running 'composer server ssl install' manually to install and set it up.</error>" );
				return $not_installed;
			}
		}

		switch ( $subcommand ) {
			case 'install':
				// Detect platform architecture to attempt automatic installation.
				$os = php_uname( 's' ); // 'Darwin', 'Linux', 'Windows'
				$arch = php_uname( 'm' ); // 'arm64' for arm, 'x86_64' or 'amd64' for x64
				$mkcert_version = 'v1.4.3';

				switch ( $os ) {
					case 'Darwin':
						$binary_arch = ( $arch === 'x86_64' ) ? 'darwin-amd64' : 'darwin-arm64';
						break;
					case 'Linux':
						$binary_arch = ( $arch === 'amd64' || $arch === 'x86_64' ) ? 'linux-amd64' : 'linux-arm64';
						break;
					case self::is_wsl():
						$binary_arch = 'windows-amd64.exe';
						break;
					default:
						$binary_arch = null;
						break;
				}

				// If couldn't detect a support architecture, ask the user to install mkcert manually.
				if ( ! $binary_arch ) {
					$output->writeln( '<error>This command is only supported on macOS, Linux, and Windows x64, install `mkcert` manually for other systems.</error>' );
					$output->writeln( '<error>Download and setup `mkcert` from https://github.com/FiloSottile/mkcert </error>' );
					return 1;
				}

				$binary = "mkcert-$mkcert_version-$binary_arch";
				$mkcert = 'vendor/mkcert';

				// Check if mkcert is installed globally already, bail if so.
				$version = trim( shell_exec( 'mkcert -version' ) );
				if ( $version ) {
					$output->writeln( "<error>mkcert $version is installed globally already</error>" );
					return 1;
				}

				// Check if mkcert is installed locally already, bail if so.
				$version = trim( shell_exec( "$mkcert -version" ) );
				if ( $version ) {
					$output->writeln( "<error>mkcert $version is installed locally already</error>" );
					return 1;
				}

				$output->writeln( "Detected system architecture to be $os $arch" );
				$output->writeln( "Downloading https://github.com/FiloSottile/mkcert/releases/download/$mkcert_version/$binary to $mkcert ..." );
				exec( "curl -o $mkcert -L https://github.com/FiloSottile/mkcert/releases/download/$mkcert_version/$binary", $dummy, $result );
				if ( $result ) {
					$output->writeln( '<error>Could not download mkcert binary, try using sudo or manually installing mkcert.</error>' );
					$output->writeln( '<error>Download and setup `mkcert` from https://github.com/FiloSottile/mkcert </error>' );
					return 1;
				}

				$output->writeln( "<info>mkcert $mkcert_version was downloaded.</info>" );

				chmod( $mkcert, 0755 );

				exec( "$mkcert -version", $dummy, $result );
				if ( $result ) {
					$output->writeln( '<error>Could not launch mkcert binary, try manually installing mkcert.</error>' );
					$output->writeln( '<error>Download and setup `mkcert` from https://github.com/FiloSottile/mkcert </error>' );
					return 1;
				}
				$output->writeln( "<info>mkcert $mkcert_version was installed.</info>" );

				// Setup and accept the root certificate.
				exec( "$mkcert -install", $dummy, $result );
				if ( $result ) {
					$output->writeln( '<error>Could not setup `mkcert` properly, try manually installing it.</error>' );
					$output->writeln( '<error>Download and setup `mkcert` from https://github.com/FiloSottile/mkcert </error>' );
					return 1;
				}

				$output->writeln( '<info>mkcert root CA was installed and accepted successfully.</info>' );
				break;
			case 'generate':
				$config = $this->get_composer_config();

				$tld = $this->get_project_tld();
				$domains = explode( ' ', $input->getArgument( 'options' )[1] ?? '' );
				$extra_domains = $config['domains'] ?? [];

				$domains[] = $tld;
				$domains[] = '*.' . $tld;

				$domains = array_merge( $domains, [ '*.vip.local' ], $extra_domains );

				$cert_domains = implode( ' ', array_filter( array_unique( $domains ) ) );

				exec( "$mkcert -cert-file vendor/ssl-cert.pem -key-file vendor/ssl-key.pem $cert_domains", $dummy, $result );

				if ( $result ) {
					$output->writeln( '<error>Could not generate certificates! Try generating them manually using `mkcert`.</error>' );
					$output->writeln( "<error>Command should be: 'mkcert -cert-file vendor/ssl-cert.pem -key-file vendor/ssl-key.pem $cert_domains'</error>" );
					return 1;
				}

				$output->writeln( '<info>Generated SSL certificate successfully.</info>' );

				// Restart proxy container if running.
				exec( 'docker ps | grep altis-proxy', $result );
				if ( $result ) {
					$output->writeln( '<info>Restarting proxy server to activate the new certificate...</info>' );
					$proxy = $this->process( $this->get_compose_command( '-f proxy.yml restart' ), 'vendor/humanmade/local-vip/docker' );
					$proxy->setTty( posix_isatty( STDOUT ) );
					$proxy->run( function ( $type, $buffer ) {
						echo $buffer;
					} );
				}

				break;

			case 'exec':
				$command = $input->getArgument( 'options' )[1] ?? null;
				exec( "$mkcert $command", $exec_output, $result );

				if ( $result ) {
					$output->writeln( "<error>$exec_output</error>" );
					return 1;
				} else {
					$output->writeln( $exec_output );
				}

				break;

			case '':
				$cert_exists = file_exists( 'vendor/ssl-cert.pem' ) && file_exists( 'vendor/ssl-key.pem' );
				if ( ! $cert_exists ) {
					$output->writeln( "<error>Certificate file does not exist. Use 'composer server ssl generate' to generate one. </error>" );
					return 1;
				} else {
					$output->writeln( '<info>Certificate file exists.</info>' );
				}

				break;

			default:
				$output->writeln( "<error>The subcommand $subcommand is not recognized</error>" );
				return 1;
		}
		return 0;
	}

	/**
	 * Retrieves path to the working copy of mkcert.
	 *
	 * @return string|null Path to the mkcert binary or false if not found.
	 */
	protected function get_mkcert_binary() : ?string {
		$mkcert = 'vendor/mkcert';

		// Check if mkcert is installed globally already, bail if so.
		$version = trim( shell_exec( 'mkcert -version' ) );
		if ( $version ) {
			return 'mkcert';
		}

		// Check if mkcert is installed locally already, bail if so.
		$version = trim( shell_exec( "$mkcert -version" ) );
		if ( $version ) {
			return $mkcert;
		}

		return null;
	}

	/**
	 * Check and notify about required /etc/hosts entries.
	 *
	 * @param InputInterface $input Command input object.
	 * @param OutputInterface $output Command output object.
	 */
	protected function check_host_entries( InputInterface $input, OutputInterface $output ) : void {
		$config = $this->get_composer_config();

		$hostname = $this->get_project_tld();
		$extra_domains = $config['domains'] ?? [];
		$sites = array_map( function ( $subdomain ) use ( $hostname ) {
			return "$subdomain.$hostname";
		}, array_keys( $config['sites'] ?? [] ) );

		$domains = array_merge( [
			$hostname,
			"elasticsearch-$hostname",
		], $extra_domains, $sites );

		$failed = [];
		foreach ( $domains as $domain ) {
			$ip = gethostbyname( $domain );
			if ( $ip === $domain ) {
				$failed[] = $domain;
			}
		}

		if ( ! $failed ) {
			return;
		}

		$output->writeln( sprintf( '<error>Missing hosts entries for: %s</error>', implode( ', ', $failed ) ) );
		$output->writeln( 'Add the following line to your /etc/hosts file:' . "\n" );
		$output->writeln( sprintf( '127.0.0.1 %s # local-vip:%s', implode( ' ', $domains ), $hostname ) );
	}

	/**
	 * Generates the docker-compose.yml file.
	 *
	 * @param array $args An optional array of arguments to pass through to the generator.
	 * @return void
	 */
	protected function generate_docker_compose( array $args = [] ) : void {
		$defaults = [
			'subdomain_install' => ( $this->get_server_config()['subdomains'] ?? false ) ? 1 : 0
		];

		$args = array_merge( $args, $defaults );

		$docker_compose = new Docker_Compose_Generator(
			$this->get_project_name(),
			$this->get_project_domain(),
			getcwd(),
			$this->get_project_tld(),
			$args
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
		file_put_contents(
			getcwd() . DIRECTORY_SEPARATOR . 'vendor/humanmade/local-vip/docker' . DIRECTORY_SEPARATOR . 'docker-compose.yml',
			$docker_compose->get_yaml()
		);
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
			"$command_prefix %s exec -e COLUMNS=%d -e LINES=%d -T db",
			$this->get_compose_command(),
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
			'MYSQL_MAJOR',
		];

		array_walk( $values, function ( $value, $key ) use ( $keys ) {
			return in_array( $key, $keys, true ) ? $value : false;
		} );

		$db_container_id = shell_exec( sprintf(
			'%s %s',
			$command_prefix,
			$this->get_compose_command( 'ps -q db' )
		) );

		// Retrieve the forwarded ports using Docker and the container ID.
		$ports = shell_exec( sprintf( "$command_prefix docker ps --format '{{.Ports}}' --filter id=%s", $db_container_id ) );
		preg_match( '/([\d.]+):([\d]+)->.*/', trim( $ports ), $ports_matches );

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
	 * Get the name of the project for the local domain
	 *
	 * @return string
	 */
	protected function get_project_domain() : string {
		$server_config = $this->get_server_config();

		if ( isset( $server_config['domain'] ) ) {
			$project_domain = strtok( $server_config['domain'], '.' );
		} else {
			$project_domain = $this->get_project_name();
		}

		return preg_replace( '/[^A-Za-z0-9\-\_]/', '', $project_domain );
	}

	/**
	 * Get the config from the composer.json project file.
	 *
	 * @return array
	 */
	protected function get_composer_config() : array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$composer_json = json_decode( file_get_contents( getcwd() . '/composer.json' ), true );
		$config = $composer_json['extra']['local-vip'] ?? [];

		return $config;
	}

	/**
	 * Get the root name to use for the project.
	 *
	 * @return string
	 */
	protected function get_project_tld() : string {
		$config = $this->get_composer_config();

		if ( isset( $config['domain'] ) ) {
			$project_name = $config['domain'];
		} else {
			$project_name = 'vip.local';
		}

		return $project_name;
	}

	/**
	 * Get the name of the project for the local subdomain
	 *
	 * @return string
	 */
	protected function get_project_url() : string {
		$is_secure = $this->get_composer_config()['secure'] ?? true;
		$site_url = sprintf(
			'http%s://%s/',
			$is_secure ? 's' : '',
			$this->get_project_tld()
		);
		return $site_url;
	}

	/**
	 * Run a prepared process command for various versions of Symfony Console.
	 *
	 * Console v5+ requires an array for the command.
	 * Console v1-3 only supports strings.
	 *
	 * @param mixed ...$args Args to pass to Process.
	 * @return Process
	 */
	protected function process( ...$args ) : Process {
		if ( version_compare( Composer::getVersion(), '2.3', '>=' ) && ! is_array( $args[0] ) ) {
			$args[0] = explode( ' ', $args[0] );
			$args[0] = array_filter( $args[0] );
		}

		return new Process( ...$args );
	}

	/**
	 * Check if the current host operating system is Linux based.
	 *
	 * @return boolean
	 */
	public static function is_linux() : bool {
		return in_array( php_uname( 's' ), [ 'BSD', 'Linux', 'Solaris', 'Unknown' ], true );
	}

	/**
	 * Check if the current host operating system is Mac OS.
	 *
	 * @return boolean
	 */
	public static function is_macos() : bool {
		return php_uname( 's' ) === 'Darwin';
	}

	/**
	 * Check if the current host is Windows.
	 *
	 * @return boolean
	 */
	public static function is_windows() : bool {
		return php_uname( 's' ) === 'Windows';
	}

	/**
	 * Check if the current host is WSL.
	 *
	 * @return boolean
	 */
	public static function is_wsl() : bool {
		return getenv( 'WSL_INTEROP' ) !== false;
	}

	/**
	 * Check if Mutagen is installed.
	 *
	 * @return boolean
	 */
	protected function is_mutagen_installed() : bool {
		static $is_installed;
		if ( $is_installed !== null ) {
			return $is_installed;
		}
		if ( self::is_linux() || self::is_macos() ) {
			$is_installed = ! empty( shell_exec( 'which mutagen' ) );
		} else {
			$is_installed = ! empty( shell_exec( 'where mutagen' ) );
		}
		return $is_installed;
	}

	/**
	 * Get the docker compose command to use.
	 *
	 * If Mutagen is active it is used for file sharing by default.
	 *
	 * @param string $command The command to append to the root compose command.
	 * @param bool $mutagen Whether to use Mutagen's compose wrapper.
	 * @return string
	 */
	protected function get_compose_command( string $command = '', bool $mutagen = false ) : string {
		static $default_command;
		if ( empty( $default_command ) ) {
			exec( 'docker compose', $output );
			$default_command = strpos( implode( "\n", $output ), 'Usage:  docker compose' ) !== false ? 'docker compose' : 'docker-compose';
		}
		return sprintf( '%s %s',
			$this->is_mutagen_installed() && $mutagen ? 'mutagen compose' : $default_command,
			$command
		);
	}

}
