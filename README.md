# Vending Machine

A PHP vending-machine application built for a take-home code exercise. It covers product management, inventory, purchasing, session authentication with Admin/User roles, PHPUnit coverage and a JWT-protected REST API.

The app is deliberately built on vanilla PHP 8.3 with a small in-house MVC layer — no framework — so the requested fundamentals (PDO connection, attribute routing, DI, RBAC, validation, mocking) are visible in the source rather than delegated to a framework.

## Features

- Storefront: product grid, search, sorting, pagination, product detail.
- Purchase flow: quantity selection, live total, row-locked stock decrement, receipt.
- Admin: product CRUD with soft delete/restore, user management with password reset and last-admin protection, transaction history.
- Auth: session login with `password_hash`, CSRF tokens, role checks on every admin route.
- API: JSON endpoints for auth, products, purchases, users and transactions; HS256 JWTs; soft deletes and the same validation rules as the web app.

## Quick start (Docker)

```bash
docker compose up --build
```

Open <http://localhost:8080>.

The database container exposes MySQL on host port `3307`; the app container reaches it at `db:3306`. Migrations and seed data run automatically on startup.

`docker-compose.override.yml` mounts the application source into the container, so edits to `public/`, `src/`, `routes/`, `bootstrap/`, `database/`, `bin/` and `tests/` apply on the next request — no rebuild. Rebuild with `docker compose up -d --build` only after changing `composer.json`/`composer.lock`, the `Dockerfile`, or files under `docker/`.

If a request fails while you are editing, the response is a plain 500 because `docker/php.ini` sets `display_errors=Off`. The details are in `storage/logs/app.log` and `docker compose logs -f app`.

Seeded accounts:

| Role | Email | Password |
|---|---|---|
| Admin | `admin@vending.test` | `password` |
| User | `user@vending.test` | `password` |

Seeded products: Coke `USD 3.99` (12), Pepsi `USD 6.885` (8), Water `USD 0.50` (24).

## Local development (host PHP)

Requirements: PHP 8.3 with `pdo_mysql`, Composer, and MySQL 8 (the Docker `db` service is enough).

```bash
cp .env.example .env
composer install
docker compose up -d db
composer migrate
composer seed
php -S 127.0.0.1:8000 -t public
```

Open <http://127.0.0.1:8000>. The `.env` file points at `127.0.0.1:3307`, matching the Docker database port. Port `8000` avoids the app container on `8080`; stop it with `docker compose stop app` if you would rather use `8080`.

## Tests

```bash
# Unit tests (no database)
vendor/bin/phpunit --testsuite Unit

# Everything; integration tests need the MySQL container
docker compose up -d db
vendor/bin/phpunit
```

Integration tests use the separate `vending_test` database created by `docker/mysql-init.sql`. If that database is missing, recreate the volume once:

```bash
docker compose down -v
docker compose up -d db
```

## Demo walkthrough

1. Browse `/products`; sort by price or stock, search for "pepsi".
2. Sign in as the demo user, buy 2 Pepsi. The receipt total is `USD 13.77`; Pepsi stock drops by 2.
3. Sign in as the admin. `/admin/products` shows stock and status; create, edit, soft-delete and restore a product.
4. `/admin/users` creates a user, changes a role, resets a password; self-demotion and the last-admin guard are blocked.
5. `/admin/transactions` lists purchases with buyer, product, quantity and totals.

## REST API

All API responses are JSON. Errors use:

```json
{"error":{"code":"validation_failed","message":"The given data was invalid.","details":{"price":["..."]}}}
```

### Token

```bash
curl -s -X POST http://localhost:8080/api/v1/auth/token \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@vending.test","password":"password"}'
```

Use the returned `data.token` as `Authorization: Bearer <token>`. Tokens are HS256, valid for one hour, and are re-checked against the live user on every request (a deactivated user's token stops working).

### Endpoints

| Method | Path | Access |
|---|---|---|
| POST | `/api/v1/auth/token` | public |
| GET | `/api/v1/products` | public; `page`, `per_page`, `sort`, `direction`, `search` |
| GET | `/api/v1/products/{slug}` | public |
| POST | `/api/v1/products` | admin |
| PATCH | `/api/v1/products/{id}` | admin |
| DELETE | `/api/v1/products/{id}` | admin (soft delete) |
| POST | `/api/v1/products/{slug}/purchase` | authenticated |
| GET | `/api/v1/transactions` | authenticated; admins see all, others only their own (`scope=me` is also accepted) |
| GET | `/api/v1/users` | admin |
| POST | `/api/v1/users` | admin |
| PATCH | `/api/v1/users/{id}` | admin |
| DELETE | `/api/v1/users/{id}` | admin (soft delete) |

Examples:

```bash
TOKEN=... # from the token endpoint

curl -s http://localhost:8080/api/v1/products?per_page=2
curl -s -X POST http://localhost:8080/api/v1/products/coke/purchase \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"quantity":2}'
```

## Design decisions

- **Vanilla mini-framework.** Router with PHP 8 attributes, PDO repositories, DI container, middleware pipeline and server-rendered views. A framework would have hidden the primitives the exercise asks for.
- **Money is decimal, never float.** `price` is `DECIMAL(10,3)` because Pepsi costs `6.885`. Totals are computed in mills by `App\Support\Money`.
- **Purchases are transactional.** `ProductsController::purchaseProduct()` opens a transaction, re-reads the product with `SELECT ... FOR UPDATE`, checks stock, decrements and writes the transaction row. Concurrent buyers cannot oversell.
- **Transactions are append-only.** The ledger stores a snapshot of the product name and unit price, so editing or delisting a product never rewrites history. There are no update/delete operations for transactions.
- **Soft delete everywhere.** Deleting a product or user sets `is_active = 0`; edit forms restore it. Foreign keys and history stay intact.
- **Login is required to buy.** It gives each transaction a real buyer and exercises the RBAC requirement. Guests can browse.
- **Sessions for HTML, JWT for JSON.** Web routes use `HttpOnly`, `SameSite=Lax` session cookies and CSRF tokens; API routes are stateless and use bearer tokens.
- **Users are managed by admins.** There is no self-registration; the seeder creates the two demo accounts and admins can create more.

## Project structure

```
public/                 front controller, assets
bootstrap/app.php       container wiring, middleware, route registration
routes/web.php          controllers scanned for attribute routes
src/Core/               Env, Container, Router, Request/Response, View, Validator, Application
src/Database/           PDO connection, migrator, seeder, transaction manager
src/Models/             Product, User, Transaction, PaginatedResult
src/Repositories/       interfaces + PDO implementations
src/Services/           AuthService, UserPolicy, JwtService
src/Http/               controllers and middleware
src/Views/              layouts, partials, storefront, admin, auth, errors
database/migrations/    users, products, transactions
tests/Unit/             router, validator, money, auth, middleware, controllers
tests/Integration/      migrations, seeder, repositories
```

## Submission note

A live preview is running at **<https://vending.54.255.218.215.sslip.io>** on AWS Lightsail (demo app only; it will be removed after the review period). The URL is a free `sslip.io` hostname that resolves to the instance's static IP — no domain was purchased.

Deployment details are in [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md).

A requirement-by-requirement map, including the written database design answer for task 1, is in [`docs/REQUIREMENTS.md`](docs/REQUIREMENTS.md).
