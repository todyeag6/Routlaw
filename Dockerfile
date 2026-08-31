# syntax=docker/dockerfile:1
# ROUTLAW — PHP 8.3 Apache image, pinned to a digest for reproducibility.
# Docroot is public/ (not repo root).
FROM php:8.3-apache-bookworm@sha256:fa8852a2e01747ffe8c8768bfd6bbc2f296f974aa0de90ee157a66664996d263

# Build-time tooling for Composer.
RUN apt-get update && apt-get install -y --no-install-recommends \
      git unzip curl \
      libicu-dev libzip-dev \
      libsodium-dev \
  && rm -rf /var/lib/apt/lists/*

# Extensions: mysqli (DB), sodium (DocumentService/TokenStore), intl/zip for Composer, opcache for prod.
RUN docker-php-source extract \
  && docker-php-ext-install -j"$(nproc)" \
      mysqli sodium intl zip opcache \
  && docker-php-source delete

# Apache: Routlaw's docroot is public/.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
  && a2enmod rewrite

# Composer (build helper).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .

# vendor/ is gitignored; build dependencies inside the image.
RUN composer install --no-dev --optimize-autoloader --no-interaction

EXPOSE 80
