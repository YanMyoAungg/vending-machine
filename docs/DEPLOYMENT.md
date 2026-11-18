# Deployment Runbook

The graded demo runs at **<https://vending.54.255.218.215.sslip.io>** on an AWS Lightsail instance
(Ubuntu 22.04, amd64, 416 MB RAM) that also serves another project. `sslip.io` is a free wildcard
DNS service: `<anything>.<ip>.sslip.io` resolves to that IP, so no domain was purchased.

This is the path used for that deployment, repeatable on any single Linux host.

## What you need

- Linux host with Docker Engine 24+ and Compose v2.
- ~2 GB free disk for the build and ~150 MB RAM for the app plus MySQL.
- No PHP, Composer or MySQL on the host — everything runs in containers.

## Files

| File | Role |
|---|---|
| `docker-compose.yml` | local development: publishes `8080` and `3307` |
| `docker-compose.override.yml` | local only: bind-mounts the source for live reload |
| `docker-compose.prod.yml` | production: tuned MySQL, memory limits, loopback-only port, proxy network |
| `.env.prod` | production secrets; created on the server, never committed |
| `deploy/nginx-vending.conf` | the exact vhost the demo serves |
| `deploy/nginx-vending-bootstrap.conf` | HTTP-only vhost used once, before a certificate exists |
| `deploy/apply-vhost.sh` | adds or replaces the vhost block inside an existing nginx config |

## 1. Get the source

```bash
git clone https://github.com/YanMyoAungg/vending-machine.git /srv/vending-machine
cd /srv/vending-machine
```

## 2. Create `.env.prod`

```bash
umask 077
cat > .env.prod <<EOF
DB_DATABASE=vending
DB_USERNAME=vending
DB_PASSWORD=$(openssl rand -hex 16)
DB_ROOT_PASSWORD=$(openssl rand -hex 16)
JWT_SECRET=$(openssl rand -hex 32)
EOF
chmod 600 .env.prod
```

`docker-compose.prod.yml` refuses to start when `DB_PASSWORD`, `DB_ROOT_PASSWORD` or `JWT_SECRET`
is missing. `.env.prod` is gitignored and excluded from the build context.

## 3. Build and start

```bash
docker compose -f docker-compose.prod.yml --env-file .env.prod build
docker compose -f docker-compose.prod.yml --env-file .env.prod up -d
docker compose -f docker-compose.prod.yml --env-file .env.prod ps
```

The entrypoint waits for MySQL, applies migrations, runs the idempotent seeder, then starts Apache.
The first build pulls `php:8.3-apache`, compiles `pdo_mysql` and runs Composer, so expect a few
minutes and roughly 1 GB of base layers.

Always pass `-f docker-compose.prod.yml --env-file .env.prod` on a server: a plain
`docker compose up` picks the development file and its bind mounts.

What the production file changes:

- MySQL is tuned for a small instance (`innodb-buffer-pool-size=48M`, `performance-schema=OFF`,
  20 connections) and every service has a memory limit.
- The app publishes `127.0.0.1:8080` only, so the public entry point is the reverse proxy.
- `DB_HOST` is `vending-db` (the container name), not `db`. On a shared Docker network another
  project may already own the alias `db`, and Docker DNS would silently route to the wrong database.
- The app joins an external network shared with the proxy (`lms-api_lms-network` in the demo).
  On a fresh host create one and update `networks.lms.name`:

  ```bash
  docker network create proxy
  ```

## 4. Reverse proxy and TLS

The demo shares an nginx container (`lms-proxy`) with the other project on that network.
`deploy/nginx-vending.conf` is the complete vhost: a port-80 server that redirects to HTTPS and
answers `/.well-known/acme-challenge/` from `/var/www/certbot`, and a port-443 server that proxies
to `vending-app:80`. It resolves the app through Docker's embedded DNS
(`resolver 127.0.0.11` plus `server vending-app:80 resolve`), so the proxy must run in a container
on the same network.

Issue the certificate while plain HTTP still reaches the host:

```bash
sudo mkdir -p /var/www/certbot
sudo certbot certonly --webroot -w /var/www/certbot -d vending.example.com
```

Mount `/etc/letsencrypt` and `/var/www/certbot` into the proxy container read-only. Add the vhost:

```bash
sudo install -m 755 deploy/apply-vhost.sh /usr/local/bin/apply-vhost
sudo apply-vhost ./deploy/nginx-vending.conf   # validates, reloads, keeps a timestamped backup
```

