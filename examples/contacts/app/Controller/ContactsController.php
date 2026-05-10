<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ContactsRepo;
use Cloude\Http\Response;
use Cloude\Input;
use Cloude\JsonSchema;
use Cloude\View;

/**
 * Route handlers for the Contacts demo.
 *
 * Each public method matches a route in App\Routes; the router invokes them
 * with the captured `{param}` array as the single argument.
 */
class ContactsController
{
    private ContactsRepo $repo;

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0755, true);
        }
        $this->repo = new ContactsRepo($dataDir);
    }

    // ── GET / ───────────────────────────────────────────────────────────────

    public function home(): void
    {
        View::render('layout.php', [
            'title'    => 'Contacts',
            'content'  => 'home.php',
            'contacts' => $this->repo->search('')->all(),
            'flash'    => $this->flash(),
        ]);
    }

    // ── GET /api/search?q=… ─────────────────────────────────────────────────

    public function apiSearch(): void
    {
        $q = (string) Input::get('q', '');

        $results = $this->repo->search($q)
            ->map(static fn (array $c): array => [
                'slug'  => $c['slug'],
                'name'  => $c['name'],
                'email' => $c['email'],
                'phone' => $c['phone'],
                'url'   => BASE_URL . '/contact/' . $c['slug'],
            ])
            ->values()
            ->all();

        Response::json([
            'q'       => $q,
            'count'   => count($results),
            'results' => $results,
        ]);
    }

    // ── GET /new ────────────────────────────────────────────────────────────

    public function newForm(): void
    {
        View::render('layout.php', [
            'title'   => 'New contact',
            'content' => 'new.php',
            'errors'  => [],
            'old'     => self::emptyInput(),
        ]);
    }

    // ── POST /new ───────────────────────────────────────────────────────────

    public function create(): void
    {
        $input = [
            'name'  => trim((string) Input::post('name', '')),
            'email' => trim((string) Input::post('email', '')),
            'phone' => trim((string) Input::post('phone', '')),
            'notes' => trim((string) Input::post('notes', '')),
        ];

        $errors = JsonSchema::validate($input, self::schema());
        if ($errors !== []) {
            http_response_code(422);
            View::render('layout.php', [
                'title'   => 'New contact',
                'content' => 'new.php',
                'errors'  => $errors,
                'old'     => $input,
            ]);
            return;
        }

        $slug = $this->repo->uniqueSlug($input['name']);
        $this->repo->write($slug, $input + ['created_at' => gmdate('c')]);

        // 303 See Other → POST/redirect/GET pattern: the browser follows with GET,
        // so a refresh on the detail page won't re-submit the form.
        Response::redirect(BASE_URL . '/contact/' . $slug . '?created=1', 303);
    }

    // ── GET /contact/{slug} ─────────────────────────────────────────────────

    /**
     * @param array<string,string> $params
     */
    public function show(array $params): void
    {
        $contact = $this->repo->findOne($params['slug']);
        if ($contact === null) {
            http_response_code(404);
            View::render('layout.php', [
                'title'   => '404 - Not Found',
                'content' => '404.php',
            ]);
            return;
        }

        View::render('layout.php', [
            'title'   => $contact['name'],
            'content' => 'detail.php',
            'contact' => $contact,
            'flash'   => $this->flash(),
        ]);
    }

    // ── POST /contact/{slug}/delete ─────────────────────────────────────────

    /**
     * @param array<string,string> $params
     */
    public function delete(array $params): void
    {
        $this->repo->delete($params['slug']);
        Response::redirect(BASE_URL . '/?deleted=1', 303);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * @return array<string,string>
     */
    private static function emptyInput(): array
    {
        return ['name' => '', 'email' => '', 'phone' => '', 'notes' => ''];
    }

    /**
     * One-shot UI message based on `?created=1` / `?deleted=1` query flags.
     * Cloude has no built-in session helpers, and a query-flag is enough for
     * this demo — anything richer would need a session or a cookie.
     */
    private function flash(): ?string
    {
        if (Input::get('created') !== null) {
            return 'Contact saved.';
        }
        if (Input::get('deleted') !== null) {
            return 'Contact removed.';
        }
        return null;
    }

    /**
     * JSON Schema for the create-contact form.
     *
     * @return array<mixed>
     */
    private static function schema(): array
    {
        return [
            'type'                 => 'object',
            'required'             => ['name'],
            'additionalProperties' => false,
            'properties'           => [
                'name'  => ['type' => 'string', 'minLength' => 2, 'maxLength' => 80],
                'email' => ['type' => 'string', 'pattern' => '^$|^[^@\s]+@[^@\s]+\.[^@\s]+$'],
                'phone' => ['type' => 'string', 'maxLength' => 30],
                'notes' => ['type' => 'string', 'maxLength' => 500],
            ],
        ];
    }
}
