<?php
/**
 * Local Server Docker Compose file generator.
 */

namespace HM\Local_VIP\Composer;

use Symfony\Component\Yaml\Yaml;

/**
 * Generates a docker compose file for Local VIP.
 */
class Docker_Compose_Generator {

	/**
	 * The Docker Compose project name.
	 *
	 * Commonly set via the `COMPOSE_PROJECT_NAME` environment variable.
	 *
	 * @var string
	 */
	protected $project_name;

	/**
	 * The Altis project root directory.
	 *
	 * @var string
	 */
	protected $root_dir;

	/**
	 * The docker-compose.yml directory.
	 *
	 * @var string
	 */
	protected $config_dir;

	/**
	 * The primary top level domain for the server.
	 *
	 * @var string
	 */
	protected $tld;

	/**
	 * The primary domain name for the project.
	 *
	 * @var string
	 */
	protected $hostname;

	/**
	 * An array of data passed to
	 *
	 * @var array
	 */
	protected $args;

	/**
	 * Create and configure the generator.
	 *
	 * @param string $project_name The docker compose project name.
	 * @param string $domain_name The docker compose domain name.
	 * @param string $root_dir The project root directory.
	 * @param string $tld The primary top level domain for the server.
	 * @param array $args An optional array of arguments to modify the behaviour of the generator.
	 */
	public function __construct( string $project_name, string $domain_name, string $root_dir, string $tld = 'vip.local', array $args = [] ) {
		$this->project_name = $project_name;
		$this->root_dir = $root_dir;
		$this->tld = $tld;
		$this->hostname = $this->tld;
		$this->config_dir = dirname( __DIR__, 2 ) . '/docker';
		$this->args = $args;
	}

	/**
	 * Get the PHP server configuration.
	 *
	 * @return array
	 */
	protected function get_php_reusable() : array {
		$version_map = [
			'8.2' => 'humanmade/altis-local-server-php:8.2.8',
			'8.1' => 'humanmade/altis-local-server-php:6.0.10',
			'8.0' => 'humanmade/altis-local-server-php:5.0.10',
			'7.4' => 'humanmade/altis-local-server-php:4.2.0',
		];

		$versions = array_keys( $version_map );
		$version = (string) $this->get_config()['php'];

		if ( ! in_array( $version, $versions, true ) ) {
			echo sprintf(
				"The configured PHP version \"%s\" is not supported.\nTry one of the following:\n  - %s\n",
				// phpcs:ignore HM.Security.EscapeOutput.OutputNotEscaped
				$version,
				// phpcs:ignore HM.Security.EscapeOutput.OutputNotEscaped
				implode( "\n  - ", $versions )
			);
			exit( 1 );
		}

		$image = $version_map[ $version ];

		$services = [
			'init' => true,
			'depends_on' => [
				'db' => [
					'condition' => 'service_healthy',
				],
				'mailhog' => [
					'condition' => 'service_started',
				],
				'memcached' => [
					'condition' => 'service_started',
				],
			],
			'image' => $image,
			'links' => [
				'db:db-read-replica',
			],
			'external_links' => [
				"proxy:{$this->hostname}",
				"proxy:elasticsearch-{$this->hostname}",
			],
			'volumes' => [
				$this->get_app_volume(),
				"{$this->config_dir}/php.ini:/usr/local/etc/php/conf.d/altis.ini",
				'socket:/var/run/php-fpm',
			],
			'networks' => [
				'proxy',
				'default',
			],
			'environment' => [
				'HOST_PATH' => $this->root_dir,
				'COMPOSE_PROJECT_NAME' => $this->hostname,
				'DB_HOST' => 'db',
				'DB_READ_REPLICA_HOST' => 'db-read-replica',
				'DB_PASSWORD' => 'wordpress',
				'DB_NAME' => 'wordpress',
				'DB_USER' => 'wordpress',
				'WP_DEBUG' => 1,
				'WP_DEBUG_DISPLAY' => 0,
				'PAGER' => 'more',
				'HM_ENV_ARCHITECTURE' => 'local-server',
				'HM_DEPLOYMENT_REVISION' => 'dev',
				'ELASTICSEARCH_HOST' => 'elasticsearch',
				'ELASTICSEARCH_PORT' => 9200,
				'AWS_XRAY_DAEMON_HOST' => 'xray',
				'PHP_SENDMAIL_PATH' => '/usr/sbin/sendmail -t -i -S mailhog:1025',
				// Enables XDebug for all processes and allows setting remote_host externally for Linux support.
				'XDEBUG_CONFIG' => sprintf(
					'client_host=%s',
					Command::is_linux() && ! Command::is_wsl() ? '172.17.0.1' : 'host.docker.internal'
				),
				'SUBDOMAIN_INSTALL' => $this->args['subdomain_install'],
				'PHP_IDE_CONFIG' => "serverName={$this->hostname}",
				'XDEBUG_SESSION' => $this->hostname,
				// Set XDebug mode, fall back to "off" to avoid any performance hits.
				'XDEBUG_MODE' => $this->args['xdebug'] ?? 'off',
				// Set up VIP specific constants to use Enterprise Search
				'VIP_ELASTICSEARCH_ENDPOINT' => 'http://elasticsearch:9200',
				'VIP_ELASTICSEARCH_USERNAME' => 'elastic',
				'VIP_ELASTICSEARCH_PASSWORD' => 'elasticadmin',
				'FILES_CLIENT_SITE_ID' => '123',
			],
		];

		if ($image !== 'humanmade/altis-local-server-php:4.2.0') {
			// Other images remove support for memcache.
			unset($services['depends_on']['memcached']);
			$services['depends_on']['redis'] = [
				'condition' => 'service_started',
			];
			$services['environment']['REDIS_HOST'] = 'redis';
			$services['environment']['REDIS_PORT'] = 6379;
		}

		if ( $this->get_config()['elasticsearch'] ) {
			$services['depends_on']['elasticsearch'] = [
				'condition' => 'service_healthy',
			];
		}

		return $services;
	}