On the very first run the 443 block cannot validate before the certificate exists, so serve
`deploy/nginx-vending-bootstrap.conf` (port 80 only), issue the certificate, then swap in the final
file.

Reload the proxy after each renewal:

```bash
printf '#!/bin/sh\ndocker exec lms-proxy nginx -s reload\n' \
  | sudo tee /etc/letsencrypt/renewal-hooks/deploy/reload-proxy.sh
sudo chmod +x /etc/letsencrypt/renewal-hooks/deploy/reload-proxy.sh
sudo certbot renew --dry-run
```

If nginx runs directly on the host instead of in a container, drop the `resolver`/`upstream` block
and proxy to `http://127.0.0.1:8080`.

## 5. Verify

```bash
curl -fsS https://vending.example.com/products > /dev/null && echo storefront-ok
curl -sS -o /dev/null -w '%{http_code}\n' https://vending.example.com/login
curl -sS -X POST https://vending.example.com/api/v1/auth/token \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@vending.test","password":"password"}'
```

Then in a browser: sign in as `user@vending.test`, buy a drink and open the receipt; sign in as
`admin@vending.test` and check `/admin/products`, `/admin/users`, `/admin/transactions`. Over HTTPS
the session cookie must carry `Secure`.

## Updating a running deployment

```bash
# on the server: fetch the new source, then
sudo docker tag vending-machine-app:prod vending-machine-app:rollback
sudo docker compose -f docker-compose.prod.yml --env-file .env.prod build
sudo docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --no-build
```

`git pull` works on the server; with `rsync` instead, exclude `.env.prod` so the secrets are never
overwritten. The build runs while the old container keeps serving — traffic switches on `up -d`,
and `--no-build` stops Compose from building a second time. Source changes always need a rebuild:
the production stack has no bind mount.

## Rollback

```bash
sudo docker tag vending-machine-app:rollback vending-machine-app:prod
sudo docker compose -f docker-compose.prod.yml --env-file .env.prod up -d --no-build
```

Migrations are forward-only, so rolling the image back does not undo a schema change. Restore the
matching database backup when the release altered data.

## Operations

- **Logs** — `docker compose -f docker-compose.prod.yml --env-file .env.prod logs -f app`, plus
  `storage/logs/app.log` inside the container.
- **Migrations and seed** — automatic on every start; run manually with
  `... exec app php bin/migrate.php` and `... exec app php bin/seed.php`. The seeder only inserts
  missing rows and never overwrites stock, roles, passwords or activation.
- **Backup / restore** —

  ```bash
  docker compose -f docker-compose.prod.yml --env-file .env.prod exec -T db \
    sh -c 'exec mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" vending' | gzip > vending-$(date +%F).sql.gz

  gunzip -c vending-2026-11-18.sql.gz | docker compose -f docker-compose.prod.yml --env-file .env.prod \
    exec -T db sh -c 'exec mysql -u root -p"$MYSQL_ROOT_PASSWORD" vending'
  ```

- **Disk** — `docker builder prune -af` after a build reclaims several GB; the image itself is
  ~740 MB and the database volume grows with purchases.

## Troubleshooting

| Symptom | Cause |
|---|---|
| `exec /usr/local/bin/entrypoint: exec format error` | Image built for the wrong CPU. Build on the server (`uname -m`), or `docker buildx build --platform linux/amd64`. |
| `SQLSTATE[HY000] [2002] Connection refused` with `DB_HOST=db` | The alias `db` belongs to another stack on the shared network. Point `DB_HOST` at the unique container name. |
| `502 Bad Gateway` | The app container is not on the proxy network, or the name does not resolve: `docker network inspect <network>`. |
| Login does not stick over HTTPS | The proxy is not sending `X-Forwarded-Proto https`; the app marks cookies `Secure` from it. |

## Production hardening

The demo is intentionally reviewable: demo accounts are seeded and their credentials are printed
on the login page. Before running this anywhere real:

- Remove the `php bin/seed.php` line from `docker/entrypoint.sh` and the credential hints in
  `src/Views/auth/login.php`.
- Install dependencies with `--no-dev`; the image ships PHPUnit because it doubles as the test image.
- Rotate `JWT_SECRET` and the database passwords, and keep `.env.prod` at mode `600`.
- Keep MySQL unpublished — the production file exposes only the app, on loopback.
- Schedule backups and test a restore.

## Local development

See the README quick start: `docker compose up --build`, then <http://localhost:8080>.
