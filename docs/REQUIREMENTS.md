# Requirements Map

This document answers the exercise task by task and points to the code that implements it.

## 1. Database design

Three InnoDB tables in `database/migrations/`:

**`users`** — `id` (BIGINT UNSIGNED PK), `name`, `email` (unique), `password_hash`, `role` (`ENUM('admin','user')`), `is_active` (soft delete), `created_at`, `updated_at`.

**`products`** — `id` (BIGINT UNSIGNED PK), `name`, `slug` (unique, used for SEO-friendly URLs), `description` (nullable), `price` (`DECIMAL(10,3) UNSIGNED`, `CHECK >= 0`), `quantity_available` (`INT UNSIGNED`, `CHECK >= 0`), `is_active` (soft delete), `created_at`, `updated_at`.

**`transactions`** — `id` (BIGINT UNSIGNED PK), `user_id` (FK → users, `ON DELETE RESTRICT`), `product_id` (FK → products, `ON DELETE RESTRICT`), `product_name` and `unit_price` (snapshots), `quantity` (`INT UNSIGNED`, `CHECK > 0`), `total_price` (`DECIMAL(12,3)`), `created_at`.

Relationships: `users 1—N transactions`, `products 1—N transactions`. Indexes cover `users(email)`, `products(slug)`, `transactions(user_id, created_at)` and `transactions(product_id)`.

Why these choices:

- **`DECIMAL(10,3)`** — Pepsi is 6.885 USD, so two decimals would lose money. Prices and totals are strings in PHP and integers in mills during arithmetic (`src/Support/Money.php`); floats are never used for money.
- **Snapshot columns** on transactions preserve what was actually sold and at what price, so product edits or delistings never rewrite history.
- **Soft delete (`is_active`)** keeps foreign keys and transaction history valid; "delete" hides a record from the storefront and blocks login/APIs.
- **Row locks** — purchases re-read the product with `SELECT ... FOR UPDATE` before decrementing, so two buyers can never oversell the last unit.

## 2. PDO data access

`src/Database/Connection.php` builds a PDO connection from environment variables (exceptions, associative fetch, native prepares, UTF-8). Repositories own the SQL:

- `src/Repositories/PdoProductRepository.php` — CRUD, pagination/sorting/search, slug lookup, soft delete/restore, guarded stock decrement.
- `src/Repositories/PdoUserRepository.php` — CRUD, email lookup, soft delete/restore, active-admin count.
- `src/Repositories/PdoTransactionRepository.php` — create/read/list; transactions are append-only by design (a ledger), so no update/delete methods exist.

## 3. Authentication

`src/Services/AuthService.php` validates credentials, rejects inactive users, regenerates the session on login and resolves the current user from the session. Passwords use `password_hash()` with `PASSWORD_DEFAULT` through `src/Services/NativePasswordHasher.php`. Login/logout live in `src/Http/Controllers/AuthController.php`; the form is `src/Views/auth/login.php`.

## 4. Role-based access control

Roles are stored on `users` and re-read from the database on every request (`ResolveUser` middleware), so a stale session cannot carry a forged role and a deactivated user loses access immediately. `src/Http/Middleware/SessionAuth.php` redirects guests; `src/Http/Middleware/RequireRole.php` enforces `admin`. Admin routes declare `#[Middleware('auth')]` then `#[Middleware('role:admin')]`. CSRF is enforced by `VerifyCsrf`.

## 5. `ProductsController`

`src/Http/Controllers/ProductsController.php` holds product CRUD (`adminIndex`, `create`, `store`, `edit`, `update`, `destroy`) plus the public listing and detail actions. It receives repositories through its constructor — no service locator.

## 6. Purchasing

`ProductsController::purchase()` validates the quantity, then `purchaseProduct()` runs inside `TransactionManagerInterface::transactional()`: re-read with `findBySlugForUpdate`, check stock, `decrementStock`, and create a transaction row with the name/price snapshot and mill-based total. Receipts are at `GET /orders/{id}` and restricted to the owner or an admin.

## 7. CRUD views

`src/Views/admin/products/index.php` and `form.php` provide list/create/edit/delete, with flash messages and old-input repopulation. The routes are admin-only; the controller also checks the role defensively. User management views live in `src/Views/admin/users/`.

## 8. Listing, pagination and sorting

