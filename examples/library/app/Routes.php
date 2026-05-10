<?php

declare(strict_types=1);

namespace App;

use App\Application\BorrowBook;
use App\Application\RegisterBook;
use App\Application\ReturnBook;
use App\Domain\Service\BorrowingService;
use App\Infrastructure\JsonBookRepository;
use App\Infrastructure\JsonLoanRepository;
use App\Presentation\Controller\BookController;
use App\Presentation\Controller\LoanController;
use Cloude\Router;

/**
 * Route table + manual wiring.
 *
 * The framework deliberately ships no DI container. For a layered app
 * this means the entry point assembles the object graph by hand — which
 * is also the only place that crosses every layer (Domain interfaces ↔
 * Infrastructure implementations ↔ Application use cases ↔ Presentation
 * controllers). Easy to read, easy to change, easy to test.
 */
class Routes
{
    public static function register(Router $router): void
    {
        // Infrastructure (concrete repositories backed by JSON files).
        $books = new JsonBookRepository(DATA_DIR . '/books');
        $loans = new JsonLoanRepository(DATA_DIR . '/loans');

        // Domain service — depends only on domain interfaces.
        $borrowing = new BorrowingService($books, $loans);

        // Application use cases.
        $register = new RegisterBook($books);
        $borrow   = new BorrowBook($borrowing);
        $return   = new ReturnBook($borrowing);

        // Presentation controllers.
        $bookCtrl = new BookController($books, $register, $borrow);
        $loanCtrl = new LoanController($loans, $books, $return);

        // Routes.
        $router->get('/',          $bookCtrl->index(...));
        $router->get('/api/books', $bookCtrl->apiList(...));
        $router->get('/books/new', $bookCtrl->newForm(...));
        $router->post('/books',    $bookCtrl->create(...));

        // Note: Cloude\Router's constraint syntax stops at the first `}`,
        // so we can't use `{N}` quantifiers here. We accept any digit run
        // and any hex-with-dashes — the Isbn / LoanId value objects enforce
        // the real shape inside the controller, which is where it belongs
        // in DDD anyway (route patterns are not business rules).
        $router->post('/books/{isbn:\d+}/borrow', $bookCtrl->borrow(...));

        $router->get('/loans', $loanCtrl->index(...));
        $router->post('/loans/{id:[0-9a-f-]+}/return', $loanCtrl->return(...));
    }
}
