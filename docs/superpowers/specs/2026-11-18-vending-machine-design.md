# Vending Machine — Design

**Date:** 2026-11-18
**Status:** Awaiting approval
**Source spec:** `Vending Machine - PHP (6).pdf`

## Intent

Build a PHP vending-machine application for a code test: product management, inventory, purchases, session authentication with Admin/User roles, a JWT-protected REST API, and PHPUnit coverage. The reviewer is the audience, so the build must make each of the 17 requested requirements easy to find in the code, and it must run locally with one command.

The exercise reads as a test of fundamentals — a hand-built PDO layer, an attribute router, role checks, validation, DI and mocking. The approach must keep those primitives visible rather than delegating them to a framework.

## Goals

- A working storefront (browse, sort, paginate, purchase) and admin area (product CRUD, user management, transaction history).
- Clean, explicit implementations of: schema, PDO connection class, repositories, session auth, RBAC, attribute routing, server + client validation, DI, JWT API.
- A PHPUnit suite that tests `ProductsController` against mocked repositories, plus router/validator unit tests and repository integration tests.
- One-command local startup: `docker compose up`, migrations and seed data applied automatically.
- A README and a requirement-to-file map so a reviewer can navigate the submission quickly.

## Non-goals

- Real payment processing, refunds, or hardware integration.
- User self-registration, password reset, email verification.
- A JavaScript SPA or a frontend build step.
- Production hardening beyond sensible defaults (rate limiting at the edge, TLS termination, backups).
- Money stored as floats.

## Anti-goals

- A framework doing the graded work (no Laravel/Slim).
- Client-only validation, or validation rules duplicated between the form and the controller.
- Trusting a role claim from the request instead of the authenticated user record.
- Tests that only re-assert PHPUnit behaviour instead of the controller's decisions.

## Constraints

- PHP 8.3, MySQL 8, Composer. Docker and Docker Compose for local runtime.
- Prices must support three decimals (Pepsi is 6.885 USD) → `DECIMAL(10,3)`.
- No frontend build tooling; CSS and JS are served as static files.
- Deployment is deferred: the app must stay deployment-ready (single Dockerfile, env-driven config) but nothing is hosted yet.

## Approaches Considered

1. **Vanilla PHP 8.3 with an in-house mini-framework — chosen.** Router with PHP 8 attributes, PDO connection class, repositories, DI container, middleware, own view layer. Every graded primitive is visible and testable. Cost: we own ~600 lines of plumbing.
2. **Slim 4 + PHP-DI + route-attribute collector.** Less plumbing and a mature router, but attribute routing and DI become package configuration, so tasks 10 and 15 demonstrate less. More dependencies to justify.
3. **Laravel.** Fastest to polish, but tasks 1–4, 10 and 15 are framework features; a reviewer cannot see the requested implementations.

## Architecture

```
public/index.php          front controller; builds container, dispatches request
src/Core/                 Router, Route/Middleware attributes, Container, Request,
                          Response, View, Validator, Session, Csrf, Env
src/Database/             Connection (PDO wrapper), Migrator, Seeder support
src/Models/               Product, User, Transaction (read-only value objects)
src/Repositories/         ProductRepositoryInterface + PdoProductRepository
                          UserRepositoryInterface + PdoUserRepository
                          TransactionRepositoryInterface + PdoTransactionRepository
src/Services/             AuthService, TransactionManager
src/Http/Controllers/     ProductsController, AuthController, AdminUserController,
                          TransactionController, Api/*
src/Http/Middleware/      Authenticate, RequireRole, CsrfGuard, JsonErrorHandler
src/Views/                layouts, partials, products, admin, auth, errors
routes/web.php            controller classes scanned for attribute routes
database/migrations/       numbered .sql files
database/seed.php         idempotent seed of users and products
tests/Unit/               controller, router, validator, auth tests (mocked)
tests/Integration/        repository tests against a test database
bin/migrate.php           CLI migration runner
bin/seed.php              CLI seeder
```

Request flow: `public/index.php` → `Router` matches an attribute route → middleware pipeline (session, CSRF, auth, role) → controller → repository → `View` or JSON response.

### Dependency injection

A small container in `src/Core/Container.php` supports:
- `set(string $id, callable $factory)` for singletons,
- `get(string $id)` with lazy resolution and circular-dependency detection,
- constructor autowiring by type for controller instantiation.

Controllers receive repositories through their constructor. This is what makes `ProductsController` testable without a database: tests construct it with PHPUnit mocks of the repository interfaces and `TransactionManager`.

### Routing and attribute routing