`ProductsController::index()` reads `page`, `sort`, `direction` and `search` and calls `PdoProductRepository::paginate()`, which clamps page/per-page, whitelists sort columns, validates direction and escapes `LIKE` wildcards. `src/Views/products/index.php` renders the grid, sort links, search box and `src/Views/partials/pagination.php`.

## 9. Purchase view

`src/Views/products/purchase.php` shows product details, stock, a quantity input and a live total; errors re-render the form with old input. `src/Views/products/receipt.php` shows the completed purchase.

## 10. Routing

`src/Core/Router.php` is a front-controller router. Controllers are registered in `routes/web.php`; the router scans public methods for `#[Route]` attributes, compiles `{param}` patterns, runs middleware and injects `Request`/scalar arguments (cast to declared types). `public/index.php` boots the app.

## 11. Attribute routing

Purchase uses method attributes with an SEO-friendly slug URL:

```php
#[Route('GET', '/products/{slug}/purchase', name: 'products.purchase')]
#[Route('POST', '/products/{slug}/purchase', name: 'products.purchase.store')]
```

The API purchase route is `POST /api/v1/products/{slug}/purchase` on the same controller, sharing `purchaseProduct()`.

## 12. Validation rules

`src/Core/Validator.php` implements required, string, integer, numeric, decimal places, min/max, email, enum and regex rules. Rule sets are centralized in `src/Support/Rules.php`:

- product: name 2–100, slug format, price > 0 with at most 3 decimals, quantity ≥ 0, active boolean;
- user: name, unique email, role in `admin,user`, password ≥ 8;
- purchase: quantity 1–1000 and ≤ available stock.

## 13. Server and client validation

The server is authoritative: every write validates through `Validator` and controllers re-render with field errors and old input. `public/assets/js/app.js` mirrors the product/user/purchase rules with `setCustomValidity` for immediate feedback; forms also use native `required`/`min`/`max`. With JavaScript disabled the server still rejects invalid input.

## 14. PHPUnit tests

- `tests/Unit/Core` — Env, Container, Request/Response, Session, CSRF, Router, View, Application, Validator.
- `tests/Unit/Support` — Money, Str.
- `tests/Unit/Services` — AuthService, JwtService, UserPolicy.
- `tests/Unit/Http` — ProductsController admin/storefront/purchase/API, AuthController, AdminUserController, TransactionController, middleware, routes, API controllers.
- `tests/Integration` — migrations, seeder, transaction manager, product/user/transaction repositories against MySQL.

Boundary cases include exact-stock purchases, overselling attempts, hostile sort/page input, 3-decimal totals, duplicate slugs/emails, last-admin lockout, inactive users, expired/tampered JWTs and soft-delete visibility.

## 15. Dependency injection and mocking

`src/Core/Container.php` provides explicit factories plus constructor autowiring. Controllers depend on repository interfaces (`ProductRepositoryInterface`, `UserRepositoryInterface`, `TransactionRepositoryInterface`), `TransactionManagerInterface`, `PasswordHasherInterface` and `SessionInterface`. Unit tests construct controllers with PHPUnit mocks; no database is needed for controller tests.

## 16. REST API

Controllers live in `src/Http/Controllers/Api/`:

- `AuthApiController` — `POST /api/v1/auth/token`.
- `ProductApiController` — list/show/create/update/delete.
- `UserApiController` — list/create/update/delete, reusing `UserPolicy`.
- `TransactionApiController` — list with admin/own scoping.
- `ProductsController::purchaseApi()` — purchase endpoint.

Collections return `{data, meta}`; errors return the shared `{"error": {...}}` shape.

## 17. JWT authentication

`src/Services/JwtService.php` issues and validates HS256 tokens (`iss`, `sub`, `role`, `iat`, `exp`) using `firebase/php-jwt`, with a one-hour TTL from `JWT_SECRET`. `src/Http/Middleware/ApiAuth.php` parses `Authorization: Bearer`, validates the signature/expiry, reloads the user and rejects inactive accounts. The `role` claim is informational only — authorisation uses the live database role via `RequireRole`.

## Submission

- Source: this repository.
- Runtime: `Dockerfile`, `docker-compose.yml`, `docs/DEPLOYMENT.md`.
- Hosting: deferred by the submitter's choice; the preview link still requires choosing a host. The README and deployment runbook state this explicitly so it is not mistaken for an omission.
