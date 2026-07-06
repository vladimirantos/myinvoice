<?php

declare(strict_types=1);

namespace MyInvoice\Action\Note;

use MyInvoice\Http\Json;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\NoteRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Poznámky (fork feature, sekce Dokumenty) — společné pro celou instanci:
 *   GET    /api/notes
 *   POST   /api/notes
 *   PUT    /api/notes/{id}
 *   DELETE /api/notes/{id}
 */
final class NotesAction
{
    public function __construct(
        private readonly NoteRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function list(Request $request, Response $response): Response
    {
        return Json::ok($response, $this->repo->listAll());
    }

    public function create(Request $request, Response $response): Response
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $err = $this->validate($body);
        if ($err !== null) return Json::error($response, 'validation_failed', $err, 400);
        $id = $this->repo->create(trim((string) $body['title']), (string) ($body['body'] ?? ''));
        $this->log($request, 'note.created', $id, ['title' => trim((string) $body['title'])]);
        return Json::ok($response, $this->repo->find($id), 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        $err = $this->validate($body);
        if ($err !== null) return Json::error($response, 'validation_failed', $err, 400);
        if ($this->repo->find($id) === null) {
            return Json::error($response, 'not_found', 'Poznámka nenalezena.', 404);
        }
        $this->repo->update($id, trim((string) $body['title']), (string) ($body['body'] ?? ''));
        $this->log($request, 'note.updated', $id, ['title' => trim((string) $body['title'])]);
        return Json::ok($response, $this->repo->find($id));
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if ($this->repo->find($id) === null) {
            return Json::error($response, 'not_found', 'Poznámka nenalezena.', 404);
        }
        $this->repo->delete($id);
        $this->log($request, 'note.deleted', $id, []);
        return Json::ok($response, ['deleted' => true]);
    }

    private function validate(array $body): ?string
    {
        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') return 'Titulek je povinný.';
        if (mb_strlen($title) > 200) return 'Titulek: max 200 znaků.';
        if (mb_strlen((string) ($body['body'] ?? '')) > 1_000_000) return 'Text poznámky je příliš dlouhý.';
        return null;
    }

    private function log(Request $request, string $action, int $id, array $payload): void
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log($action, (int) ($user['id'] ?? 0) ?: null, 'note', $id, $payload, $ip, $request->getHeaderLine('User-Agent'));
    }
}