`#[Route(method, path, name)]` on controller methods. The router scans the controller classes listed in `routes/web.php`, compiles their attributes into a route table, and supports `{param}` and `{param:\d+}` constraints plus named-route URL generation.

Purchase is attribute-routed with an SEO-friendly slug path:

```php
#[Route('GET',  '/products/{slug}/purchase', name: 'products.purchase')]
#[Route('POST', '/products/{slug}/purchase', name: 'products.purchase.store')]
#[Middleware('auth')]
public function purchase(string $slug): Response
```

Middleware is declared per method with `#[Middleware('auth')]` / `#[Middleware('role:admin')]`, so RBAC is visible at the route, not buried in the controller body.

Web routes:

| method | path | action |
|---|---|---|
| GET | `/` | redirect to product list |
| GET | `/products` | list with pagination, sorting, search |
| GET | `/products/{slug}` | product detail |
| GET | `/products/{slug}/purchase` | purchase form (auth) |
| POST | `/products/{slug}/purchase` | purchase (auth) |
| GET | `/orders/{id:\d+}` | receipt for owner/admin (auth) |
| GET | `/login` | login form |
| POST | `/login` | authenticate |
| POST | `/logout` | destroy session |
| GET | `/admin/products` | admin product list incl. inactive |
| GET | `/admin/products/create` | admin create form |
| POST | `/admin/products` | admin create |
| GET | `/admin/products/{id:\d+}/edit` | admin edit form |
| POST | `/admin/products/{id:\d+}` | admin update |
| POST | `/admin/products/{id:\d+}/delete` | soft delete |
| GET | `/admin/users` | admin user list |
| GET | `/admin/users/create` | admin user form |
| POST | `/admin/users` | admin create user |
| GET | `/admin/users/{id:\d+}/edit` | admin edit user |
| POST | `/admin/users/{id:\d+}` | admin update user |
| POST | `/admin/users/{id:\d+}/delete` | soft delete user |
| GET | `/admin/transactions` | admin transaction history |

### Views

Server-rendered PHP templates with a layout, escaped output helper `e()`, flash messages, and old-input repopulation. Admin views for products, users and transactions live under `src/Views/admin/` and are only reachable through `role:admin` routes; the controller also asserts the role defensively.

### Validation

One `Validator` class holds the rules; forms render errors from the same rule set. Server-side is authoritative:
- `name`: required, 2–100 chars
- `slug`: derived from name if omitted, unique, `[a-z0-9-]`
- `price`: required, numeric, > 0, max 3 decimals
- `quantity_available`: required, integer, ≥ 0
- purchase `quantity`: required, integer, ≥ 1, ≤ available stock
- user `name`: required, 2–100 chars
- user `email`: required, valid, unique, ≤ 190 chars
- user `role`: one of `admin`, `user`
- user `password`: required on create, ≥ 8 chars, optional on edit (blank keeps current)
- user `is_active`: boolean

Client-side uses HTML5 attributes plus a small progressive-enhancement script that mirrors the same rules for immediate feedback. With JS disabled, the server still enforces everything.

### Authentication and RBAC

- `password_hash()` / `password_verify()` with `PASSWORD_DEFAULT` (argon2id where available).
- Login regenerates the session ID; logout destroys the session and cookie.
- Session stores `user_id`; the user is reloaded from the database per request so role changes take effect immediately and a stale session cannot carry a forged role.
- Roles: `admin`, `user`.
- Guests may browse; purchasing requires login; admin routes and write APIs require `admin`.
- Inactive accounts are rejected at login, and an existing session stops working on the next request.
- CSRF token required on every state-changing form; APIs are stateless and use JWT instead.

Seeded accounts (documented in README, passwords hashed at seed time):
- `admin@vending.test` / `password` (admin)
- `user@vending.test` / `password` (user)

### Purchase flow

The purchase function lives on `ProductsController` as required by task 6. The controller depends on `ProductRepositoryInterface`, `TransactionRepositoryInterface` and `TransactionManager`, so the whole flow is observable in unit tests with mocks:

1. Validate `quantity` (integer, ≥ 1).
2. `TransactionManager::transactional()` begins a DB transaction.
3. `ProductRepository::findBySlugForUpdate($slug)` re-reads the product with a row lock.
4. Reject if missing/inactive, or if `quantity_available < quantity`.
5. Decrement stock via `ProductRepository::decrementStock()`, then `TransactionRepository::create()` with a **snapshot** of product name and unit price, computed total, and the buying user.
6. Commit; render receipt. Any exception rolls back and surfaces a friendly error.

Concurrent purchases cannot oversell because the stock check and decrement happen under the same row lock.

### User management

