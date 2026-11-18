<?php

declare(strict_types=1);

use App\Core\Application;
use App\Core\Container;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\NativeSession;
use App\Core\Router;
use App\Core\SessionInterface;
use App\Core\View;
use App\Database\Connection;
use App\Database\PdoTransactionManager;
use App\Database\TransactionManagerInterface;
use App\Http\Controllers\Api\AuthApiController;
use App\Http\Controllers\AuthController;
use App\Http\Middleware\ApiAuth;
use App\Http\Middleware\RequireRole;
use App\Http\Middleware\ResolveUser;
use App\Http\Middleware\SessionAuth;
use App\Http\Middleware\VerifyCsrf;
use App\Repositories\PdoProductRepository;
use App\Repositories\PdoTransactionRepository;
use App\Repositories\PdoUserRepository;
use App\Repositories\ProductRepositoryInterface;
use App\Repositories\TransactionRepositoryInterface;
use App\Repositories\UserRepositoryInterface;
use App\Services\AuthService;
use App\Services\JwtService;
use App\Services\NativePasswordHasher;
use App\Services\PasswordHasherInterface;
use App\Services\UserPolicy;

Env::require('JWT_SECRET');

$container = new Container();

$container->set(Connection::class, static fn (): Connection => Connection::fromEnv());

$container->set(
    ProductRepositoryInterface::class,
    static fn (Container $c): ProductRepositoryInterface => new PdoProductRepository($c->get(Connection::class))
);

$container->set(
    UserRepositoryInterface::class,
    static fn (Container $c): UserRepositoryInterface => new PdoUserRepository($c->get(Connection::class))
);

$container->set(
    TransactionRepositoryInterface::class,
    static fn (Container $c): TransactionRepositoryInterface => new PdoTransactionRepository($c->get(Connection::class))
);

$container->set(
    TransactionManagerInterface::class,
    static fn (Container $c): TransactionManagerInterface => new PdoTransactionManager($c->get(Connection::class))
);

$container->set(SessionInterface::class, static fn (): SessionInterface => new NativeSession());

$container->set(
    Csrf::class,
    static fn (Container $c): Csrf => new Csrf($c->get(SessionInterface::class))
);

$container->set(
    Router::class,
    static fn (Container $c): Router => new Router($c)
);

$container->set(
    View::class,
    static fn (Container $c): View => new View(
        dirname(__DIR__) . '/src/Views',
        $c->get(Router::class),
        $c->get(SessionInterface::class),
        $c->get(Csrf::class)
    )
);

$container->set(
    Application::class,
    static fn (Container $c): Application => new Application(
        $c->get(Router::class),
        $c->get(View::class)
    )
);

$container->set(
    PasswordHasherInterface::class,
    static fn (): PasswordHasherInterface => new NativePasswordHasher()
);

$container->set(
    AuthService::class,
    static fn (Container $c): AuthService => new AuthService(
        $c->get(UserRepositoryInterface::class),
        $c->get(PasswordHasherInterface::class),
        $c->get(SessionInterface::class)
    )
);

$container->set(
    UserPolicy::class,
    static fn (Container $c): UserPolicy => new UserPolicy($c->get(UserRepositoryInterface::class))
);

$container->set(
    JwtService::class,
    static fn (): JwtService => new JwtService(Env::require('JWT_SECRET'))
);

$router = $container->get(Router::class);

$router->useGlobal($container->get(ResolveUser::class));

$router->middleware('auth', static fn (): SessionAuth => new SessionAuth());
$router->middleware('csrf', static fn (Container $c): VerifyCsrf => new VerifyCsrf($c->get(Csrf::class)));
$router->middleware('role', static fn (Container $c, ?string $argument): RequireRole => new RequireRole((string) $argument));
$router->middleware('api_auth', static fn (Container $c): ApiAuth => new ApiAuth(
    $c->get(JwtService::class),
    $c->get(UserRepositoryInterface::class)
));

foreach (require dirname(__DIR__) . '/routes/web.php' as $controller) {
    $router->addController($controller);
}

return $container;
