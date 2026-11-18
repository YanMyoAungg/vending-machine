# Vending Machine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a PHP vending-machine application with a hand-built mini-framework, MySQL persistence, session auth with roles, attribute routing, validation, PHPUnit coverage and a JWT REST API.

**Architecture:** Front controller → attribute router → middleware pipeline → controller → PDO repository → MySQL, with a small DI container and server-rendered PHP views. Purchase logic lives on `ProductsController` and runs inside a database transaction with a row lock.

**Tech Stack:** PHP 8.3, MySQL 8.4, Apache, Composer, PHPUnit 11, firebase/php-jwt, Docker Compose.

**Spec:** `docs/superpowers/specs/2026-11-18-vending-machine-design.md`

## Global Constraints

- PHP `^8.3`; every PHP file starts with `declare(strict_types=1);`.
- PSR-4: `App\` → `src/`; tests `App\Tests\` → `tests/`.
- No frameworks, no ORM, no frontend build step.
- Money travels as decimal strings; storage is `DECIMAL(10,3)`; totals use the `Money` helper, never floats.
- Product prices: Coke `3.990`, Pepsi `6.885`, Water `0.500`.
- Soft delete everywhere: `is_active = 0`; never hard-delete products or users.
- Seeded accounts: `admin@vending.test` / `password`, `user@vending.test` / `password`.
- Session cookie: `HttpOnly`, `SameSite=Lax`; `Secure` when HTTPS.
- JWT: HS256, TTL 3600 s, secret from `JWT_SECRET`, fail fast when missing.
- API JSON error shape: `{"error":{"code":string,"message":string,"details":object?}}`.
- Local deployment only for now; app on `http://localhost:8080`.
- Integration tests use database `vending_test` on port `3307`.

## Review Focus

1. **Two buyers racing for the last unit.** Stock check and decrement must happen under one row lock; a purchase at exactly the remaining quantity succeeds, the next one fails without writing. Tests in Tasks 5 and 11.
2. **Hostile list parameters.** `sort=drop`, `direction=sideways`, `page=0`, `per_page=9999`, `search=%` must not break SQL or ranking. Whitelist and clamp in Task 5, assert in Tasks 5 and 10.
3. **Zero and boundary stock.** Buying 0 must be rejected; buying all remaining units must succeed and leave 0; buying more must fail. Tasks 5, 7, 11.
4. **Admin lockout.** An admin must not demote or deactivate themselves, and the last active admin must not lose admin access by any route. Task 12 (web), Task 17 (API).
5. **Stale or forged API identity.** Expired and tampered JWTs are rejected; a token for a user deactivated after issuance stops working. Task 15.

---

## File Structure

```
composer.json                     deps, autoload, scripts
phpunit.xml                       Unit + Integration suites, test env
Dockerfile / docker-compose.yml   app + MySQL, one-command local start
docker/                           apache vhost, entrypoint, mysql init
public/index.php                  front controller
public/.htaccess                  rewrite to index.php
public/assets/css/app.css         design system
public/assets/js/app.js           progressive enhancement + client validation
routes/web.php                    controller registry for attribute scanning
bootstrap/app.php                 container wiring, middleware registry, routes
src/Core/                         Env, Container, Request, Response, Router, Route,
                                  Middleware attribute/interface, Session, Csrf, View,
                                  HttpException, Application
src/Database/                     Connection, Migrator, Seeder, PdoTransactionManager
src/Models/                       Product, User, Transaction, PaginatedResult
src/Repositories/                 interfaces + PDO implementations
src/Services/                     AuthService, UserPolicy, JwtService
src/Http/Controllers/             ProductsController, AuthController, AdminUserController,
                                  TransactionController, Api/*
src/Http/Middleware/              SessionAuth, RequireRole, VerifyCsrf, ApiAuth
src/Support/                      helpers.php, Money.php, Str.php
src/Views/                        layouts, partials, auth, products, admin, errors
database/migrations/              001_users.sql, 002_products.sql, 003_transactions.sql
database/seed.php                 seed runner used by bin/seed.php
bin/migrate.php, bin/seed.php     CLI entry points
tests/Unit/                       core, controller, service tests (no database)
tests/Integration/                repository, migration, seeder tests (MySQL)
tests/Fixtures/                   fixture controllers, middleware, views
docs/REQUIREMENTS.md              task 1–17 map
docs/DEPLOYMENT.md                single-server runbook
README.md                         setup, credentials, demo, decision notes
```

---

### Task 1: Scaffold, environment loader and DI container

**Files:**
- Create: `composer.json`, `phpunit.xml`, `.gitignore`, `.env.example`
- Create: `src/Core/Env.php`, `src/Core/Container.php`
- Create: `tests/bootstrap.php`, `tests/Unit/Core/EnvTest.php`, `tests/Unit/Core/ContainerTest.php`

**Interfaces:**
- Produces:
  - `Env::load(string $path): void`, `Env::get(string $key, ?string $default = null): ?string`, `Env::require(string $key): string`, `Env::bool(string $key, bool $default = false): bool`
  - `Container::set(string $id, callable $factory): void`, `Container::has(string $id): bool`, `Container::get(string $id): object`
  - Constructor autowiring: unregistered class ids resolve class-typed constructor parameters by type and scalar parameters only when they have defaults.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Core/EnvTest.php`:

```php
public function test_loads_values_and_ignores_comments(): void
{
    $path = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($path, "APP_NAME=\"Vending Machine\"\n# nope\nDB_PORT=3307\nEMPTY=\n");
    Env::load($path);
    self::assertSame('Vending Machine', Env::get('APP_NAME'));
    self::assertSame('3307', Env::get('DB_PORT'));
    self::assertSame('', Env::get('EMPTY'));
    unlink($path);
}

public function test_require_throws_for_missing_key(): void
{
    $this->expectException(RuntimeException::class);
    Env::require('DEFINITELY_MISSING_KEY');
}
```

`tests/Unit/Core/ContainerTest.php` uses fixture classes in the same file:

```php
public function test_resolves_registered_singleton(): void
{
    $c = new Container();
    $c->set('answer', fn () => new ArrayObject([42]));
    self::assertSame($c->get('answer'), $c->get('answer'));
}

public function test_autowires_constructor_dependencies(): void
{
    $c = new Container();
    $c->set(ArrayObject::class, fn () => new ArrayObject([1]));
    $resolved = $c->get(ContainerFixtureConsumer::class);
    self::assertInstanceOf(ArrayObject::class, $resolved->items);
}
```

Add a missing-dependency test expecting `RuntimeException`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/Core`  
Expected: FAIL — `Env`/`Container` classes not found.

- [ ] **Step 3: Create the scaffold and implementations**

`composer.json` exact requirements: `php ^8.3`, `ext-pdo`, `firebase/php-jwt ^6.10`; dev `phpunit/phpunit ^11.5`; scripts `test` → `phpunit`, `migrate` → `php bin/migrate.php`, `seed` → `php bin/seed.php`. PSR-4 as in Global Constraints.

`phpunit.xml`: bootstrap `tests/bootstrap.php`, testsuites `Unit` (`tests/Unit`) and `Integration` (`tests/Integration`), env values for `APP_ENV=testing`, `DB_HOST=127.0.0.1`, `DB_PORT=3307`, `DB_DATABASE=vending_test`, `DB_USERNAME=vending`, `DB_PASSWORD=secret`, `JWT_SECRET=test-secret`.

`.gitignore`: `/vendor/`, `.env`, `.phpunit.cache/`, `storage/logs/*.log`, `.DS_Store`.

`.env.example`: `APP_ENV=local`, `APP_URL=http://localhost:8080`, `DB_HOST=127.0.0.1`, `DB_PORT=3307`, `DB_DATABASE=vending`, `DB_USERNAME=vending`, `DB_PASSWORD=secret`, `JWT_SECRET=change-me`.

Implement `Env` with `parse_ini_file($path, false, INI_SCANNER_TYPED)` fallback to manual `KEY=VALUE` parsing, stripping surrounding quotes; `load` writes to `$_ENV` and `putenv`. Implement `Container` with a `$instances` cache and `ReflectionClass` autowiring; throw `RuntimeException("Cannot resolve {$id}")` when a required class parameter is unregistered.

- [ ] **Step 4: Run install and tests**