Admins manage accounts from `/admin/users`: paginated list, create, edit (name, email, role, active, optional password reset), and delete. Delete is a soft delete (`is_active = 0`): the account can no longer log in, its transaction history stays intact, and an admin can restore it from the edit form. Guards:

- Only active admins can reach these screens and API endpoints.
- An admin cannot deactivate or demote their own account.
- The last active admin cannot be demoted, deactivated or deleted.
- Email uniqueness is enforced in validation and by the database.
- Passwords are hashed at write time; leaving the field blank on edit keeps the current password.

The same operations exist at the repository layer with integration tests, satisfying task 2's CRUD requirement for users.

### Deleting products and users

Deletion is a soft delete everywhere: `DELETE` sets `is_active = 0`. Nothing is hard-deleted, so transaction history and foreign keys stay intact.

- A deleted product disappears from the storefront and public API list, but remains visible to admins with its sales history; the edit form's Active checkbox restores it.
- A deleted user cannot log in or use the API, and their transactions remain attributed; the edit form restores them.
- `DELETE` is idempotent and returns `204` on success.

### Schema

`users`

| column | type | notes |
|---|---|---|
| id | BIGINT UNSIGNED PK AI | |
| name | VARCHAR(100) | |
| email | VARCHAR(190) UNIQUE | login identifier |
| password_hash | VARCHAR(255) | |
| role | ENUM('admin','user') | default `user` |
| is_active | TINYINT(1) | default 1; soft delete — inactive users cannot log in |
| created_at, updated_at | TIMESTAMP | |

`products`

| column | type | notes |
|---|---|---|
| id | BIGINT UNSIGNED PK AI | |
| name | VARCHAR(100) | |
| slug | VARCHAR(120) UNIQUE | SEO-friendly purchase URL |
| description | TEXT NULL | |
| price | DECIMAL(10,3) UNSIGNED | CHECK ≥ 0 |
| quantity_available | INT UNSIGNED | CHECK ≥ 0 |
| is_active | TINYINT(1) | default 1; hides delisted products |
| created_at, updated_at | TIMESTAMP | |

`transactions`

| column | type | notes |
|---|---|---|
| id | BIGINT UNSIGNED PK AI | |
| user_id | BIGINT UNSIGNED FK → users.id ON DELETE RESTRICT | buyer |
| product_id | BIGINT UNSIGNED FK → products.id ON DELETE RESTRICT | |
| product_name | VARCHAR(100) | snapshot at purchase time |
| unit_price | DECIMAL(10,3) | snapshot |
| quantity | INT UNSIGNED | CHECK > 0 |
| total_price | DECIMAL(12,3) | unit_price × quantity |
| created_at | TIMESTAMP | |

Indexes: `transactions(user_id, created_at)`, `transactions(product_id)`, `products(slug)` unique, `users(email)` unique.

Relationships: `users 1—N transactions`, `products 1—N transactions`. Snapshot columns mean editing or delisting a product never rewrites history.

Seed data: Coke 3.99, Pepsi 6.885, Water 0.50, plus stock quantities (e.g. 12/8/24) so pagination and sorting are demonstrable.

### REST API

Stateless JSON under `/api/v1`, `Content-Type: application/json`, consistent error shape `{ "error": { "code", "message", "details"? } }`.

| method | path | access |
|---|---|---|
| POST | `/api/v1/auth/token` | public (email + password) |
| GET | `/api/v1/products` | public; `page`, `per_page`, `sort`, `direction`, `q` |
| GET | `/api/v1/products/{slug}` | public |
| POST | `/api/v1/products` | admin |
| PATCH | `/api/v1/products/{id}` | admin |
| DELETE | `/api/v1/products/{id}` | admin; soft delete |
| POST | `/api/v1/products/{slug}/purchase` | authenticated |
| GET | `/api/v1/transactions` | admin; own history with `?scope=me` |
| GET | `/api/v1/users` | admin |
| POST | `/api/v1/users` | admin |
| PATCH | `/api/v1/users/{id}` | admin; name, email, role, is_active, optional password |
| DELETE | `/api/v1/users/{id}` | admin; soft delete |

JWT: HS256 via `firebase/php-jwt`, `sub` = user id, `role` claim for convenience only — the middleware reloads the user and checks the live role and `is_active`. Token TTL 1 hour; secret from `JWT_SECRET`; missing secret fails fast at boot. Login returns the token and expiry; protected endpoints read `Authorization: Bearer <token>`.

`DELETE` soft-deletes products and users (`is_active = 0`) and returns `204`; both are restorable through `PATCH` with `"is_active": true` or the admin edit form.

### UI direction

