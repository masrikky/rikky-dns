# ── Rikky DNS Propagation Checker ──────────────────────────────────────────
# PHP 8.3 + Apache  (static files + per-resolver API backend)
#
# Build : docker build -t rikky-dns .
# Run   : docker run -p 8080:80 rikky-dns
# Dev   : docker compose up --build
# ────────────────────────────────────────────────────────────────────────────

FROM php:8.3-apache

# ── PHP extensions ──────────────────────────────────────────────────────────
# curl  : needed for DoH cURL fallback in api/check.php
# (fsockopen is PHP core — no extension required)
RUN docker-php-ext-install curl \
    && docker-php-ext-enable curl

# ── Apache: enable rewrite, set AllowOverride ───────────────────────────────
RUN a2enmod rewrite \
    && sed -i 's/AllowOverride None/AllowOverride All/g' \
             /etc/apache2/apache2.conf

# ── PHP runtime tweaks ───────────────────────────────────────────────────────
RUN { \
    echo 'allow_url_fopen = On'; \
    echo 'expose_php = Off'; \
    echo 'display_errors = Off'; \
    echo 'log_errors = On'; \
    echo 'error_log = /dev/stderr'; \
} > /usr/local/etc/php/conf.d/rikky-dns.ini

# ── Copy project files ───────────────────────────────────────────────────────
COPY --chown=www-data:www-data . /var/www/html/

# Remove the diagnostic script from production image
RUN rm -f /var/www/html/api/diagnostic.php

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/api/check.php?ping=1 || exit 1