Run: `composer install && vendor/bin/phpunit tests/Unit/Core`  
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add composer.json composer.lock phpunit.xml .gitignore .env.example src/Core tests
git commit -m "feat: scaffold project with env loader and DI container"
```

---

### Task 2: HTTP primitives, session, CSRF and helpers

**Files:**
- Create: `src/Core/Request.php`, `src/Core/Response.php`, `src/Core/SessionInterface.php`, `src/Core/ArraySession.php`, `src/Core/NativeSession.php`, `src/Core/Csrf.php`, `src/Support/helpers.php`
- Test: `tests/Unit/Core/RequestTest.php`, `ResponseTest.php`, `SessionTest.php`, `CsrfTest.php`
- Modify: `composer.json` (autoload `files: ["src/Support/helpers.php"]`)

**Interfaces:**
- Produces:
  - `Request::__construct(string $method, string $path, array $query = [], array $body = [], array $headers = [], array $cookies = [], array $attributes = [])`; `fromGlobals()`, `input(string $key, mixed $default = null)`, `query(string $key, mixed $default = null)`, `header(string $name, ?string $default = null)`, `attribute(string $key, mixed $default = null)`, `withAttribute(string $key, mixed $value): self`, `isJson()`, `expectsJson()`
  - `Response::__construct(string $body = '', int $status = 200, array $headers = [])`; static `html`, `json(array $data, int $status = 200)`, `error(string $code, string $message, int $status, array $details = [])`, `redirect(string $url, int $status = 302)`, `noContent()`; `withHeader`, `body`, `status`, `headers`, `send`
  - `SessionInterface` (`get`, `set`, `remove`, `regenerate`, `destroy`, `flash`, `pullFlash`)
  - `Csrf::__construct(SessionInterface $session)`, `token()`, `check(?string $token)`, `field()`
  - Global `e(?string $value): string`

- [ ] **Step 1: Write the failing tests**

```php
// RequestTest
public function test_normalizes_method_and_reads_inputs(): void
{
    $r = new Request('post', '/products?x=1', ['x' => '1'], ['quantity' => '2'], ['Content-Type' => 'application/json']);
    self::assertSame('POST', $r->method);
    self::assertSame('1', $r->query('x'));
    self::assertSame('2', $r->input('quantity'));
    self::assertTrue($r->isJson());
    self::assertSame('/other', $r->withAttribute('next', '/other')->attribute('next'));
}

// ResponseTest
public function test_json_error_shape(): void
{
    $r = Response::error('not_found', 'No such product.', 404);
    self::assertSame(404, $r->status());
    self::assertSame(
        ['error' => ['code' => 'not_found', 'message' => 'No such product.']],
        json_decode($r->body(), true)
    );
}

// CsrfTest
public function test_rejects_wrong_token(): void
{
    $csrf = new Csrf(new ArraySession());
    self::assertFalse($csrf->check('wrong'));
    self::assertTrue($csrf->check($csrf->token()));
}
```

Additional cases: flash pulls once, header lookup is case-insensitive, invalid JSON body falls back to `[]`, `Response::redirect` sets `Location`, `ArraySession::regenerate` changes its id (concrete `id()` accessor for tests), `expectsJson()` true for `/api/` paths.

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit tests/Unit/Core`  
Expected: FAIL — classes missing.

- [ ] **Step 3: Implement**

- `Request::fromGlobals()` uppercases method, parses `php://input` for JSON or urlencoded PUT/PATCH/DELETE, lowercases header keys.
- `Response` methods return clones from `withHeader`; `json()` sets `Content-Type: application/json`; `error()` nests as specified.
- `ArraySession` stores id, data, flash bag; `NativeSession` uses `session_start()` with cookie params and delegates.
- `Csrf::token()` stores `_csrf` in session, 32 random bytes hex; `check()` uses `hash_equals`; `field()` returns `'<input type="hidden" name="_token" value="' . e($token) . '">'`.
- helpers: `e()` uses `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.

- [ ] **Step 4: Run tests**

Run: `composer dump-autoload && vendor/bin/phpunit tests/Unit/Core`  
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add composer.json src/Core src/Support tests/Unit/Core
git commit -m "feat: add HTTP, session and CSRF primitives"
```

---

### Task 3: Attribute router, view layer, middleware pipeline and application error handling

**Files:**
- Create: `src/Core/Route.php`, `src/Core/Middleware.php`, `src/Core/MiddlewareInterface.php`, `src/Core/RouteDefinition.php`, `src/Core/Router.php`, `src/Core/View.php`, `src/Core/HttpException.php`, `src/Core/ValidationException.php`, `src/Core/Application.php`
- Create: `public/index.php`, `public/.htaccess`, `bootstrap/app.php`, `routes/web.php`
- Create: `src/Views/errors/403.php`, `404.php`, `419.php`, `422.php`, `500.php`, `storage/logs/.gitkeep`
- Create: `tests/Fixtures/views/layouts/app.php`, `tests/Fixtures/views/hello.php`, `tests/Fixtures/FixtureController.php`, `tests/Fixtures/RecordingMiddleware.php`
- Test: `tests/Unit/Core/RouterTest.php`, `ViewTest.php`, `ApplicationTest.php`

**Interfaces:**
- Produces:
  - `#[Route(string $method, string $path, ?string $name = null)]` (repeatable, methods only)
  - `#[Middleware(string $spec)]` (repeatable; `auth`, `csrf`, `role:admin`, `api_auth`)
  - `MiddlewareInterface::handle(Request $request, callable $next): Response`
  - `Router::__construct(Container $container)`, `addController(string $class): void`, `useGlobal(MiddlewareInterface $middleware): void`, `middleware(string $name, callable $factory): void`, `dispatch(Request $request): Response`, `url(string $name, array $params = []): string`, `routes(): array<RouteDefinition>`
  - `RouteDefinition` readonly: `method`, `path`, `name`, `middleware` (string list), `controller`, `action`
  - `View::__construct(string $basePath, Router $router, SessionInterface $session, Csrf $csrf)`, `share(string $key, mixed $value): void`, `render(string $template, array $data = [], int $status = 200): Response`
  - `HttpException::__construct(int $status, string $message = '', array $headers = [])`
  - `ValidationException::__construct(array $errors)`, `errors(): array<string, list<string>>`
  - `Application::__construct(Router $router, View $view)`, `handle(Request $request): Response`

- [ ] **Step 1: Write the failing router tests**

```php
public function test_matches_attribute_route_and_extracts_parameter(): void
{
    $router = $this->routerWithFixture();
    $response = $router->dispatch(new Request('GET', '/greet/ada'));
    self::assertSame('Hello ada', $response->body());
}

public function test_returns_405_with_allow_header(): void
{
    $router = $this->routerWithFixture();
    try {
        $router->dispatch(new Request('POST', '/greet/ada'));
        self::fail('Expected 405');
    } catch (HttpException $e) {
        self::assertSame(405, $e->status);
        self::assertSame('GET', $e->headers['Allow']);
    }
}

public function test_middleware_runs_in_declared_order_and_can_short_circuit(): void
{
    RecordingMiddleware::$calls = [];
    $router = $this->routerWithFixture();
    $router->useGlobal(new RecordingMiddleware('global'));
    $router->dispatch(new Request('GET', '/guarded'));
    self::assertSame(['global', 'first', 'second'], RecordingMiddleware::$calls);
}

public function test_url_generation_uses_route_parameters_and_query(): void
{
    $router = $this->routerWithFixture();
    self::assertSame('/greet/ada?x=1', $router->url('fixture.greet', ['name' => 'ada', 'x' => 1]));
}
```

`ApplicationTest`: HTML 404 body contains `Not found`; `/api/nope` returns JSON with `error.code === 'not_found'`; `ValidationException` becomes 422 JSON; unexpected exception becomes 500 without leaking the message.

`ViewTest`:

```php
public function test_renders_layout_and_escapes_output(): void
{
    $router = new Router(new Container());
    $view = new View(__DIR__ . '/../Fixtures/views', $router, new ArraySession(), new Csrf(new ArraySession()));
    $response = $view->render('hello', ['name' => '<b>Ada</b>']);
    self::assertStringContainsString('&lt;b&gt;Ada&lt;/b&gt;', $response->body());
    self::assertStringContainsString('Layout', $response->body());
}

public function test_shared_data_is_available_to_templates(): void
{
    $router = new Router(new Container());
    $view = new View(__DIR__ . '/../Fixtures/views', $router, new ArraySession(), new Csrf(new ArraySession()));
    $view->share('brand', 'Vending');
    self::assertStringContainsString('Vending', $view->render('hello', ['name' => 'Ada'])->body());
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit tests/Unit/Core/RouterTest.php tests/Unit/Core/ApplicationTest.php`  
Expected: FAIL.

- [ ] **Step 3: Implement**

- Compile attributes once per controller: route path → regex (`{name}` → `(?P<name>[^/]+)`, `{name:\d+}` → `(?P<name>\d+)`), middleware specs split on first `:`.
- `dispatch()` iterates definitions in registration order; path match + method match; collect allowed methods for 405.
- Middleware factories are keyed by name: exact match first, then prefix before `:` with the argument. Unknown spec throws `RuntimeException`.
- Build the pipeline in reverse so globals run first, then the first route attribute runs before the second. Middleware gets `$next` closing over the remaining chain.
- Invoke handlers with reflection: `Request` parameters receive the request; scalar parameters receive named route params **cast to the declared type** (`int`, `float`, `bool`, `string`; strict types are on); class parameters resolve from the container.
- `Application::handle()` catches `ValidationException` (422), `HttpException` (page/JSON, preserving headers), and `Throwable` (log to `storage/logs/app.log`, 500). It does not mutate view state. JSON error codes map from status: 401 `unauthorized`, 403 `forbidden`, 404 `not_found`, 405 `method_not_allowed`, 419 `csrf_failed`, 422 `validation_failed`, 500 `server_error`.
- `url()` replaces path params and appends remaining params with `http_build_query`.
- `View::render()` extracts shares and data (data wins), captures the template, then captures `layouts/app.php` with `$content`; restores state after render. Shares include `router`, `csrf`, `session`.
- Front controller loads `.env` when present, requires `bootstrap/app.php`, dispatches `Request::fromGlobals()`, sends the response.
- `.htaccess` rewrites everything to `index.php` and denies dotfiles.
- Bootstrap here wires `Container`, `SessionInterface` → `NativeSession`, `Csrf`, `Router`, `View` (base path `src/Views`), `Application`; loads `routes/web.php` (empty array for now).
- Error views are simple full pages using inline minimal markup; Task 9 introduces the shared layout and they are restyled in Task 14.

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit tests/Unit/Core`  
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add public bootstrap routes src/Core src/Views/errors storage tests
git commit -m "feat: add attribute router, view layer, middleware pipeline and error handling"
```

