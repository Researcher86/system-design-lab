FROM php:8.5-cli

ARG XDEBUG_VERSION=3.5.3

RUN apt-get update && apt-get install -y \
        $PHPIZE_DEPS \
        git \
        unzip \
        procps \
        htop \
        strace \
        time \
    && git clone --depth 1 --branch ${XDEBUG_VERSION} https://github.com/xdebug/xdebug.git /tmp/xdebug \
    && cd /tmp/xdebug \
    && phpize \
    && ./configure --enable-xdebug \
    && make -j"$(nproc)" \
    && make install \
    && docker-php-ext-enable xdebug \
    && docker-php-ext-install pcntl posix \
    && rm -rf /tmp/xdebug \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV PHP_IDE_CONFIG serverName=system-design-lab

# Xdebug is installed but off by default. This project measures time, and an
# extension that is merely loaded changes the numbers. Debug runs opt in with
# XDEBUG_MODE=debug XDEBUG_TRIGGER=1; see the *-debug make targets.
RUN { \
        echo 'zend_extension=xdebug'; \
        echo 'xdebug.mode=off'; \
        echo 'xdebug.start_with_request=trigger'; \
        echo 'xdebug.client_host=host.docker.internal'; \
        echo 'xdebug.client_port=9003'; \
        echo 'xdebug.discover_client_host=1'; \
    } > /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

# The lab builds indexes and runs bounded simulations in one process. The limit
# is explicit so a host default cannot silently change what an out-of-memory
# failure means here.
RUN { \
        echo 'memory_limit=512M'; \
        echo 'opcache.enable_cli=0'; \
        echo 'date.timezone=UTC'; \
    } > /usr/local/etc/php/conf.d/zz-system-design-lab.ini

RUN mkdir -p /root/.config/htop && { \
        echo 'fields=0 48 2 46 47 49 1'; \
        echo 'sort_key=48'; \
        echo 'tree_view=1'; \
        echo 'hide_kernel_threads=1'; \
    } > /root/.config/htop/htoprc

WORKDIR /app