	/**
	 * Get the PHP container service.
	 *
	 * @return array
	 */
	protected function get_service_php() : array {
		return [
			'php' => array_merge(
				[
					'container_name' => "{$this->project_name}-php",
				],
				$this->get_php_reusable()
			),
		];
	}

	/**
	 * Get the nginx service.
	 *
	 * @return array
	 */
	protected function get_service_nginx() : array {
		$config = $this->get_config();
		$domains = $config['domains'] ?? [];
		$domains = $domains ? ',' . implode( ',', $domains ) : '';

		return [
			'nginx' => [
				'image' => 'humanmade/altis-local-server-nginx:3.4.0',
				'container_name' => "{$this->project_name}-nginx",
				'networks' => [
					'proxy',
					'default',
				],
				'depends_on' => [
					'php',
				],
				'volumes' => [
					$this->get_app_volume(),
					'socket:/var/run/php-fpm',
				],
				'ports' => [
					'8080',
				],
				'labels' => [
					'traefik.frontend.priority=1',
					'traefik.port=8080',
					'traefik.protocol=https',
					'traefik.docker.network=proxy',
					"traefik.frontend.rule=HostRegexp:{$this->hostname},{subdomain:[a-z.-_]+}.{$this->hostname}{$domains}",
					"traefik.domain={$this->hostname},*.{$this->hostname}{$domains}",
				],
				'environment' => [
					// Gzip compression now defaults to off to support Brotli compression via CloudFront.
					'GZIP_STATUS' => 'on',
					// Increase read response timeout when debugging.
					'READ_TIMEOUT' => ( $this->args['xdebug'] ?? 'off' ) !== 'off' ? '9000s' : '60s',
				],
			],
		];
	}

	/**
	 * Get the DB service.
	 *
	 * @return array
	 */
	protected function get_service_db() : array {
		return [
			'db' => [
				'image' => $this->get_config()['db-image'],
				'container_name' => "{$this->project_name}-db",
				'volumes' => [
					'db-data:/var/lib/mysql',
				],
				'ports' => [
					'3306',
				],
				'environment' => [
					'MYSQL_ROOT_PASSWORD' => 'wordpress',
					'MYSQL_DATABASE' => 'wordpress',
					'MYSQL_USER' => 'wordpress',
					'MYSQL_PASSWORD' => 'wordpress',
				],
				'healthcheck' => [
					'test' => [
						'CMD',
						'mysqladmin',
						'ping',
						'-h',
						'localhost',
						'-u',
						'wordpress',
						'-pwordpress',
					],
					'timeout' => '5s',
					'interval' => '5s',
					'retries' => 10,
				],
			],
		];
	}

	/**
	 * Get the Memcached service.
	 *
	 * @return array
	 */
	protected function get_service_memcached() : array {
		return [
			'memcached' => [
				'image' => 'memcached',
				'container_name' => "{$this->project_name}-memcached",
				'restart' => 'always',
			],
		];
	}

	/**
	 * Get the Redis service.
	 *
	 * @return array
	 */
	protected function get_service_redis() : array {
		return [
			'redis' => [
				'image' => 'redis:3.2-alpine',
				'container_name' => "{$this->project_name}-redis",
				'ports' => [
					'6379',
				],
			],
		];
	}