---

### Task 4: Database connection, migrations, seed data and Docker runtime

**Files:**
- Create: `src/Database/Connection.php`, `src/Database/Migrator.php`, `src/Database/Seeder.php`, `src/Database/TransactionManagerInterface.php`, `src/Database/PdoTransactionManager.php`
- Create: `database/migrations/001_users.sql`, `002_products.sql`, `003_transactions.sql`, `database/seed.php`, `bin/migrate.php`, `bin/seed.php`
- Create: `Dockerfile`, `docker-compose.yml`, `docker/apache.conf`, `docker/entrypoint.sh`, `docker/mysql-init.sql`, `.dockerignore`
- Create: `tests/Integration/IntegrationTestCase.php`, `tests/Integration/MigrationTest.php`, `tests/Integration/SeederTest.php`, `tests/Integration/TransactionManagerTest.php`
- Modify: `bootstrap/app.php` (bind `Connection` and `TransactionManagerInterface`)

**Interfaces:**
- Produces:
  - `Connection::__construct(PDO $pdo)`, `Connection::fromEnv(): self` (reads `$_ENV`, `getenv`, then `$_SERVER`), `Connection::pdo(): PDO`
  - `Migrator::__construct(Connection $connection, string $migrationsPath)`, `Migrator::run(): array` (applied filenames, idempotent)
  - `Seeder::__construct(Connection $connection)`, `Seeder::run(): void` (idempotent)
  - `TransactionManagerInterface::transactional(callable $callback): mixed`; `PdoTransactionManager::__construct(Connection $connection)`
  - `IntegrationTestCase` with `protected Connection $db` and truncation of `transactions`, `products`, `users` before each test.

- [ ] **Step 1: Write the failing integration tests**

```php
public function test_migrations_apply_once_and_are_tracked(): void
{
    $migrator = new Migrator($this->db, dirname(__DIR__, 2) . '/database/migrations');
    $first = $migrator->run();
    self::assertSame(['001_users.sql', '002_products.sql', '003_transactions.sql'], $first);
    self::assertSame([], $migrator->run());
}

public function test_seed_is_idempotent_and_hashes_passwords(): void
{
    (new Seeder($this->db))->run();
    (new Seeder($this->db))->run();
    $pdo = $this->db->pdo();
    self::assertSame(3, (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn());
    self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    $hash = $pdo->query("SELECT password_hash FROM users WHERE email = 'admin@vending.test'")->fetchColumn();
    self::assertTrue(password_verify('password', $hash));
}

public function test_database_rejects_negative_price(): void
{
    $this->expectException(PDOException::class);
    $this->db->pdo()->exec("INSERT INTO products (name, slug, price, quantity_available) VALUES ('Bad', 'bad', -1, 0)");
}

public function test_transaction_manager_commits_and_rolls_back(): void
{
    $manager = new PdoTransactionManager($this->db);
    $value = $manager->transactional(fn () => 'ok');
    self::assertSame('ok', $value);
    try {
        $manager->transactional(function (): void {
            $this->db->pdo()->exec("INSERT INTO users (name, email, password_hash, role) VALUES ('X','x@test','h','user')");
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }
    self::assertSame(0, (int) $this->db->pdo()->query("SELECT COUNT(*) FROM users WHERE email = 'x@test'")->fetchColumn());
}
```

`IntegrationTestCase::setUp()` runs migrations once per test process, then truncates `transactions`, `products`, `users` (FK checks off/on). `MigrationTest` starts by dropping those tables plus `migrations` so it can assert the first run, then re-runs migrations for the rest of that class.

- [ ] **Step 2: Start MySQL and run tests to verify failure**

Run: `docker compose up -d db && vendor/bin/phpunit --testsuite Integration`  
Expected: FAIL — classes and migrations missing.

- [ ] **Step 3: Implement schema, migrator and seeder**

Migration SQL exactly as specified:

- `users`: `id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`, `name VARCHAR(100) NOT NULL`, `email VARCHAR(190) NOT NULL UNIQUE`, `password_hash VARCHAR(255) NOT NULL`, `role ENUM('admin','user') NOT NULL DEFAULT 'user'`, `is_active TINYINT(1) NOT NULL DEFAULT 1`, timestamps.
- `products`: `id`, `name VARCHAR(100)`, `slug VARCHAR(120) NOT NULL UNIQUE`, `description TEXT NULL`, `price DECIMAL(10,3) UNSIGNED NOT NULL`, `quantity_available INT UNSIGNED NOT NULL DEFAULT 0`, `is_active TINYINT(1) NOT NULL DEFAULT 1`, timestamps, `CHECK (price >= 0)`, `CHECK (quantity_available >= 0)`.
- `transactions`: `id`, `user_id` FK → users `ON DELETE RESTRICT`, `product_id` FK → products `ON DELETE RESTRICT`, `product_name VARCHAR(100)`, `unit_price DECIMAL(10,3)`, `quantity INT UNSIGNED`, `total_price DECIMAL(12,3)`, `created_at`, indexes `(user_id, created_at)` and `(product_id)`, `CHECK (quantity > 0)`.
- All tables `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`.

`Migrator` creates `migrations(filename VARCHAR(255) PRIMARY KEY, applied_at TIMESTAMP)`, sorts files, skips applied, wraps each file in a transaction.

`Seeder` upserts by unique key (`slug`, `email`): products Coke 3.990 qty 12, Pepsi 6.885 qty 8, Water 0.500 qty 24, all active with short descriptions; users Admin/User with `password_hash('password', PASSWORD_DEFAULT)` and roles admin/user.

`PdoTransactionManager` opens a transaction, runs the callback, commits; on `Throwable` it rolls back if still in transaction and rethrows.

- [ ] **Step 4: Add Docker runtime**

- `Dockerfile`: `php:8.3-apache`, `docker-php-ext-install pdo_mysql`, `a2enmod rewrite`, Composer 2 from the official image, copy the project, `composer install --no-interaction --prefer-dist` (copy before install so the `files` autoload entry resolves), `docker/apache.conf` with `DocumentRoot /var/www/html/public`, entrypoint.
- `docker-compose.yml`: `db` (mysql:8.4, port `3307:3306`, healthcheck, volume `docker/mysql-init.sql` to `/docker-entrypoint-initdb.d/`), `app` (build ., port `8080:80`, `DB_HOST=db`, port 3306, database `vending`, same credentials, `JWT_SECRET`, depends_on db healthy).
- `docker/mysql-init.sql`: create `vending_test`, grant it to `vending`.
- `docker/entrypoint.sh`: wait for PDO connection with retries, run `php bin/migrate.php`, `php bin/seed.php`, then `apache2-foreground`.
- `.dockerignore`: `vendor`, `.git`, `.env`, `docs`.

- [ ] **Step 5: Run tests and verify containers**

Run: `vendor/bin/phpunit --testsuite Integration`  
Expected: PASS.  
Run: `docker compose config` and `docker compose up -d db`  
Expected: config prints; db becomes healthy.

- [ ] **Step 6: Commit**

```bash
git add database bin src/Database Dockerfile docker-compose.yml docker .dockerignore tests/Integration bootstrap
git commit -m "feat: add database schema, migrations, seed data and Docker runtime"
```

---

### Task 5: Product model and repository

**Files:**
- Create: `src/Models/Product.php`, `src/Models/PaginatedResult.php`
- Create: `src/Repositories/ProductRepositoryInterface.php`, `src/Repositories/PdoProductRepository.php`
- Test: `tests/Integration/ProductRepositoryTest.php`
- Modify: `bootstrap/app.php` (bind `ProductRepositoryInterface`)

**Interfaces:**
- Produces:
  - `Product` readonly: `id`, `name`, `slug`, `?description`, `price` (decimal string), `quantityAvailable` (int), `isActive` (bool), `createdAt`, `updatedAt`; `Product::fromRow(array $row): self`
  - `PaginatedResult` readonly: `items` (array), `total`, `page`, `perPage`; `lastPage(): int`, `hasPrevious(): bool`, `hasNext(): bool`
  - `ProductRepositoryInterface::paginate(array $options = []): PaginatedResult` where options are `page`, `per_page`, `sort`, `direction`, `search`, `include_inactive`
  - `findById(int $id): ?Product`, `findBySlug(string $slug): ?Product`, `findBySlugForUpdate(string $slug): ?Product`, `create(array $data): Product`, `update(int $id, array $data): Product`, `softDelete(int $id): void`, `restore(int $id): void`, `decrementStock(int $id, int $quantity): void`, `slugExists(string $slug, ?int $ignoreId = null): bool`

- [ ] **Step 1: Write the failing integration tests**

