<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Route;
use App\Core\View;
use App\Repositories\TransactionRepositoryInterface;

final class TransactionController
{
    public function __construct(
        private readonly TransactionRepositoryInterface $transactions,
        private readonly View $view,
    ) {
    }

    #[Route('GET', '/admin/transactions', name: 'admin.transactions.index')]
    #[Middleware('auth')]
    #[Middleware('role:admin')]
    public function index(Request $request): Response
    {
        $page = max(1, (int) ($request->queryString('page', '1')));

        return $this->view->render('admin/transactions/index', [
            'transactions' => $this->transactions->paginate($page, 20),
            'query' => $request->query,
        ]);
    }
}