	/**
	 * Get the Elasticsearch service.
	 *
	 * @return array
	 */
	protected function get_service_elasticsearch() : array {
		$mem_limit = getenv( 'ES_MEM_LIMIT' ) ?: '1g';

		$version_map = [
			'7.10' => 'humanmade/altis-local-server-elasticsearch:4.1.0',
			'7' => 'humanmade/altis-local-server-elasticsearch:4.1.0',
			'6.8' => 'humanmade/altis-local-server-elasticsearch:3.1.0',
			'6' => 'humanmade/altis-local-server-elasticsearch:3.1.0',
			'6.3' => 'humanmade/altis-local-server-elasticsearch:3.0.0',
		];

		$this->check_elasticsearch_version( array_keys( $version_map ) );

		$image = $version_map[ $this->get_elasticsearch_version() ];

		return [
			'elasticsearch' => [
				'image' => $image,
				'container_name' => "{$this->project_name}-es",
				'restart' => 'unless-stopped',
				'ulimits' => [
					'memlock' => [
						'soft' => -1,
						'hard' => -1,
					],
				],
				'mem_limit' => $mem_limit,
				'volumes' => [
					'es-data:/usr/share/elasticsearch/data',
					"{$this->root_dir}/wp-content/uploads/es-packages:/usr/share/elasticsearch/config/packages",
				],
				'ports' => [
					'9200',
				],
				'networks' => [
					'proxy',
					'default',
				],
				'healthcheck' => [
					'test' => [
						'CMD-SHELL',
						'curl --silent --fail localhost:9200/_cluster/health || exit 1',
					],
					'interval' => '5s',
					'timeout' => '5s',
					'retries' => 25,
				],
				'labels' => [
					'traefik.port=9200',
					'traefik.protocol=http',
					'traefik.docker.network=proxy',
					"traefik.frontend.rule=HostRegexp:elasticsearch-{$this->hostname}",
					"traefik.domain=elasticsearch-{$this->hostname}",
				],
				'environment' => [
					'http.max_content_length=10mb',
					// Force ES into single-node mode (otherwise defaults to zen discovery as
					// network.host is set in the default config).
					'discovery.type=single-node',
					// Use max container memory limit as the max JVM heap allocation value.
					"ES_JAVA_OPTS=-Xms512m -Xmx{$mem_limit}",
				],
			],
		];
	}

	/**
	 * Get the Kibana service.
	 *
	 * @return array
	 */
	protected function get_service_kibana() : array {

		$version_map = [
			'7.10' => 'humanmade/altis-local-server-kibana:1.1.1',
			'7' => 'humanmade/altis-local-server-kibana:1.1.1',
			'6.8' => 'blacktop/kibana:6.8',
			'6' => 'blacktop/kibana:6.8',
			'6.3' => 'blacktop/kibana:6.3',
		];

		$this->check_elasticsearch_version( array_keys( $version_map ) );

		$image = $version_map[ $this->get_elasticsearch_version() ];

		$yml_file = 'kibana.yml';
		if ( version_compare( $this->get_elasticsearch_version(), '7', '>=' ) ) {
			$yml_file = 'kibana-7.yml';
		}

		return [
			'kibana' => [
				'image' => $image,
				'container_name' => "{$this->project_name}-kibana",
				'networks' => [
					'proxy',
					'default',
				],
				'ports' => [
					'5601',
				],
				'labels' => [
					'traefik.port=5601',
					'traefik.protocol=http',
					'traefik.docker.network=proxy',
					"traefik.frontend.rule=Host:{$this->hostname};PathPrefix:/kibana",
				],
				'depends_on' => [
					'elasticsearch' => [
						'condition' => 'service_healthy',
					],
				],
				'volumes' => [
					"{$this->config_dir}/{$yml_file}:/usr/share/kibana/config/kibana.yml",
				],
			],
		];
	}

	/**
	 * Get the Mailhog service.
	 *
	 * @return array
	 */
	protected function get_service_mailhog() : array {
		return [
			'mailhog' => [
				'image' => 'cd2team/mailhog:1632011321',
				'container_name' => "{$this->project_name}-mailhog",
				'ports' => [
					'8025',
					'1025',
				],
				'networks' => [
					'proxy',
					'default',
				],
				'labels' => [
					'traefik.port=8025',
					'traefik.protocol=http',
					'traefik.docker.network=proxy',
					"traefik.frontend.rule=Host:{$this->hostname};PathPrefix:/mailhog",
				],
				'environment' => [
					'MH_UI_WEB_PATH' => 'mailhog',
				],
			],
		];
	}

	/**
	 * Get the XRay service.
	 *
	 * @return array
	 */
	protected function get_service_xray() : array {
		return [
			'xray' => [
				'image' => 'amazon/aws-xray-daemon:3.3.3',
				'container_name' => "{$this->project_name}-xray",
				'ports' => [
					'2000',
				],
				'environment' => [
					'AWS_ACCESS_KEY_ID' => 'YOUR_KEY_HERE',
					'AWS_SECRET_ACCESS_KEY' => 'YOUR_SECRET_HERE',
					'AWS_REGION' => 'us-east-1',
				],
			],
		];
	}