```php
public function test_paginates_sorts_and_searches(): void
{
    $this->seedProducts();
    $repo = new PdoProductRepository($this->db);
    $page = $repo->paginate(['page' => 1, 'per_page' => 2, 'sort' => 'price', 'direction' => 'desc']);
    self::assertSame(['Pepsi', 'Coke'], array_map(fn (Product $p) => $p->name, $page->items));
    self::assertSame(3, $page->total);
    self::assertTrue($page->hasNext());
    self::assertSame(1, $repo->paginate(['search' => 'water'])->total);
}

public function test_hostile_sort_and_page_values_are_clamped(): void
{
    $this->seedProducts();
    $repo = new PdoProductRepository($this->db);
    $page = $repo->paginate(['sort' => 'price; DROP TABLE users', 'direction' => 'sideways', 'page' => -4, 'per_page' => 9999]);
    self::assertSame(1, $page->page);
    self::assertSame(50, $page->perPage);
}

public function test_soft_delete_hides_from_public_but_not_admin(): void
{
    $product = $this->seedProducts()[0];
    $repo = new PdoProductRepository($this->db);
    self::assertTrue($product->isActive);
    $repo->softDelete($product->id);
    self::assertNull($repo->findBySlug($product->slug));
    self::assertFalse($repo->findById($product->id)->isActive);
    self::assertSame(2, $repo->paginate([])->total);
    self::assertSame(3, $repo->paginate(['include_inactive' => true])->total);
}
```

Additional cases: `findBySlugForUpdate` inside a transaction locks the row; `decrementStock` at exactly available quantity leaves 0; decrement past stock throws `RuntimeException`; slug uniqueness ignores the edited id; page beyond last returns empty items.

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit tests/Integration/ProductRepositoryTest.php`  
Expected: FAIL.

- [ ] **Step 3: Implement**

- `fromRow` casts `price` to string, `quantity_available` to int, `is_active` to bool.
- `paginate`: clamp `page >= 1`, `per_page` 1–50; whitelist sort to `name|price|quantity_available|created_at`, direction to `asc|desc`; default `name asc`. Exclude `is_active = 0` unless `include_inactive`. Search uses `name LIKE :search OR slug LIKE :search` with escaped `%`, `_`, `\`.
- `findBySlug` only returns active products; admin lookup uses `findById`.
- `decrementStock` executes `UPDATE ... SET quantity_available = quantity_available - :q WHERE id = :id AND quantity_available >= :q` and throws `RuntimeException('Insufficient stock')` when `rowCount() === 0`.
- Use explicit column lists and `PDO::PARAM_INT` for limits.

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit tests/Integration/ProductRepositoryTest.php`  
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Models src/Repositories tests/Integration/ProductRepositoryTest.php bootstrap/app.php
git commit -m "feat: add product model and PDO repository"
```

---

### Task 6: User and transaction models and repositories

**Files:**
- Create: `src/Models/User.php`, `src/Models/Transaction.php`
- Create: `src/Repositories/UserRepositoryInterface.php`, `PdoUserRepository.php`, `TransactionRepositoryInterface.php`, `PdoTransactionRepository.php`
- Test: `tests/Integration/UserRepositoryTest.php`, `TransactionRepositoryTest.php`
- Modify: `bootstrap/app.php` (bind both interfaces)

**Interfaces:**
- Produces:
  - `User` readonly: `id`, `name`, `email`, `passwordHash`, `role`, `isActive`, `createdAt`; `User::ROLE_ADMIN = 'admin'`, `ROLE_USER = 'user'`, `isAdmin(): bool`
  - `Transaction` readonly: `id`, `userId`, `productId`, `productName`, `unitPrice`, `quantity`, `totalPrice`, `createdAt`, `?buyerName`
  - `UserRepositoryInterface::paginate(int $page, int $perPage, string $search = ''): PaginatedResult`, `findById`, `findByEmail`, `create(array $data)`, `update(int $id, array $data)`, `softDelete(int $id)`, `restore(int $id)`, `countActiveAdmins(): int`, `emailExists(string $email, ?int $ignoreId = null): bool`
  - `TransactionRepositoryInterface::create(array $data): Transaction`, `findById(int $id): ?Transaction`, `paginate(int $page, int $perPage, ?int $userId = null): PaginatedResult`

- [ ] **Step 1: Write the failing tests**

```php
public function test_creates_and_finds_user_by_lowercased_email(): void
{
    $repo = new PdoUserRepository($this->db);
    $user = $repo->create([
        'name' => 'Ada', 'email' => 'ada@example.test', 'password_hash' => 'hash',
        'role' => User::ROLE_USER, 'is_active' => 1,
    ]);
    self::assertSame('ada@example.test', $repo->findByEmail('ADA@example.test')?->email);
    self::assertTrue($repo->findById($user->id)->isActive);
}

public function test_last_admin_count_ignores_inactive_admins(): void
{
    $repo = new PdoUserRepository($this->db);
    $this->createUser($repo, 'a@test', User::ROLE_ADMIN, 1);
    $this->createUser($repo, 'b@test', User::ROLE_ADMIN, 0);
    self::assertSame(1, $repo->countActiveAdmins());
}

public function test_transaction_paginates_for_one_user_with_buyer_name(): void
{
    [$user, $product] = $this->seedUserAndProduct();
    $repo = new PdoTransactionRepository($this->db);
    $repo->create([
        'user_id' => $user->id, 'product_id' => $product->id, 'product_name' => $product->name,
        'unit_price' => '3.990', 'quantity' => 2, 'total_price' => '7.980',
    ]);
    $page = $repo->paginate(1, 10, $user->id);
    self::assertSame('3.990', $page->items[0]->unitPrice);
    self::assertSame($user->name, $page->items[0]->buyerName);
}
```

Additional cases: duplicate email rejected by DB; `update` preserves password when key absent; soft delete then `findByEmail` still returns the inactive user (auth checks `isActive`); transactions order newest first; `scope` by user filters.

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --testsuite Integration --filter 'UserRepository|TransactionRepository'`  
Expected: FAIL.

- [ ] **Step 3: Implement**

- User queries lowercase email on write and lookup; create/update accept `password_hash`; update only writes the keys present.
- `countActiveAdmins` counts `role='admin' AND is_active=1`.
- Transaction `create` inserts then re-reads via join; `paginate` joins users for `buyer_name`, orders `created_at DESC, id DESC`, optionally filters `user_id`.
- Transaction records are append-only: no update/delete methods; state this in `docs/REQUIREMENTS.md` for task 2.

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit --testsuite Integration`  
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Models src/Repositories tests/Integration bootstrap/app.php
git commit -m "feat: add user and transaction repositories"
```

---

### Task 7: Validation rules, money and slug utilities

**Files:**
- Create: `src/Core/Validator.php`, `src/Support/Money.php`, `src/Support/Str.php`, `src/Support/Rules.php`
- Test: `tests/Unit/Core/ValidatorTest.php`, `tests/Unit/Support/MoneyTest.php`, `StrTest.php`

**Interfaces:**
- Produces:
  - `Validator::validate(array $data, array $rules): array` (validated subset, throws `ValidationException` from Task 3)
  - `Rules::product(): array`, `Rules::purchase(): array`, `Rules::userCreate(): array`, `Rules::userUpdate(): array`
  - `Money::toMills(string $amount): int`, `Money::fromMills(int $mills): string`, `Money::multiply(string $amount, int $quantity): string`, `Money::format(string $amount): string`
  - `Str::slug(string $value): string`
  - Global `money(string $amount): string` helper (`'USD ' . Money::format($amount)`)

- [ ] **Step 1: Write the failing tests**

```php
public function test_purchase_quantity_must_be_a_positive_integer(): void
{
    try {
        (new Validator())->validate(['quantity' => '0'], Rules::purchase());
        self::fail('Expected validation failure');
    } catch (ValidationException $e) {
        self::assertSame(['Quantity must be at least 1.'], $e->errors()['quantity']);
    }
}

public function test_price_rejects_fourth_decimal_and_negative_values(): void
{
    $validator = new Validator();
    foreach (['6.8855', '-1'] as $price) {
        try { $validator->validate(['price' => $price], ['price' => ['required', 'numeric', 'regex:/^\d+(\.\d{1,3})?$/']]); self::fail(); }
        catch (ValidationException) { self::assertTrue(true); }
    }
}

public function test_money_multiplies_without_float_error(): void
{
    self::assertSame('20.655', Money::multiply('6.885', 3));
    self::assertSame('USD 20.655', money('20.655'));
    self::assertSame('USD 3.99', money('3.990'));
    self::assertSame('USD 0.50', money('0.500'));
}

public function test_slugifies_product_names(): void
{
    self::assertSame('diet-coke-330ml', Str::slug('Diet Coke — 330ml'));
}
```

