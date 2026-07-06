<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Action;

use MyInvoice\Action\Note\NotesAction;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\NoteRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Validace NotesAction — chybové cesty se vrací PŘED dotykem DB (Connection je
 * lazy, PDO se otevírá až při prvním query), takže test běží bez databáze.
 */
final class NotesActionValidationTest extends TestCase
{
    public function testCreateWithoutTitleFails(): void
    {
        $response = $this->action()->create($this->request(['title' => '   ']), (new ResponseFactory())->createResponse());
        self::assertSame(400, $response->getStatusCode());
        $payload = (string) $response->getBody();
        self::assertStringContainsString('validation_failed', $payload);
    }

    public function testCreateWithTooLongTitleFails(): void
    {
        $response = $this->action()->create(
            $this->request(['title' => str_repeat('á', 201), 'body' => '']),
            (new ResponseFactory())->createResponse(),
        );
        self::assertSame(400, $response->getStatusCode());
    }

    public function testUpdateWithoutTitleFails(): void
    {
        $response = $this->action()->update(
            $this->request(['title' => '']),
            (new ResponseFactory())->createResponse(),
            ['id' => '7'],
        );
        self::assertSame(400, $response->getStatusCode());
    }

    public function testTitleOfExactly200CharsPassesValidation(): void
    {
        // 200 znaků projde validací → akce sáhne na DB → bez DB skončí výjimkou,
        // NE 400. Tím je ověřená hranice validace bez potřeby databáze.
        $this->expectException(\Throwable::class);
        $this->action()->create(
            $this->request(['title' => str_repeat('á', 200), 'body' => 'x']),
            (new ResponseFactory())->createResponse(),
        );
    }

    private function action(): NotesAction
    {
        $connection = new Connection(new Config([], sys_get_temp_dir()));
        return new NotesAction(
            new NoteRepository($connection),
            new ActivityLogger($connection),
            new IpMatcher(),
        );
    }

    private function request(array $body): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/notes')
            ->withParsedBody($body);
    }
}
