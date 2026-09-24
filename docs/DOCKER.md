# Running FlatBB with Docker

Docker is an optional way to run FlatBB; uploading the unzipped folder to any PHP host stays the main one. The image is Apache with PHP 8.3 and every extension FlatBB uses (SQLite, MySQL, GD, zip, curl).

## Start

In the unzipped `flatbb-<version>/` folder (or a checkout of the source):

```bash
docker compose up -d
```

Open http://localhost:8080 and follow the installer. Choose **SQLite** and there is nothing else to set up.

Two containers run: `flatbb` (the forum) and `cron` (the scheduled jobs, once a minute; nothing to configure).

## Port

The forum answers on port **8080**. To use another one, create a `.env` file next to `docker-compose.yml`:

```ini
FLATBB_PORT=8090
```

then run `docker compose up -d` again.

## MySQL instead of SQLite

Put your own passwords in `.env` first:

```ini
MYSQL_PASSWORD=a-long-random-password
MYSQL_ROOT_PASSWORD=another-long-random-password
```

Start with the MySQL service included:

```bash
docker compose --profile mysql up -d
```

In the installer choose MySQL with host `mysql`, port `3306`, database `flatbb`, user `flatbb` and the `MYSQL_PASSWORD` you set. The database is not published outside Docker.

## Where your site lives

Everything is in the Docker volume `flatbb` (mounted at `/var/www/html`): the code, `data/` (settings, the SQLite database), `uploads/` and the plugins you install. MySQL keeps its data in the volume `mysql`.

- `docker compose down` stops the forum and keeps the volumes.
- `docker compose down -v` **deletes the volumes, and with them the whole forum.**

## Upgrades

Upgrade from **Admin → Updates**, as on any other host. The new files land in the volume, so they survive restarts.

Rebuilding the image does not change a forum that is already installed. The image is only copied into the volume the first time, when the volume is empty.

## Backups

Copy the volume to a file in the current folder:

```bash
docker run --rm -v flatbb_flatbb:/site -v "$PWD":/backup alpine tar czf /backup/flatbb-site.tgz -C /site .
```

With MySQL, also dump the database:

```bash
docker compose exec mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" flatbb' > flatbb.sql
```

## HTTPS and a domain

Put the forum behind the reverse proxy you already use (Nginx, Caddy, Traefik, a panel) and forward to port 8080. The proxy must send `X-Forwarded-Proto: https`.

If links still point to the wrong address, set `'base_url' => 'https://forum.example.com'` in `data/config.php` inside the volume:

```bash
docker compose exec flatbb sh
```

## Command line

The `flatbb` command runs inside the container:

```bash
docker compose exec -u www-data flatbb php flatbb upgrade:check
docker compose exec -u www-data flatbb php flatbb admin:password <user> <new password>
```