Additional cases: required/email/max/in rules; error map keys; `Money::multiply` rejects >3 decimals; zero and negative prices fail `min:0.001`; user password min 8; role `in:admin,user`.

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit tests/Unit/Core/ValidatorTest.php tests/Unit/Support`  
Expected: FAIL.

- [ ] **Step 3: Implement**

Rules exact strings:

```php
Rules::product(): [
    'name' => ['required', 'string', 'min:2', 'max:100'],
    'slug' => ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
    'description' => ['nullable', 'string', 'max:2000'],
    'price' => ['required', 'numeric', 'regex:/^\d+(\.\d{1,3})?$/', 'min:0.001', 'max:9999.999'],
    'quantity_available' => ['required', 'integer', 'min:0', 'max:100000'],
    'is_active' => ['boolean'],
]
Rules::purchase(): ['quantity' => ['required', 'integer', 'min:1', 'max:1000']]
Rules::userCreate(): name, email, role in:admin,user, password min:8 max:255, is_active boolean
Rules::userUpdate(): same but password => ['nullable', 'string', 'min:8', 'max:255']
```

Messages: `"Name is required."`, `"Price must be greater than zero."`, `"Quantity must be at least 1."`, etc. `nullable` skips other rules for `null`/`''`.

`Money` parses sign, integer and up to 3 decimals into mills (int), rejects malformed/too-precise input with `InvalidArgumentException`; `format` renders 3 decimals then trims to at least 2 (strip trailing zeros while >2 decimals remain). `Str::slug` lowercases, transliterates non-alphanumerics to `-`, trims duplicates.

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit tests/Unit`  
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Core src/Support tests/Unit
git commit -m "feat: add validation rules, money arithmetic and slug helper"
```

---

### Task 8: Authentication service, login flow and RBAC middleware

**Files:**
- Create: `src/Services/AuthService.php`, `src/Services/PasswordHasherInterface.php`, `src/Services/NativePasswordHasher.php`
- Create: `src/Http/Middleware/ResolveUser.php`, `SessionAuth.php`, `RequireRole.php`, `VerifyCsrf.php`
- Create: `src/Http/Controllers/AuthController.php`, `src/Views/auth/login.php`
- Test: `tests/Unit/Services/AuthServiceTest.php`, `tests/Unit/Http/MiddlewareTest.php`, `tests/Unit/Http/AuthControllerTest.php`
- Modify: `bootstrap/app.php` (bind auth services, register `ResolveUser` as a global middleware and the auth/role/csrf middleware factories; add `AuthController` to `routes/web.php`)

**Interfaces:**
- Produces:
  - `PasswordHasherInterface::hash(string $plain): string`, `verify(string $plain, string $hash): bool`
  - `AuthService::__construct(UserRepositoryInterface $users, PasswordHasherInterface $hasher, SessionInterface $session)`, `verifyCredentials(string $email, string $password): ?User`, `attempt(string $email, string $password): bool`, `user(): ?User`, `check(): bool`, `logout(): void`
  - `ResolveUser::__construct(AuthService $auth, View $view)`, `handle()` — global middleware: when a session user exists, sets the `user` request attribute and shares `authUser` with the view; otherwise shares null
  - `SessionAuth::handle()` — redirects to `/login?next=<path>` when the request has no `user`
  - `RequireRole::__construct(string $role)` — 403 when the request user's role differs
  - `VerifyCsrf::handle()` — 419 for state-changing requests with a bad/missing token
  - `AuthController::showLogin(Request)`, `login(Request)`, `logout()`

- [ ] **Step 1: Write the failing tests**

```php
public function test_attempt_regenerates_session_and_stores_user(): void
{
    $user = $this->user(active: true, hash: password_hash('secret', PASSWORD_DEFAULT));
    $users = $this->createMock(UserRepositoryInterface::class);
    $users->method('findByEmail')->with('ada@test')->willReturn($user);
    $session = new ArraySession();
    $before = $session->id();
    $auth = new AuthService($users, new NativePasswordHasher(), $session);
    self::assertTrue($auth->attempt('ada@test', 'secret'));
    self::assertSame($user->id, $session->get('auth_user_id'));
    self::assertNotSame($before, $session->id());
}

public function test_inactive_user_cannot_authenticate(): void
{
    $user = $this->user(active: false, hash: password_hash('secret', PASSWORD_DEFAULT));
    $users = $this->createMock(UserRepositoryInterface::class);
    $users->method('findByEmail')->willReturn($user);
    $auth = new AuthService($users, new NativePasswordHasher(), new ArraySession());
    self::assertFalse($auth->attempt('ada@test', 'secret'));
    self::assertNull($auth->verifyCredentials('ada@test', 'secret'));
}
```

Middleware tests: `ResolveUser` puts an active session user on the request and shares it; unauth `SessionAuth` returns 302 to `/login?next=/admin`; `RequireRole('admin')` with a user role throws 403; `VerifyCsrf` throws 419 on POST without a token and passes with one.

AuthController tests: missing fields 422 with errors; bad credentials 422 with `Invalid email or password.`; success 302 to `/products`; `next=https://evil.test` ignored in favour of `/products`; logout destroys the session.

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit tests/Unit/Services tests/Unit/Http`  
Expected: FAIL.

- [ ] **Step 3: Implement**

- `verifyCredentials` lowercases/trims email, returns only active users with a valid hash.
- `attempt` calls `verifyCredentials`, regenerates the session, stores `auth_user_id`, returns bool.
- `user()` loads by session id and returns null for inactive accounts; cache within the request.
- `ResolveUser` runs globally, shares `authUser` and sets `user` on the request; `SessionAuth` only checks the attribute and redirects, preserving the path in `next`.
- `RequireRole` reads `user`; 401 when absent, 403 on mismatch.
- `VerifyCsrf` skips `GET`/`HEAD`/`OPTIONS`, reads `_token` or `X-CSRF-Token`, throws 419.
- `AuthController::login` validates required email/password, uses a generic failure message, accepts only local `next` paths starting with `/` (not `//`). Its POST route declares `csrf`; login/logout routes are public except logout, which requires `auth`.
- Login view: email/password form, CSRF field, error block, demo credentials note, link back to store.

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit tests/Unit`  
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Services src/Http src/Views/auth tests/Unit bootstrap routes
git commit -m "feat: add session authentication, login flow and RBAC middleware"
```

---

### Task 9: Admin product CRUD with views and RBAC

**Files:**
- Create: `src/Http/Controllers/ProductsController.php` (admin actions)
- Create: `src/Views/layouts/app.php`, `src/Views/partials/nav.php`, `flash.php`, `errors.php`, `pagination.php`
- Create: `src/Views/admin/products/index.php`, `form.php`
- Test: `tests/Unit/Http/ProductsControllerAdminTest.php`, `tests/Unit/Http/RoutesTest.php`
- Modify: `routes/web.php`, `src/Views/errors/*` to use the layout

**Interfaces:**
- Produces:
  - `ProductsController::__construct(ProductRepositoryInterface $products, TransactionRepositoryInterface $transactions, TransactionManagerInterface $tx, View $view, Router $router, SessionInterface $session, Validator $validator)`
  - Admin actions: `adminIndex(Request): Response`, `create(): Response`, `store(Request): Response`, `edit(int $id): Response`, `update(int $id, Request): Response`, `destroy(int $id): Response`
  - Routes: `/admin/products` (GET), `/admin/products/create` (GET), `/admin/products` (POST), `/admin/products/{id:\d+}/edit` (GET), `/admin/products/{id:\d+}` (POST), `/admin/products/{id:\d+}/delete` (POST). Every admin route declares `auth` **then** `role:admin`; writes also declare `csrf`.

- [ ] **Step 1: Write the failing tests**

```php
public function test_store_rejects_invalid_input_and_preserves_old_values(): void
{
    $this->products->expects(self::never())->method('create');
    $response = $this->controller()->store(new Request('POST', '/admin/products', body: [
        'name' => '', 'price' => '-1', 'quantity_available' => '-2',
    ]));
    self::assertSame(422, $response->status());
    self::assertStringContainsString('Name is required.', $response->body());
    self::assertStringContainsString('value="-1"', $response->body());
}

public function test_store_derives_slug_and_redirects_with_flash(): void
{
    $this->products->method('slugExists')->willReturn(false);
    $this->products->expects(self::once())->method('create')->with(self::callback(
        fn (array $d) => $d['slug'] === 'diet-coke' && $d['price'] === '2.500'
    ))->willReturn($this->product());
    $response = $this->controller()->store($this->validProductRequest());
    self::assertSame(302, $response->status());
    self::assertSame('/admin/products', $response->headers()['Location']);
}
```

Additional cases: duplicate slug returns 422 with field error; edit renders existing values; update uses `slugExists($slug, $id)`; destroy calls `softDelete`; missing product returns 404; routes expose `auth`, `role:admin` and `csrf` middleware in that order (`RoutesTest` introspects `Router::routes()`); admin index shows an Inactive badge.

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter 'ProductsControllerAdmin|RoutesTest'`  
Expected: FAIL.

- [ ] **Step 3: Implement controller**

- Normalize `is_active` from checkbox presence (absent → `0`) and derive `slug = Str::slug($input['name'] ?? '')` when the slug field is blank, **before** validation.
- `store`: validate `Rules::product()`; `slugExists` → add error and re-render 422; create with snake_case data; flash `Product created.`; redirect `admin.products.index`.
- `update`: `findById` or 404; derive slug if blank; validate; uniqueness ignoring id; update; flash; redirect.
- `destroy`: `findById` or 404; `softDelete`; flash `Product removed from the store.`; redirect.
- `adminIndex`: `paginate(['include_inactive' => true, 'per_page' => 20])`.
- `View` responses pass `products`, `product`, `errors`, `old`.

- [ ] **Step 4: Build layout and admin views**

Layout: skip link, header nav (store, admin products/users/transactions when admin, login/logout), flash region, `$content`, footer. `nav` uses `$authUser`. Errors partial prints field errors and a global summary. Pagination partial uses `$paginator`, `$router`, `$query` and preserves existing query args.

Admin index: table name/slug/price/stock/status/actions, create button, soft-delete form with `confirm`, empty state. Form: shared create/edit fields, error messages, active checkbox, cancel link.

- [ ] **Step 5: Run tests**

Run: `vendor/bin/phpunit --filter 'ProductsControllerAdmin|RoutesTest'`  
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src routes bootstrap tests
git commit -m "feat: add admin product CRUD with role-protected views"
```

