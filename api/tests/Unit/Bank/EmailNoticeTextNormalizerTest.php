<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Bank;

use MyInvoice\Service\Bank\EmailNotice\EmailNoticeTextNormalizer;
use PHPUnit\Framework\TestCase;

final class EmailNoticeTextNormalizerTest extends TestCase
{
    /**
     * issue #58: tělo ČSOB avíza ve windows-1250 musí po normalizaci zachovat
     * diakritiku (dřív `iconv UTF-8//IGNORE` diakritiku zahodil).
     */
    public function testRecoversWindows1250HtmlBody(): void
    {
        $utf8Html = '<html><body><p>Parametry platby</p>'
            . '<p>Účet<br>123456789/0300</p>'
            . '<p>Částka<br>+10 000,00 CZK</p>'
            . '<p>Vaše ČSOB</p></body></html>';
        $cp1250 = iconv('UTF-8', 'WINDOWS-1250', $utf8Html);
        self::assertIsString($cp1250);

        $out = (new EmailNoticeTextNormalizer())->normalize($cp1250);

        self::assertStringContainsString('Parametry platby', $out);
        self::assertStringContainsString('Účet', $out);
        self::assertStringContainsString('Částka', $out);
        self::assertStringContainsString('Vaše ČSOB', $out);
        self::assertStringNotContainsString('<', $out);
    }

    public function testKeepsUtf8PlainBody(): void
    {
        $body = "Parametry platby\nČástka\n+10 000,00 CZK\nVaše ČSOB";
        $out = (new EmailNoticeTextNormalizer())->normalize($body);
        self::assertStringContainsString('Částka', $out);
        self::assertStringContainsString('Vaše ČSOB', $out);
    }
}
