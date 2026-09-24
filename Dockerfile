# FlatBB on Apache + PHP 8.3. One container serves the forum; the same image runs the scheduled jobs (docker-compose.yml).
# The whole site lives in the volume /var/www/html: it is filled from this image on the first start, and from then on
# Admin → Updates upgrades it in place, plugins install into it, and data/ and uploads/ stay with it. See docs/DOCKER.md.
FROM php:8.3-apache

# build gd, pdo_mysql, zip and opcache, then keep only the runtime libraries they link against (the build packages go)
RUN set -eux; \
    saved="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev libzip-dev; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" gd pdo_mysql zip opcache; \
    apt-mark auto '.*' > /dev/null; \
    apt-mark manual $saved; \
    ldd "$(php -r 'echo ini_get("extension_dir");')"/*.so | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) next; gsub("^/(usr/)?", "", so); printf "*%s\n", so }' \
        | sort -u | xargs -r dpkg-query --search | cut -d: -f1 | sort -u | xargs -r apt-mark manual; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*; \
    php -r 'foreach (["gd", "pdo_mysql", "pdo_sqlite", "zip", "mbstring", "curl", "fileinfo"] as $e) if (!extension_loaded($e)) { fwrite(STDERR, "missing $e\n"); exit(1); }'; \
    a2enmod rewrite headers

COPY docker/php.ini /usr/local/etc/php/conf.d/flatbb.ini
COPY docker/apache.conf /etc/apache2/conf-enabled/flatbb.conf
COPY docker/entrypoint.sh /usr/local/bin/flatbb-entrypoint
COPY . /usr/src/flatbb
# a Windows checkout may carry CRLF line endings, which a shell script cannot run with
RUN sed -i 's/\r$//' /usr/local/bin/flatbb-entrypoint && chmod +x /usr/local/bin/flatbb-entrypoint

VOLUME /var/www/html
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s CMD php -r "exit(@file_get_contents('http://127.0.0.1/__rewrite_check') === 'ok' ? 0 : 1);"
ENTRYPOINT ["flatbb-entrypoint"]
CMD ["apache2-foreground"]