---

### Task 10: Public product list with pagination, sorting and detail view

**Files:**
- Create: `src/Views/products/index.php`, `src/Views/products/show.php`
- Test: `tests/Unit/Http/ProductsControllerStorefrontTest.php`
- Modify: `src/Http/Controllers/ProductsController.php` (add `index`, `show`), `routes/web.php` (controller already registered)

**Interfaces:**
- Produces: `ProductsController::index(Request): Response`, `ProductsController::show(string $slug): Response`, routes `/products` (GET, name `products.index`) and `/products/{slug}` (GET, name `products.show`), plus `/` redirecting to `products.index`.

- [ ] **Step 1: Write the failing tests**

```php
public function test_index_passes_clamped_list_options_and_renders_empty_state(): void
{
    $this->products->expects(self::once())->method('paginate')->with(self::callback(
        fn (array $o) => $o['page'] === 1 && $o['per_page'] === 9 && $o['sort'] === 'name' && $o['direction'] === 'asc'
    ))->willReturn(new PaginatedResult([], 0, 1, 9));
    $response = $this->controller()->index(new Request('GET', '/products', ['page' => '0', 'sort' => 'nope']));
    self::assertSame(200, $response->status());
    self::assertStringContainsString('No products are available', $response->body());
}

public function test_index_renders_products_with_prices_and_buy_links(): void
{
    $this->products->method('paginate')->willReturn(new PaginatedResult([$this->product()], 1, 1, 9));
    $body = $this->controller()->index(new Request('GET', '/products'))->body();
    self::assertStringContainsString('Coke', $body);
    self::assertStringContainsString('USD 3.99', $body);
    self::assertStringContainsString('/products/coke/purchase', $body);
}

public function test_show_returns_404_for_inactive_product(): void
{
    $this->products->method('findBySlug')->with('coke')->willReturn(null);
    $this->expectException(HttpException::class);
    $this->controller()->show('coke');
}
```

Additional: sorting link flips direction; search term preserved; page beyond last shows "No products on this page" with a reset link; inactive products never appear.

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter ProductsControllerStorefrontTest`  
Expected: FAIL.

- [ ] **Step 3: Implement and add views**

- `index`: read `page`, `per_page` (default 9), `sort`, `direction`, `search`; pass options to repository (`include_inactive` omitted); render with `paginator`, `query`, `sort`, `direction`, `search`.
- `show`: 404 when inactive or missing; render product plus "Buy" CTA.
- Storefront index: page heading, search field, sortable column headers, product cards with name, price, stock, buy link, empty state. Detail: name, description, price, stock, buy CTA, back link.

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit --filter ProductsControllerStorefrontTest`  
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src tests
git commit -m "feat: add public product list, pagination, sorting and detail view"
```

---

### Task 11: Purchase flow and receipt

**Files:**
- Create: `src/Views/products/purchase.php`, `src/Views/products/receipt.php`
- Test: `tests/Unit/Http/ProductsControllerPurchaseTest.php`
- Modify: `src/Http/Controllers/ProductsController.php`, `routes/web.php` already registers the controller

**Interfaces:**
- Produces:
  - `ProductsController::purchaseForm(string $slug): Response` — `GET /products/{slug}/purchase`, name `products.purchase`, middleware `auth`
  - `ProductsController::purchase(string $slug, Request): Response` — `POST /products/{slug}/purchase`, name `products.purchase.store`, middleware `auth`, `csrf`
  - `ProductsController::receipt(int $id, Request): Response` — `GET /orders/{id:\d+}`, name `orders.show`, middleware `auth`
  - `private purchaseProduct(string $slug, int $quantity, User $buyer): Transaction` — shared with the API in Task 16

- [ ] **Step 1: Write the failing tests**

```php
public function test_purchase_decrements_stock_and_logs_snapshot(): void
{
    $product = $this->product(price: '6.885', stock: 8);
    $this->setupPurchaseMocks($product);
    $this->products->expects(self::once())->method('decrementStock')->with($product->id, 3);
    $this->transactions->expects(self::once())->method('create')->with(self::callback(
        fn (array $d) => $d['product_name'] === 'Pepsi'
            && $d['unit_price'] === '6.885'
            && $d['total_price'] === '20.655'
            && $d['quantity'] === 3
    ))->willReturn($this->transaction());
    $response = $this->controller()->purchase('pepsi', $this->purchaseRequest(3));
    self::assertSame(302, $response->status());
    self::assertSame('/orders/1', $response->headers()['Location']);
}

public function test_purchase_at_exact_stock_boundary_succeeds(): void
{
    $product = $this->product(price: '3.990', stock: 2);
    $this->setupPurchaseMocks($product);
    $this->products->expects(self::once())->method('decrementStock')->with($product->id, 2);
    $this->controller()->purchase('coke', $this->purchaseRequest(2));
    self::assertTrue(true);
}

public function test_purchase_above_stock_does_not_write(): void
{
    $product = $this->product(price: '3.990', stock: 1);
    $this->setupPurchaseMocks($product);
    $this->products->expects(self::never())->method('decrementStock');
    $this->transactions->expects(self::never())->method('create');
    $response = $this->controller()->purchase('coke', $this->purchaseRequest(2));
    self::assertSame(422, $response->status());
    self::assertStringContainsString('Only 1 left', $response->body());
}

public function test_transaction_exceptions_are_not_swallowed(): void
{
    $product = $this->product();
    $this->products->method('findBySlug')->willReturn($product);
    $this->products->method('findBySlugForUpdate')->willReturn($product);
    $this->tx->method('transactional')->willThrowException(new RuntimeException('DB down'));
    $this->expectException(RuntimeException::class);
    $this->controller()->purchase('coke', $this->purchaseRequest(1));
}
```

`setupPurchaseMocks` makes `transactional` execute its callback, `findBySlug` and `findBySlugForUpdate` return the product, and `decrementStock`/`create` return defaults. `ApplicationTest` already proves the 500 page hides exception text. Additional cases: quantity `0`/`-1`/`abc` re-render 422; inactive product 404; receipt owner 200, other user 403, admin 200; missing receipt 404; purchased product hidden afterwards when stock hits 0 (storefront shows Sold out).

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter ProductsControllerPurchaseTest`  
Expected: FAIL.

- [ ] **Step 3: Implement**

`purchaseProduct`:

1. Fetch a non-locking copy for display: `$display = $this->products->findBySlug($slug)`; null → `HttpException(404)`.
2. `$quantity <= $display->quantityAvailable` or throw `ValidationException(['quantity' => ["Only {$display->quantityAvailable} left."]])` (fail fast before opening a transaction).
3. Inside `$this->tx->transactional(function () use ($slug, $quantity, $buyer)`:
   - `$product = $this->products->findBySlugForUpdate($slug)` — the lock is acquired inside the transaction, null/inactive → `HttpException(404)`;
   - re-check `$quantity <= $product->quantityAvailable` (the stock may have changed since step 1) or throw the same `ValidationException`;
   - `$this->products->decrementStock($product->id, $quantity)`;
   - `return $this->transactions->create([... 'unit_price' => $product->price, 'total_price' => Money::multiply($product->price, $quantity), ...])`.
4. Return the `Transaction`.

`purchase`: validate with `Rules::purchase()`; catch `ValidationException` from rules or stock and re-render `products/purchase` with 422, `product` (from `findBySlug`, or the pre-lock model), `errors`, `old`; on success flash `Purchase complete.` and redirect `orders.show` with the transaction id. Transaction/DB exceptions are **not** caught here — `Application` logs them and renders the 500 page, so the raw message never reaches the browser.

`purchaseForm`: 404 inactive; render summary and quantity field.

`receipt`: `findById` or 404; allow owner or admin, otherwise 403; render receipt.

Views: purchase page shows unit price, stock, quantity selector, live total (`data-unit-price`), error block; receipt shows transaction id, product snapshot, quantity, unit/total price, buyer, timestamp, back link.

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit --filter ProductsControllerPurchaseTest`  
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src tests
git commit -m "feat: add transactional purchase flow and receipt"
```

---

### Task 12: Admin user management with safety guards

**Files:**
- Create: `src/Services/UserPolicy.php`, `src/Http/Controllers/AdminUserController.php`, `src/Views/admin/users/index.php`, `form.php`
- Test: `tests/Unit/Services/UserPolicyTest.php`, `tests/Unit/Http/AdminUserControllerTest.php`
- Modify: `routes/web.php`, `bootstrap/app.php` (bind `UserPolicy`)

**Interfaces:**
- Produces:
  - `UserPolicy::__construct(UserRepositoryInterface $users)`, `assertCanChange(User $actor, User $target, string $newRole, bool $newActive): void` — throws `ValidationException` with key `user`
  - `AdminUserController::__construct(UserRepositoryInterface $users, PasswordHasherInterface $hasher, UserPolicy $policy, View $view, Router $router, SessionInterface $session, Validator $validator)`
  - Actions `index`, `create`, `store`, `edit`, `update`, `destroy`; routes `/admin/users*`, middleware `auth`, `role:admin`, and `csrf` on writes

- [ ] **Step 1: Write the failing policy and controller tests**

