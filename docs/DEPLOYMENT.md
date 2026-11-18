# Deployment Runbook

The graded demo is live at **<https://vending.54.255.218.215.sslip.io>** on the existing Lightsail instance (`Ubuntu-1`, ap-southeast-1). It is a temporary demo. The rest of this document is the general single-server path.

## What you need

- A Linux server with Docker Engine 24+ and Docker Compose v2.
- A domain (or the server's public IP) and a TLS-terminating reverse proxy (Caddy, nginx, or the platform's edge).
- Two secrets: a database password and a long random `JWT_SECRET`.
- No PHP, Composer or MySQL on the host — everything runs in containers.

## Deploy

1. Point DNS at the server, then clone the repository:

   ```bash
   git clone <repository-url> /srv/vending-machine
   cd /srv/vending-machine
   ```

2. Create a production environment file (not committed):

   ```bash
   cp .env.example .env
   ```

   Set at least:

   ```dotenv
   APP_ENV=production
   DB_DATABASE=vending
   DB_USERNAME=vending
   DB_PASSWORD=<strong-password>
   JWT_SECRET=<openssl rand -hex 32>
   ```

   `docker-compose.yml` currently passes environment values to the app. For production, edit the `app.environment` block to read from `.env` (Compose does this automatically for `${VAR}` syntax), and change the MySQL root/user passwords in the `db` block to match.

3. Build and start:

   ```bash
   docker compose up -d --build
   docker compose ps
   ```

   The entrypoint waits for MySQL, applies migrations, runs the idempotent seeder, then starts Apache. The app listens on host port `8080`.

4. Put a TLS reverse proxy in front of `127.0.0.1:8080`.

   Caddy example:

   ```caddyfile
   vending.example.com {
       reverse_proxy 127.0.0.1:8080
   }
   ```

   nginx example:

   ```nginx
   server {
       listen 443 ssl;
       server_name vending.example.com;

       ssl_certificate     /etc/letsencrypt/live/vending.example.com/fullchain.pem;
       ssl_certificate_key /etc/letsencrypt/live/vending.example.com/privkey.pem;

       location / {
           proxy_pass http://127.0.0.1:8080;
           proxy_set_header Host $host;
           proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
           proxy_set_header X-Forwarded-Proto $scheme;
       }
   }
   ```

   Set `session.cookie_secure` through HTTPS termination; the app marks cookies `Secure` automatically when `HTTPS` is present.

5. Verify:

   ```bash
   curl -fsS https://vending.example.com/products > /dev/null
   curl -fsS -X POST https://vending.example.com/api/v1/auth/token \
     -H 'Content-Type: application/json' \
     -d '{"email":"admin@vending.test","password":"password"}'
   ```

   Then open the storefront, sign in, make a purchase and check `/admin/transactions`.

## Operations

- **Logs:** `docker compose logs -f app` and `storage/logs/app.log` inside the container.
- **Migrations/seed:** run automatically on every start; manually with `docker compose exec app php bin/migrate.php` and `php bin/seed.php`.
- **Backups:**

  ```bash
  docker compose exec -T db sh -c 'exec mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" vending' \
    | gzip > vending-$(date +%F).sql.gz
  ```

  Restore with `gunzip -c file.sql.gz | docker compose exec -T db sh -c 'exec mysql -u root -p"$MYSQL_ROOT_PASSWORD" vending'`.
- **Updates:** `git pull`, `docker compose up -d --build`; the entrypoint applies new migrations.
- **Rollback:** redeploy the previous image/tag and restore the matching database backup.

## Security checklist

- [ ] `JWT_SECRET` is random and unique per environment.
- [ ] Database password is not the compose default.
- [ ] `.env` is never committed (already in `.gitignore`).
- [ ] HTTPS is enforced; `Secure` cookies and HSTS are configured at the proxy.
- [ ] The seeded demo passwords are changed before sharing a public link.
- [ ] MySQL port `3307` is **not** exposed publicly; bind it to `127.0.0.1` or remove the `ports` mapping in production.
- [ ] Backups run on a schedule and restores are tested.

## Known local-only shortcuts to address before production

- `docker-compose.yml` uses development credentials (`vending`/`secret`, root `root`). Replace them.
- The app image installs dev dependencies (`phpunit`) because it is the same image used for local work. For a smaller production image, add `--no-dev` to `composer install` and run tests in CI instead.
- The Docker entrypoint seeds missing demo rows on every boot (it never overwrites existing stock, roles, passwords or activation). Remove the `php bin/seed.php` line from `docker/entrypoint.sh` if production should contain no demo accounts.
- Add `docker/php.ini` (already included) turns `display_errors` off and logs to stderr; check it is still present if you customise the image.
