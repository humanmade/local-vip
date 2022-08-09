<h1 align="center">Local Server for VIP</h1>

<p align="center">A local development environment for WordPress VIP projects, built on Docker.</p>

<p align="center"><a href="https://packagist.org/packages/humanmade/local-vip"><img alt="Packagist Version" src="https://img.shields.io/packagist/v/humanmade/local-vip.svg"></a></p>

## Dependencies

* [Composer](https://getcomposer.org/download/)
* [Docker Desktop](https://www.docker.com/get-started) (you can [install Docker Machine directly](https://docs.docker.com/machine/install-machine/) if preferred)

## Installation

Local VIP can be installed as a dependency within a Composer-based WordPress project:

`composer require --dev humanmade/local-vip`

## Getting Started

Fill out your project's `composer.json` to define the project name, domain, and subsites/subdomains you want to use:

```json
  "extra": {
    "local-vip": {
      "name": "test-vip",
      "domain": "text.local",
      "subdomains": true,
			"db-image": "biarms/mysql:5.7"
      "sites": {
        "subdomain": "Subsite Name"
      }
    }
  }
```

Your local domains will need to be mapped within you hosts file. For example, if your domain is `test.local`, the following would need to be added to your hosts file.

```
# test.local
127.0.0.1 test.local
```

In your local server project you can run the following commands:

```
# Start the server cluster
composer server start

# Stop the server cluster
composer server stop
```

[For full documentation click here](./docs).

## Enterprise Search

Local VIP mimics VIP's Enterprise Search by using Elastic Search with mock variables to simulate a VIP environment.

Once the environment is created you can confirm the ES instances is running via Kibana at [http://127.0.0.1:63917/kibana/app/kibana#/](http://127.0.0.1:63917/kibana/app/kibana#/)

In addition you can check the health of the environment in the Kibana console [http://127.0.0.1:63917/kibana/app/kibana#/dev_tools/console?_g=()](http://127.0.0.1:63917/kibana/app/kibana#/dev_tools/console?_g=()]) by executing `GET _cluster/health?pretty`.

In order to leverage ES, the data needs to be indexed. By default, there is no data indexed in the ES environment. To index the data, use WP CLI and execute `wp vip-search index --setup`.
