<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Pdf;

use MyInvoice\Service\Pdf\InvoiceTemplateResolver;
use PHPUnit\Framework\TestCase;

final class InvoiceTemplateResolverTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        // Minimální fake repo strom: default vždy existuje, varianta "spotted" taky.
        $this->root = sys_get_temp_dir() . '/myinvoice-tplres-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/api/templates/invoice', 0700, true);
        mkdir($this->root . '/styles', 0700, true);
        foreach (['invoice', 'spotted'] as $v) {
            file_put_contents($this->root . "/api/templates/invoice/{$v}.twig", 'x');
            file_put_contents($this->root . "/styles/{$v}.css", 'x');
        }
    }

    protected function tearDown(): void
    {
        foreach (['invoice', 'spotted'] as $v) {
            @unlink($this->root . "/api/templates/invoice/{$v}.twig");
            @unlink($this->root . "/styles/{$v}.css");
        }
        @rmdir($this->root . '/api/templates/invoice');
        @rmdir($this->root . '/api/templates');
        @rmdir($this->root . '/api');
        @rmdir($this->root . '/styles');
        @rmdir($this->root);
        parent::tearDown();
    }

    public function testDefaultResolvesToInvoice(): void
    {
        $r = (new InvoiceTemplateResolver($this->root))->resolve('invoice');
        self::assertSame('invoice', $r['variant']);
        self::assertSame('invoice.twig', $r['twigName']);
        self::assertSame($this->root . '/styles/invoice.css', $r['cssPath']);
        self::assertSame($this->root . '/api/templates/invoice/invoice.twig', $r['twigPath']);
    }

    public function testValidVariantResolves(): void
    {
        $r = (new InvoiceTemplateResolver($this->root))->resolve('spotted');
        self::assertSame('spotted', $r['variant']);
        self::assertSame('spotted.twig', $r['twigName']);
        self::assertSame($this->root . '/styles/spotted.css', $r['cssPath']);
    }

    public function testNullAndEmptyFallBackToInvoice(): void
    {
        $res = new InvoiceTemplateResolver($this->root);
        self::assertSame('invoice', $res->resolve(null)['variant']);
        self::assertSame('invoice', $res->resolve('')['variant']);
    }

    public function testTraversalAndIllegalCharsFallBackToInvoice(): void
    {
        $res = new InvoiceTemplateResolver($this->root);
        self::assertSame('invoice', $res->resolve('../etc/passwd')['variant']);
        self::assertSame('invoice', $res->resolve('Spotted')['variant']); // velké písmeno mimo [a-z0-9-]
        self::assertSame('invoice', $res->resolve('a b')['variant']);
    }

    public function testMissingVariantFilesFallBackToInvoice(): void
    {
        // syntakticky platná, ale soubory neexistují
        $r = (new InvoiceTemplateResolver($this->root))->resolve('neexistuje');
        self::assertSame('invoice', $r['variant']);
        self::assertSame($this->root . '/styles/invoice.css', $r['cssPath']);
    }
}
