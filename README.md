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

In your local server project you can run the following commands:

```
# Start the server cluster
composer server start

# Stop the server cluster
composer server stop
```

[For full documentation click here](./docs).
