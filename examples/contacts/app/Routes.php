<?php

declare(strict_types=1);

namespace App;

use App\Controller\ContactsController;
use Cloude\Router;

/**
 * Single source of truth for the route table.
 *
 * | Method | Path                          | Handler                             |
 * |--------|-------------------------------|-------------------------------------|
 * | GET    | /                             | ContactsController::home            |
 * | GET    | /api/search?q=…               | ContactsController::apiSearch       |
 * | GET    | /new                          | ContactsController::newForm         |
 * | POST   | /new                          | ContactsController::create          |
 * | GET    | /contact/{slug}               | ContactsController::show            |
 * | POST   | /contact/{slug}/delete        | ContactsController::delete          |
 *
 * The slug constraint `[a-z0-9-]+` matches what `Cloude\Str::slug()` produces.
 */
class Routes
{
    public static function register(Router $router): void
    {
        $contacts = new ContactsController(DATA_DIR . '/contacts');

        $router->get('/',           $contacts->home(...));
        $router->get('/api/search', $contacts->apiSearch(...));

        $router->get('/new',  $contacts->newForm(...));
        $router->post('/new', $contacts->create(...));

        $router->get('/contact/{slug:[a-z0-9-]+}',         $contacts->show(...));
        $router->post('/contact/{slug:[a-z0-9-]+}/delete', $contacts->delete(...));
    }
}
