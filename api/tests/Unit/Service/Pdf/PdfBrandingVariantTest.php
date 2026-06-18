<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Pdf;

use MyInvoice\Service\Pdf\PdfBranding;
use PHPUnit\Framework\TestCase;

final class PdfBrandingVariantTest extends TestCase
{
    /** @return array<string,mixed> */
    private function supplier(): array
    {
        return ['email_branding_enabled' => 1, 'email_accent_color' => '#0EA5C9'];
    }

    public function testDefaultVariantParamMatchesNoParam(): void
    {
        // Tvrdý invariant: přidání default parametru nesmí změnit výstup pro stávající instanci.
        self::assertSame(
            PdfBranding::accentCss($this->supplier()),
            PdfBranding::accentCss($this->supplier(), 'invoice')
        );
    }

    public function testDefaultVariantStillEmitsAccentOverrides(): void
    {
        // Stávající instance: zapnutý branding + ne-default barva → CSS override se generuje.
        $css = PdfBranding::accentCss($this->supplier(), 'invoice');
        self::assertStringContainsString('Branding override', $css);
    }

    public function testSpottedVariantEmitsNoPerSupplierOverride(): void
    {
        // spotted.css nese vlastní paletu → žádný per-supplier accent override.
        self::assertSame('', PdfBranding::accentCss($this->supplier(), 'spotted'));
    }

    public function testUnknownVariantDoesNotBreak(): void
    {
        $css = PdfBranding::accentCss($this->supplier(), 'whatever');
        self::assertIsString($css);
    }
}
