#!/bin/sh
# First start: copy FlatBB from the image into the empty volume. Later starts leave the site alone, so upgrades made
# under Admin → Updates, installed plugins, data/ and uploads/ are never overwritten by an older image.
set -e
if [ ! -e /var/www/html/index.php ]; then
    echo "flatbb: installing $(sed -n "s/.*FLATBB_VERSION', '\([^']*\)'.*/\1/p" /usr/src/flatbb/core/boot.php) into /var/www/html"
    cp -a /usr/src/flatbb/. /var/www/html/
    mkdir -p /var/www/html/data/cache /var/www/html/uploads
    chown -R www-data:www-data /var/www/html
fi
exec "$@"
