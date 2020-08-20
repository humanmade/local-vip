# Local VIP

**Note:** Local VIP is a Docker-based environment. If you experience any issues running it consult the [Chassis](https://chassis.io) documentation for an alternative local environment.

Local VIP provides a local development environment for WordPress VIP projects. It is built on a containerized architecture using Docker images and Docker Compose to provide drop-in replacements or alternatives for most parts of the WordPress VIP platform.

Local VIP is forked from [Altis Local Server](https://www.altis-dxp.com/resources/docs/local-server/), and supports most of the same commands and interface.

## Installing

Local VIP uses Docker for containerization, therefore you must install the Docker runtime on your computer as a prerequisite. Download and install Docker for your OS at [https://www.docker.com/get-started](https://www.docker.com/get-started).

Once Docker is installed and running, you are ready to start the Local Server. Local VIP is controlled from the command line via the `composer` command.

## Starting the Local Server

To start the Local Server, run `composer server`. The first time you run this it will download all the necessary Docker images.

Once the initial download and install has completed, you should see the output:

```sh
Installed database.
WP Username:	admin
WP Password:	password
Startup completed.
To access your site visit: https://my-site.local/
```

Visiting your site's URL should now work. Visit `/wp-admin/` and login with the username `admin` and password `password` to get started!

> [If the server does not start for any reason take a look at the troubleshooting guide](./troubleshooting.md)

## Configuration

Server options can be configured via the `extra.local-vip` section in your composer.json:

```json
{
	"extra": {
		"local-vip": {
			"name": "my-project",
			"host": "mycompany.local",
			"subdomains": true,
			"sites": {
				"Subsite Title": "subsite-slug"
			}
		}
	}
}
```

### Configuration Options

- **name**: The name to use for the Docker containers and the default hostname. Defaults to the name of your project's root folder.
- **host**: The host name to use for your local site. Defaults to {name}.local.
- **subdomains**: Whether to set up new network sites as subdomains. Defaults to false, which causes sites to be created as subdirectories.
- **sites**: An array or object of sites to create on the network. Either pass an array of subsite slugs, or an object of Title: slug pairs.

## Available Commands

* `composer server start [--xdebug]` - Starts the containers.
  * If the `--xdebug` option is passed the PHP container will have XDebug enabled. To switch off XDebug run this command again without the `--xdebug` option.
* `composer server stop` - Stops the containers.
* `composer server restart` - Restart the containers.
* `composer server destroy` - Stops and destroys all containers.
* `composer server status` - Displays the status of all containers.
* `composer server logs <service>` - Tail the logs from a given service, defaults to `php`, available options are `nginx`, `php`, `db`, `redis`, `cavalcade`, `s3` and `elasticsearch`.
* `composer server shell` - Logs in to the PHP container.
* `composer server cli -- <command>` - Runs a WP CLI command, you should omit the 'wp' for example `composer server cli -- info`
* `composer server exec -- <command>` - Runs any command on the PHP container.
* `composer server db` - Logs into MySQL on the DB container.
  * `composer server db info` - Print MySQL connection details.
  * `composer server db sequel` - Opens a connection to the database in [Sequel Pro](https://sequelpro.com).