```php
public function test_admin_cannot_demote_self(): void
{
    $admin = $this->user(id: 1, role: User::ROLE_ADMIN, active: true);
    $this->expectException(ValidationException::class);
    (new UserPolicy($this->users))->assertCanChange($admin, $admin, User::ROLE_USER, true);
}

public function test_last_active_admin_cannot_be_deactivated(): void
{
    $actor = $this->user(id: 1, role: User::ROLE_ADMIN, active: true);
    $other = $this->user(id: 2, role: User::ROLE_ADMIN, active: true);
    $this->users->method('countActiveAdmins')->willReturn(1);
    $this->expectException(ValidationException::class);
    (new UserPolicy($this->users))->assertCanChange($actor, $other, User::ROLE_ADMIN, false);
}
```

Controller cases: store hashes password once and lowercases email; duplicate email → 422; update with blank password keeps the existing hash; role change persists; destroy soft-deletes; self-deactivation blocked with flash error; index paginates with search; non-admin routes carry `role:admin`.

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter 'UserPolicy|AdminUserController'`  
Expected: FAIL.

- [ ] **Step 3: Implement policy and controller**

- Policy rejects: self-demotion, self-deactivation, and demoting/deactivating the last active admin (count `<= 1`).
- Controller normalizes email to lowercase `trim`, `is_active` from checkbox, blank password on update removes the key.
- `store`: validate `Rules::userCreate()`; `emailExists` → error; hash; create; flash; redirect.
- `update`: find or 404; validate `Rules::userUpdate()`; uniqueness ignoring id; policy with new role/active; optional password re-hash; update; flash.
- `destroy`: find or 404; policy with current role, `newActive = false`; soft delete; flash.
- Views: user table (name, email, role badge, status, actions), create/edit form (role select, active checkbox, optional password hint), errors, pagination.

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit --filter 'UserPolicy|AdminUserController'`  
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src bootstrap routes tests
git commit -m "feat: add admin user management with last-admin guards"
```

---

### Task 13: Admin transaction history

**Files:**
- Create: `src/Http/Controllers/TransactionController.php`, `src/Views/admin/transactions/index.php`
- Test: `tests/Unit/Http/TransactionControllerTest.php`
- Modify: `routes/web.php`

**Interfaces:**
- Produces: `TransactionController::__construct(TransactionRepositoryInterface $transactions, View $view)`; `index(Request): Response`; route `/admin/transactions` (GET, `auth` then `role:admin`), name `admin.transactions.index`.

- [ ] **Step 1: Write the failing test**

```php
public function test_index_paginates_transactions_newest_first(): void
{
    $this->transactions->expects(self::once())->method('paginate')->with(2, 20, null)
        ->willReturn(new PaginatedResult([$this->transaction()], 21, 2, 20));
    $body = $this->controller()->index(new Request('GET', '/admin/transactions', ['page' => '2']))->body();
    self::assertStringContainsString('Ada Lovelace', $body);
    self::assertStringContainsString('USD 7.98', $body);
    self::assertStringContainsString('Pepsi', $body);
}
```

Additional: empty state; page clamp; route requires admin.

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter TransactionControllerTest`  
Expected: FAIL.

- [ ] **Step 3: Implement and add view**

Controller clamps page, fixed 20 per page, no user filter. View: table with id, buyer, product, quantity, unit price, total, timestamp, empty state, pagination.

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit --filter TransactionControllerTest`  
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src routes tests
git commit -m "feat: add admin transaction history"
```

---

### Task 14: Visual design, client-side validation and accessibility pass

**Files:**
- Create: `public/assets/css/app.css`, `public/assets/js/app.js`
- Modify: all views and `src/Views/layouts/app.php` to use the stylesheet/script, polished markup and accessible states

**Interfaces:**
- Produces: static assets served from `/assets/...`; no build step. `app.js` exposes progressive enhancement only — every form works without JS.

- [ ] **Step 1: Load the design skill and lock the visual direction**

Invoke the `ui-ux-pro-max` skill before writing CSS. Translate the spec's direction into tokens: warm off-white surface, near-black ink, one vivid accent, receipt-panel treatment for purchase/receipt, tabular numerals for money, 8-pt spacing scale, two font sizes for display/body from a system stack.

- [ ] **Step 2: Build the stylesheet**

Single `app.css` with `:root` tokens, reset, typography, buttons, forms, alerts, tables, product grid, badges, pagination, receipt, admin table, responsive breakpoints at 720px and 1024px, `:focus-visible` rings, `prefers-reduced-motion` guard. Mobile: admin tables become stacked cards; nav collapses without JS (wrap).

- [ ] **Step 3: Add client-side validation**

`app.js`:
- annotate forms with `data-validate` and fields with `data-rules="required|min:2|max:100"`; mirror server rules for product, user and purchase forms using `input.setCustomValidity`/inline error nodes; never submit invalid data;
- quantity total preview reads `data-unit-price` and formats with 3-decimal trim;
- delete confirmations via `data-confirm`;
- no framework, no bundler, defer-loaded.

- [ ] **Step 4: Verify in a browser**

Run: `docker compose up -d --build` then open `http://localhost:8080`.  
Check: 320px, 768px and 1280px layouts; keyboard tab order; focus visibility; error messages announced via `role="alert"`; no console errors; purchase total updates; JS-disabled form still submits successfully. Use the Playwright/browser tools for screenshots and console output.

- [ ] **Step 5: Commit**

```bash
git add public src/Views
git commit -m "feat: apply visual design system, client validation and accessibility pass"
```

---

### Task 15: JWT service, API authentication and token endpoint

**Files:**
- Create: `src/Services/JwtService.php`, `src/Http/Middleware/ApiAuth.php`, `src/Http/Controllers/Api/AuthApiController.php`
- Test: `tests/Unit/Services/JwtServiceTest.php`, `tests/Unit/Http/ApiAuthTest.php`, `tests/Unit/Http/AuthApiControllerTest.php`
- Modify: `bootstrap/app.php` (bind `JwtService` from `Env::require('JWT_SECRET')`, register `api_auth`), `routes/web.php`, `src/Core/Application.php` (JSON 422 for `ValidationException` and 401/403 defaults)

**Interfaces:**
- Produces:
  - `JwtService::__construct(string $secret, int $ttlSeconds = 3600)`, `issue(User $user): array{token:string, token_type:string, expires_in:int, expires_at:string}`, `validate(string $token): ?array`
  - `ApiAuth::__construct(JwtService $jwt, UserRepositoryInterface $users)`, `handle()` — sets `user` attribute; 401 for missing/invalid token, unknown or inactive user
  - `AuthApiController::__construct(AuthService $auth, JwtService $jwt)`, `token(Request): Response`

- [ ] **Step 1: Write the failing tests**

```php
public function test_issues_and_validates_token_with_user_claims(): void
{
    $jwt = new JwtService('test-secret');
    $issued = $jwt->issue($this->user(id: 7, role: User::ROLE_ADMIN));
    $claims = $jwt->validate($issued['token']);
    self::assertSame(7, (int) $claims['sub']);
    self::assertSame('admin', $claims['role']);
    self::assertSame(3600, $issued['expires_in']);
}

public function test_rejects_tampered_and_expired_tokens(): void
{
    $jwt = new JwtService('test-secret');
    $token = $jwt->issue($this->user())['token'];
    self::assertNull($jwt->validate($token . 'x'));
    $expired = new JwtService('test-secret', -10);
    self::assertNull($expired->validate($expired->issue($this->user())['token']));
}
```

Additional: `ApiAuth` 401 without header, 401 with unknown user, 401 for inactive user, success sets `user` attribute; token endpoint returns 200 with token for valid credentials, 401 generic for bad/ inactive, 422 for missing fields.

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter 'JwtService|ApiAuth|AuthApiController'`  
Expected: FAIL.

- [ ] **Step 3: Implement**

- `issue` uses `Firebase\JWT\JWT::encode` with claims `iss`, `sub`, `role`, `iat`, `exp`; `validate` catches `ExpiredException`, `SignatureInvalidException`, `UnexpectedValueException` and returns null.
- `ApiAuth` parses `Authorization: Bearer`, validates, loads by `sub`, checks active, sets attribute.
- `AuthApiController::token` validates required email/password, calls `AuthService::verifyCredentials`, returns 200 with `{data: {...token payload}}` or 401 `{error:{code:'invalid_credentials',...}}`.
- Bootstrap registration: `api_auth` middleware; `JWT_SECRET` missing throws at container resolution.
- Application: `expectsJson()` requests get JSON for `ValidationException` (422, `details` = errors), `HttpException`, and 500s.

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit --filter 'JwtService|ApiAuth|AuthApiController'`  
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src bootstrap routes tests
git commit -m "feat: add JWT service, API authentication and token endpoint"
```

---

### Task 16: Product REST API including purchase

**Files:**
- Create: `src/Http/Controllers/Api/ProductApiController.php`
- Test: `tests/Unit/Http/Api/ProductApiControllerTest.php`, `tests/Unit/Http/ProductsControllerPurchaseApiTest.php`
- Modify: `src/Http/Controllers/ProductsController.php` (add `purchaseApi`), `routes/web.php`

**Interfaces:**
- Produces:
  - `ProductApiController::__construct(ProductRepositoryInterface $products, Validator $validator)`, actions `index`, `show`, `store`, `update`, `destroy`
  - Routes: `GET /api/v1/products`, `GET /api/v1/products/{slug}`, `POST /api/v1/products` (`api_auth` + `role:admin`), `PATCH /api/v1/products/{id:\d+}` (`api_auth` + `role:admin`), `DELETE /api/v1/products/{id:\d+}` (`api_auth` + `role:admin`), `POST /api/v1/products/{slug}/purchase` (`api_auth`)
  - Response shapes: collection `{data:[...], meta:{page,per_page,total,last_page}}`; item `{data:{...}}`; validation 422 with `details`; 404/401/403 JSON errors
  - `ProductsController::purchaseApi(string $slug, Request): Response` returns 201 with `{data:{transaction_id, product, quantity, unit_price, total_price, remaining_stock}}`

- [ ] **Step 1: Write the failing tests**

```php
public function test_index_returns_paginated_payload(): void
{
    $this->products->method('paginate')->willReturn(new PaginatedResult([$this->product()], 3, 1, 2));
    $response = $this->controller()->index(new Request('GET', '/api/v1/products', ['per_page' => '2']));
    self::assertSame(200, $response->status());
    $json = json_decode($response->body(), true);
    self::assertSame(3, $json['meta']['total']);
    self::assertSame('Coke', $json['data'][0]['name']);
}