	/**
	 * Get the full docker compose configuration.
	 *
	 * @return array
	 */
	public function get_array() : array {
		$php = $this->get_service_php();
		$services = array_merge(
			$this->get_service_db(),
			isset( $php['php']['depends_on']['memcached'] )
				? $this->get_service_memcached()
				: $this->get_service_redis(),
			$php,
			$this->get_service_nginx()
		);

		if ( $this->get_config()['xray'] ) {
			$services = array_merge( $services, $this->get_service_xray() );
		}

		if ( $this->get_config()['elasticsearch'] ) {
			$services = array_merge( $services, $this->get_service_elasticsearch() );
		}

		$services = array_merge(
			$services,
			$this->get_service_mailhog()
		);

		if ( $this->get_config()['kibana'] && $this->get_config()['elasticsearch'] ) {
			$services = array_merge( $services, $this->get_service_kibana() );
		}

		// Default compose configuration.
		$config = [
			'version' => '2.3',
			'services' => $services,
			'networks' => [
				'default' => null,
				'proxy' => [
					'name' => 'proxy',
					'external' => true,
				],
			],
			'volumes' => [
				'db-data' => null,
				'es-data' => null,
				'tmp' => null,
				's3' => null,
				'socket' => null,
			],
		];

		// Handle mutagen volume according to args.
		if ( ! empty( $this->args['mutagen'] ) && $this->args['mutagen'] === 'on' ) {
			$config['volumes']['app'] = null;
			$config['x-mutagen'] = [
				'sync' => [
					'app' => [
						'alpha' => $this->root_dir,
						'beta' => 'volume://app',
						'configurationBeta' => [
							'permissions' => [
								'defaultOwner' => 'id:82',
								'defaultGroup' => 'id:82',
								'defaultFileMode' => '0664',
								'defaultDirectoryMode' => '0775',
							],
						],
						'mode' => 'two-way-resolved',
					],
				],
			];
			// Add ignored paths.
			if ( ! empty( $this->get_config()['ignore-paths'] ) ) {
				$config['x-mutagen']['sync']['app']['ignore'] = [
					'paths' => array_values( (array) $this->get_config()['ignore-paths'] ),
				];
			}
		}

		return $config;
	}

	/**
	 * Get Yaml output for config.
	 *
	 * @return string
	 */
	public function get_yaml() : string {
		return Yaml::dump( $this->get_array(), 10, 2 );
	}

	/**
	 * Get a module config from composer.json.
	 *
	 * @return array
	 */
	protected function get_config() : array {
		// @codingStandardsIgnoreLine
		$json = file_get_contents( $this->root_dir . DIRECTORY_SEPARATOR . 'composer.json' );
		$composer_json = json_decode( $json, true );

		$local_server = ( $composer_json['extra']['altis']['modules']['local-server'] ?? [] );
		$local_vip = ( $composer_json['extra']['local-vip'] ?? [] );
		$defaults = [
			'elasticsearch' => '7',
			'kibana' => true,
			'xray' => true,
			'ignore-paths' => [],
			'db-image' => 'biarms/mysql:5.7',
		];

		return array_merge( $defaults, $local_server, $local_vip );
	}

	/**
	 * Get the configured Elasticsearch version.
	 *
	 * @return int
	 */
	protected function get_elasticsearch_version() : string {
		if ( ! empty( $this->get_config()['elasticsearch'] ) ) {
			return (string) $this->get_config()['elasticsearch'];
		}

		return '7';
	}

	/**
	 * Check the configured Elasticsearch version in config.
	 *
	 * @param array $versions List of available version numbers.
	 * @return void
	 */
	protected function check_elasticsearch_version( array $versions ) {
		$versions = array_map( 'strval', $versions );
		rsort( $versions );
		if ( in_array( $this->get_elasticsearch_version(), $versions, true ) ) {
			return;
		}

		echo sprintf(
			"The configured elasticsearch version \"%s\" is not supported.\nTry one of the following:\n  - %s\n",
			// phpcs:ignore HM.Security.EscapeOutput.OutputNotEscaped
			$this->get_elasticsearch_version(),
			// phpcs:ignore HM.Security.EscapeOutput.OutputNotEscaped
			implode( "\n  - ", $versions )
		);
		exit( 1 );
	}

	/**
	 * Get the main application volume adjusted for sharing config options.
	 *
	 * @return string
	 */
	protected function get_app_volume() : string {
		if ( ! empty( $this->args['mutagen'] ) && $this->args['mutagen'] === 'on' ) {
			return 'app:/usr/src/app:delegated';
		}
		return "{$this->root_dir}:/usr/src/app:delegated";
	}
}
