ARG BASE_PHP_IMAGE=humanmade/altis-local-server-php:3.2.0
FROM ${BASE_PHP_IMAGE}

USER root

RUN set -ex \
    # Prepare build dependencies
    && apk add -u --no-cache --virtual .build-deps \
		$PHPIZE_DEPS \
		zlib-dev \
		g++ \
		make \
        autoconf \
	# Install PHP Extensions
	&& apk add -u --no-cache \
        libmemcached-dev \
    && pecl install \
        memcached-3.1.5 \
    && docker-php-ext-enable \
		memcached \
	# Validation
	&& php -m \
	# Cleanup
	&& apk del .build-deps

USER www-data
