# PHP 8.3 + Apache web container for the docker-compose fallback dev store.
# ddev is the preferred environment — see docs/development.md.
FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        default-mysql-client \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libsodium-dev \
        libxml2-dev \
        libxslt1-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        intl \
        opcache \
        pdo_mysql \
        soap \
        sockets \
        xsl \
        zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN { \
        echo "memory_limit = 2G"; \
        echo "max_execution_time = 1800"; \
        echo "realpath_cache_size = 10M"; \
        echo "realpath_cache_ttl = 7200"; \
    } > /usr/local/etc/php/conf.d/zz-magento.ini

# Serve the git-ignored Magento install at dev/magento/pub, with .htaccess
# support (Magento requires AllowOverride All + mod_rewrite).
ENV APACHE_DOCUMENT_ROOT=/var/www/html/dev/magento/pub
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
        /etc/apache2/sites-available/000-default.conf \
    && a2enmod rewrite headers \
    && printf '<Directory /var/www/html>\n    AllowOverride All\n    Require all granted\n</Directory>\n' \
        > /etc/apache2/conf-available/magento.conf \
    && a2enconf magento

# Match the host UID (passed by dev/setup.sh) so the bind-mounted repo
# stays writable by both the container (www-data) and the host user.
ARG HOST_UID=1000
RUN usermod -o -u "$HOST_UID" www-data
