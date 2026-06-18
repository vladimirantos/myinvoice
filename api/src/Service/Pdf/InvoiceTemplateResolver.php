<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

/**
 * Vybírá variantu PDF šablony faktury podle konfigurace (ENV MYINVOICE_INVOICE_TEMPLATE).
 *
 * Záměrně izolované a čisté (jen filesystem existence-check), aby šlo unit-testovat bez
 * těžkých závislostí InvoicePdfRenderer. Default i jakákoli neplatná / chybějící varianta
 * spadne na 'invoice' — stávající instance se tím nikdy nezmění.
 */
final class InvoiceTemplateResolver
{
    private const DEFAULT = 'invoice';

    public function __construct(private readonly string $rootDir)
    {
    }

    /**
     * @return array{variant:string, twigName:string, twigPath:string, cssPath:string}
     */
    public function resolve(?string $configured): array
    {
        $variant = (string) ($configured ?? '');
        if ($variant === '' || preg_match('/^[a-z0-9-]+$/', $variant) !== 1) {
            $variant = self::DEFAULT;
        }

        $twigPath = $this->twigPath($variant);
        $cssPath  = $this->cssPath($variant);

        if ($variant !== self::DEFAULT && (!is_file($twigPath) || !is_file($cssPath))) {
            error_log(sprintf(
                '[InvoicePdf] varianta faktury "%s" nemá twig/css → fallback na "%s"',
                $variant,
                self::DEFAULT
            ));
            $variant  = self::DEFAULT;
            $twigPath = $this->twigPath($variant);
            $cssPath  = $this->cssPath($variant);
        }

        return [
            'variant'  => $variant,
            'twigName' => $variant . '.twig',
            'twigPath' => $twigPath,
            'cssPath'  => $cssPath,
        ];
    }

    private function twigPath(string $variant): string
    {
        return $this->rootDir . '/api/templates/invoice/' . $variant . '.twig';
    }

    private function cssPath(string $variant): string
    {
        return $this->rootDir . '/styles/' . $variant . '.css';
    }
}
