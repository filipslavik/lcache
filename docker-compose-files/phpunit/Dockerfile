FROM php:5.6-cli

RUN apt-get update \
    && apt-get install -y --force-yes --no-install-recommends \
		unzip \
    zlib1g-dev \
		&& rm -rf /var/lib/apt/lists/*
# install and enable apcu extension
RUN echo "" | pecl install -f apcu-4.0.11 \
    && docker-php-ext-enable apcu

# configure apcu extension
RUN { \
      echo ''; \
      echo 'apc.enable_cli=1'; \
    } >> /usr/local/etc/php/conf.d/docker-php-ext-apcu.ini


RUN pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && rm -rf /tmp/pear/

# install php extensions
RUN docker-php-ext-install zip pdo_mysql

# Enable error reporting.
# https://github.com/docker-library/php/issues/153
RUN { \
      echo 'error_reporting=E_ALL'; \
      echo 'log_errors=On'; \
    } > /usr/local/etc/php/conf.d/errors.ini

# Install phpunit
RUN curl -sSL -o /usr/local/bin/phpunit "https://phar.phpunit.de/phpunit.phar" \
  && chmod +x /usr/local/bin/phpunit

# install phpcs
RUN curl -sSL -o /usr/local/bin/phpcs "https://squizlabs.github.io/PHP_CodeSniffer/phpcs.phar" \
  && chmod +x /usr/local/bin/phpcs

# install phpcbf
RUN curl -sSL -o /usr/local/bin/phpcbf "https://squizlabs.github.io/PHP_CodeSniffer/phpcbf.phar" \
  && chmod +x /usr/local/bin/phpcbf

RUN curl -sSL "https://getcomposer.org/installer" | php \
  && mv composer.phar /usr/local/bin/composer

RUN mkdir -p /lcache

WORKDIR /lcache