A polished, restrained vending-machine storefront rather than a plain CRUD demo: warm off-white surfaces, a single vivid accent, strong product cards, clear price/stock hierarchy, and a receipt-style purchase panel. Admin views are denser but share the same tokens. Two-column responsive product grid, accessible focus states, visible form errors, table-to-cards collapse on mobile. No external fonts required at runtime (system stack or self-hosted).

Detailed visual direction will follow the `ui-ux-pro-max` skill during implementation.

## Testing

**Unit (no database):**
- Router: attribute discovery, param extraction, constraints, 404, method mismatch, named URL generation.
- Validator: each rule and each error message; boundary values (0 price, negative quantity, 4-decimal price).
- `ProductsController` with mocked `ProductRepositoryInterface`, `TransactionRepositoryInterface`, `TransactionManager`:
  - successful purchase decrements stock and logs one transaction with snapshot values;
  - quantity above stock → 422/error, no writes, rollback not committed;
  - inactive or unknown product → 404;
  - quantity 0, negative, string → validation errors;
  - total price uses integer-safe decimal arithmetic (6.885 × 3 = 20.655);
  - repository exception → rollback + friendly error;
  - non-admin hitting admin actions → 403; guest purchase → redirect to login;
  - create/update: validation failures, duplicate slug, successful paths;
  - delete: sets `is_active = false` without touching rows, storefront hides the product, restore path works;
  - admin user management: create/update with role changes, password reset, self-demotion blocked, last-admin guard, duplicate email rejected, inactive user cannot log in.
- AuthService: password verify, session regeneration, role reload, inactive account rejection.

**Integration (MySQL test database via Docker):**
- Migrations apply cleanly to an empty schema.
- Product repository CRUD, pagination/sorting, slug lookup, soft delete visibility.
- User repository CRUD, unique email enforcement, inactive filtering.
- Transaction repository create/list.
- Purchase concurrency smoke test: two sequential purchases never oversell; rollback leaves stock unchanged.
- Soft delete keeps transaction history and foreign keys intact.

**Commands:** `composer test` (phpunit), `composer migrate`, `composer seed`.

## Security notes

- PDO prepared statements everywhere; no string interpolation into SQL.
- All output escaped with `e()`; JSON encoded with `JSON_THROW_ON_ERROR`.
- Session cookie `HttpOnly`, `SameSite=Lax`, `Secure` when HTTPS.
- CSRF token compared with `hash_equals`.
- JWT secret required from environment; no default secret in code.
- Generic login failure message; passwords never logged.
- Admin role checked server-side on every admin request, not only in navigation.

## Project conventions

- PSR-4 autoload under `App\` → `src/`.
- Strict types in every PHP file; `declare(strict_types=1)`.
- Constructor injection, no service locator inside controllers.
- One responsibility per class; repositories return models, not raw arrays, at controller boundaries.
- Money handled as strings/`Decimal`-style via `number_format` at render time and MySQL `DECIMAL` in storage.
- Errors: 404/403/422 pages for web, JSON equivalents for API.

## Deliverables

1. Source code in this folder, git-initialised with meaningful commits.
2. `docker-compose.yml` + `Dockerfile`; app on `http://localhost:8080`.
3. `README.md`: setup, seeded accounts, demo walkthrough, and an explicit note that hosting was deferred by the submitter's choice (so it reads as a decision, not an omission).
4. `docs/DEPLOYMENT.md`: runbook for a single server (Docker Compose with app + MySQL, env/secrets, migrate + seed, smoke test) to use once a host is chosen.
5. `docs/REQUIREMENTS.md`: each of the 17 tasks mapped to the file(s) that satisfy it, plus the written database design answer for task 1.
6. Green PHPUnit run.

## Decisions taken (open to correction)

- **Vanilla mini-framework** over Slim/Laravel — keeps graded primitives visible.
- **Login required to purchase** — gives transactions a real user relationship and exercises RBAC; guests can still browse. Alternative: guest purchases with nullable `user_id`.
- **Purchase orchestration lives in `ProductsController`** — task 6 asks for the function there, and it keeps the controller unit tests meaningful. No extra service layer.
- **Users get full admin management** — list, create, edit, role assignment, password reset and soft delete, backed by repository CRUD. The last active admin is protected from demotion/deactivation.
- **Soft delete for products and users** — `is_active = 0`; history and foreign keys stay intact, and edit forms restore records. No hard delete and no 409 branch.
- **`price` stored as `DECIMAL(10,3)`** — 6.885 needs three decimals.
- **No self-registration** — seeded accounts are enough for the test; adding it would pad scope without being asked.
- **Snapshot columns on transactions** — preserves history across product edits.
- **Deployment deferred, Docker-ready now** — hosting awaits your choice; `docs/DEPLOYMENT.md` and the README make that explicit.
