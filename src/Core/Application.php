<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

final class Application
{
    public function __construct(
        private readonly Router $router,
        private readonly View $view,
    ) {
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->router->dispatch($request);
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                return Response::json([
                    'error' => [
                        'code' => 'validation_failed',
                        'message' => 'The given data was invalid.',
                        'details' => $exception->errors(),
                    ],
                ], 422);
            }

            return $this->view->render('errors/422', ['message' => 'Please correct the highlighted fields.'], 422);
        } catch (HttpException $exception) {
            return $this->httpErrorResponse($request, $exception);
        } catch (Throwable $exception) {
            $this->log($exception);

            if ($request->expectsJson()) {
                return Response::error('server_error', 'Something went wrong.', 500);
            }

            return $this->view->render('errors/500', ['message' => 'Something went wrong.'], 500);
        }
    }

    private function httpErrorResponse(Request $request, HttpException $exception): Response
    {
        $message = $exception->getMessage() !== '' ? $exception->getMessage() : $this->defaultMessage($exception->status);

        $response = $request->expectsJson()
            ? Response::error($this->errorCode($exception->status), $message, $exception->status)
            : $this->view->render($this->errorView($exception->status), ['message' => $message], $exception->status);

        foreach ($exception->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    private function errorCode(int $status): string
    {
        return match ($status) {
            401 => 'unauthorized',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            419 => 'csrf_failed',
            422 => 'validation_failed',
            default => 'server_error',
        };
    }

    private function errorView(int $status): string
    {
        $view = 'errors/' . $status;

        return is_file(dirname(__DIR__) . '/Views/' . $view . '.php') ? $view : 'errors/500';
    }

    private function defaultMessage(int $status): string
    {
        return match ($status) {
            401 => 'Authentication required.',
            403 => 'You are not allowed to do that.',
            404 => 'Not found.',
            405 => 'Method not allowed.',
            419 => 'Your session expired. Please try again.',
            422 => 'The given data was invalid.',
            default => 'Something went wrong.',
        };
    }

    private function log(Throwable $exception): void
    {
        $directory = dirname(__DIR__, 2) . '/storage/logs';

        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $line = sprintf(
            "[%s] %s\n%s\n",
            date('c'),
            $exception->getMessage(),
            $exception->getTraceAsString()
        );

        @error_log($line, 3, $directory . '/app.log');
    }
}