public function test_store_requires_admin_route_and_validates(): void
{
    $response = $this->controller()->store($this->jsonRequest(['name' => '', 'price' => '0']));
    self::assertSame(422, $response->status());
    self::assertArrayHasKey('price', json_decode($response->body(), true)['error']['details']);
}

public function test_purchase_api_returns_201_and_remaining_stock(): void
{
    $product = $this->product(price: '3.990', stock: 5);
    $this->setupPurchaseMocks($product);
    $response = $this->controller()->purchaseApi('coke', $this->jsonRequest(['quantity' => 2], user: $this->user()));
    self::assertSame(201, $response->status());
    self::assertSame(3, json_decode($response->body(), true)['data']['remaining_stock']);
}
```

Additional: show 404 JSON; update partial fields; delete 204 and storefront hides product; non-admin routes carry `role:admin`; API purchase without `api_auth` never reaches handler; quantity 1 succeeds; out-of-stock 422 JSON.

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter 'ProductApiController|PurchaseApi'`  
Expected: FAIL.

- [ ] **Step 3: Implement**

- API controllers read JSON body via `$request->body` and return `Response::json`.
- `store`/`update` mirror web validation + slug derivation/uniqueness, but return JSON; `is_active` defaults true on create; blank slug derives from name before validation. `update` loads the product first and merges the supplied fields over the existing values so a partial `PATCH` validates a complete record.
- `destroy` soft-deletes and returns 204.
- `purchaseApi` reuses the private `purchaseProduct()`; catches `ValidationException` → 422 JSON, `HttpException` → JSON, returns 201 with remaining stock read from `findById` after commit.

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit --filter 'ProductApiController|PurchaseApi'`  
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src routes tests
git commit -m "feat: add product REST API with token-protected purchase"
```

---

### Task 17: User and transaction REST API

**Files:**
- Create: `src/Http/Controllers/Api/UserApiController.php`, `src/Http/Controllers/Api/TransactionApiController.php`
- Test: `tests/Unit/Http/Api/UserApiControllerTest.php`, `TransactionApiControllerTest.php`
- Modify: `routes/web.php`

**Interfaces:**
- Produces:
  - `UserApiController::__construct(UserRepositoryInterface $users, PasswordHasherInterface $hasher, UserPolicy $policy, Validator $validator)`, actions `index`, `store`, `update`, `destroy`; routes `GET/POST /api/v1/users`, `PATCH/DELETE /api/v1/users/{id:\d+}`, all `api_auth` + `role:admin`; reuses `UserPolicy`, `Rules::userCreate/userUpdate`, `PasswordHasherInterface`
  - `TransactionApiController::__construct(TransactionRepositoryInterface $transactions)`, `index(Request): Response`; route `GET /api/v1/transactions` (`api_auth`); admin sees all, `?scope=me` limits to the token user
  - User JSON never includes `password_hash`

- [ ] **Step 1: Write the failing tests**

```php
public function test_user_payload_never_leaks_password_hash(): void
{
    $this->users->method('paginate')->willReturn(new PaginatedResult([$this->user()], 1, 1, 20));
    $json = json_decode($this->controller()->index(new Request('GET', '/api/v1/users'))->body(), true);
    self::assertArrayNotHasKey('password_hash', $json['data'][0]);
}

public function test_api_enforces_last_admin_guard(): void
{
    $this->users->method('findById')->willReturn($this->admin(id: 2));
    $this->users->method('countActiveAdmins')->willReturn(1);
    $actor = $this->admin(id: 1);
    $response = $this->controller()->update(2, $this->jsonRequest(['is_active' => false], user: $actor));
    self::assertSame(422, $response->status());
}

public function test_transactions_scope_me_filters_to_token_user(): void
{
    $this->transactions->expects(self::once())->method('paginate')->with(1, 20, 5)
        ->willReturn(new PaginatedResult([], 0, 1, 20));
    $this->controller()->index(new Request('GET', '/api/v1/transactions', ['scope' => 'me'], attributes: ['user' => $this->user(id: 5)]));
}
```

Additional: non-admin calling users API routes is blocked by middleware (route introspection); role/invalid email 422 JSON; delete 204; transactions admin list includes buyer; invalid scope falls back to all for admins and own for non-admins.

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter 'UserApiController|TransactionApiController'`  
Expected: FAIL.

- [ ] **Step 3: Implement**

- User API mirrors web user management, returns `{data}` items without `password_hash`; partial updates merge supplied fields over the existing user; soft delete 204.
- Transaction API: admin without scope or `scope=all` → all; `scope=me` or non-admin → own. Clamp page, 20 per page.
- Ensure `routes/web.php` registers both controllers.

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit tests/Unit`  
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src routes tests
git commit -m "feat: add user and transaction REST API"
```

---

### Task 18: Documentation, requirement map and full-system verification

**Files:**
- Create: `README.md`, `docs/REQUIREMENTS.md`, `docs/DEPLOYMENT.md`
- Modify: `.env.example` if runtime values changed

**Interfaces:**
- Consumes: the whole application.

- [ ] **Step 1: Write the README**

Sections: what it is; stack; quick start (`docker compose up --build`, URL, seeded accounts); host-PHP development (`.env`, `composer install`, `composer migrate`, `composer seed`, `php -S 127.0.0.1:8080 -t public`); test commands (unit, integration prerequisites); demo walkthrough (browse → login → purchase → receipt → admin CRUD → API token + curl); security notes; explicit line: **hosting deferred by submitter's choice — `docs/DEPLOYMENT.md` has the runbook, so the submission needs a host before the preview link can be sent.**

- [ ] **Step 2: Write `docs/REQUIREMENTS.md`**

Answer task 1 in prose (schema, fields, relationships, why `DECIMAL(10,3)`, snapshot columns, soft delete). Then a table mapping every task 1–17 to files/classes/tests, including:
- task 2 note: transactions are append-only by design (create/read, no update/delete);
- task 3/4 session + role middleware files;
- task 6 `ProductsController::purchase()`;
- task 11 attribute route example;
- task 14/15 test files and mocks;
- task 16/17 API endpoints.

- [ ] **Step 3: Write `docs/DEPLOYMENT.md`**

Single-server runbook: Docker Compose (app + MySQL) behind a TLS reverse proxy; env/secrets (`JWT_SECRET`, DB password); volume for MySQL data; `bin/migrate` + `bin/seed` on release; health check; smoke commands; backup/restore; note that `docker compose up` on a fresh host reproduces the local stack.

- [ ] **Step 4: Run the full verification**

Run: `vendor/bin/phpunit`  
Expected: all Unit and Integration tests pass (MySQL container running).  
Run: `docker compose down -v && docker compose up -d --build`  
Expected: app healthy at `http://localhost:8080`, migrations and seed applied, three products visible.  
Run API smoke:

```bash
curl -s -X POST localhost:8080/api/v1/auth/token -H 'Content-Type: application/json' \
  -d '{"email":"admin@vending.test","password":"password"}'
curl -s localhost:8080/api/v1/products
```

Expected: token payload; product collection JSON.

- [ ] **Step 5: Browser walkthrough**

Log in as user, buy 2 Pepsi, confirm receipt total `USD 13.77` and stock reduced by 2; log in as admin, create a product, edit it, soft-delete it, confirm it disappears from the storefront; open `/admin/users`, trigger the self-demotion guard and confirm it is blocked; check `/admin/transactions` shows the purchase.

- [ ] **Step 6: Commit**

```bash
git add README.md docs .env.example
git commit -m "docs: add setup, requirement map and deployment runbook"
```

---

## Self-Review Results

- **Spec coverage:** Every numbered requirement maps to at least one task above; Task 18 records the mapping in `docs/REQUIREMENTS.md`. Deployment is intentionally deferred by the user's choice and documented as such.
- **Interfaces:** `ProductRepositoryInterface`, `UserRepositoryInterface`, `TransactionRepositoryInterface`, `TransactionManagerInterface`, `SessionInterface`, `MiddlewareInterface` and controller constructors are referenced with the same signatures across tasks.
- **Review Focus:** Each listed failure mode has an owning test: row lock/boundary (Tasks 5, 11), hostile params (Task 5), zero/boundary stock (Tasks 7, 11), admin lockout (Tasks 12, 17), stale JWT (Task 15).
- **Known deferral:** `storage/logs/` is created at runtime; the Docker entrypoint ensures its presence.
