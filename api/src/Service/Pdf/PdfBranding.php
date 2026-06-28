<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use MyInvoice\Service\Branding\AccentColor;
use MyInvoice\Service\Mail\SafeLogoPath;

/**
 * Sdílená brandingová logika pro PDF (faktura + výkaz víceprací):
 *   - resolveLogoPath: gate na email_branding_enabled, SafeLogoPath validace,
 *     preference SVG sidecaru (crisp), fallback PNG,
 *   - accentCss: override CSS přebarvující fialové akcenty na zvolenou barvu.
 *
 * Cíl: faktura i výkaz mají identické chování hlavičky (3 varianty: bez loga →
 * textový název, jen logo, logo + název) a stejné barevné branding změny.
 */
final class PdfBranding
{
    /**
     * Logo pro PDF — jen když má dodavatel zapnutý branding (email_branding_enabled).
     * Bez brandingu vrací null → šablona vykreslí textový brand-name fallback.
     * Preferuje SVG sidecar (vektor), pokud je mPDF-kompatibilní; jinak PNG.
     */
    public static function logoPath(array $supplier, int $supplierIdFallback = 0): ?string
    {
        if (empty($supplier['email_branding_enabled'])) {
            return null;
        }
        $logoPath = $supplier['logo_path'] ?? null;
        if (!$logoPath) {
            return null;
        }

        // SafeLogoPath: defense-in-depth proti podstrčenému logo_path (security #2).
        $supplierId = (int) ($supplier['id'] ?? $supplierIdFallback);
        $abs = SafeLogoPath::resolve((string) $logoPath, $supplierId);
        if ($abs === null) {
            return null;
        }

        // SVG sidecar preferujeme (crisp), pokud neobsahuje mPDF-problematické prvky.
        $svgSibling = preg_replace('/\.png$/i', '.svg', (string) $logoPath);
        if (is_string($svgSibling) && $svgSibling !== $logoPath) {
            $svgAbs = SafeLogoPath::resolve($svgSibling, $supplierId);
            if ($svgAbs !== null && self::svgIsMpdfCompatible($svgAbs)) {
                return $svgAbs;
            }
        }
        // PNG fallback: splácni alfa kanál na bílou — mPDF neumí SMask u truecolor
        // RGBA PNG a vykreslil by průhledné pozadí černě (issue #152).
        return PdfLogoFlattener::flattenedPath($abs);
    }

    /** True = supplier má logo, které lze v PDF zobrazit (pro gate `logo_show_name`). */
    private static function svgIsMpdfCompatible(string $svgPath): bool
    {
        $svg = (string) @file_get_contents($svgPath);
        if ($svg === '') {
            return false;
        }
        $bad = '/<(?:clipPath|use|mask|linearGradient|radialGradient|pattern|filter)\b/i';
        return !preg_match($bad, $svg);
    }

    /**
     * Per-supplier accent override CSS — přebarví fialové akcenty (#3B2D83 + světlé
     * varianty/linky) na zvolenou barvu. Gated na email_branding_enabled + nedefaultní
     * hex. Vrací '' pokud branding vypnutý nebo defaultní barva (ta je už v base CSS).
     *
     * Selektory pokrývají fakturu i výkaz (přebytečné selektory u výkazu jsou no-op:
     * .head border, .brand-name, .doc-type, .wr-title/.wr-link platí pro oba).
     */
    public static function accentCss(array $supplier, string $variant = 'invoice'): string
    {
        // Brandové varianty (spotted) nesou paletu přímo ve své CSS (styles/<variant>.css),
        // takže je per-supplier akcentem NEpřebarvujeme. Default 'invoice' = dnešní chování
        // (early-return se pro něj nespustí → výstup je bitově identický).
        if ($variant !== 'invoice') {
            return self::variantAccentCss($variant, $supplier);
        }

        if (empty($supplier['email_branding_enabled'])) {
            return '';
        }
        $color = AccentColor::normalize($supplier['email_accent_color'] ?? null);
        if ($color === null || $color === AccentColor::DEFAULT) {
            return '';
        }

        $bgSoft     = AccentColor::tint($color, 0.08);
        $lineSoft   = AccentColor::tint($color, 0.24);
        $lineMedium = AccentColor::tint($color, 0.28);
        $badgeBorder = AccentColor::tint($color, 0.30);

        // Minimalistický layout (2026-05): akcent = fialový pruh nad „Faktura",
        // proužky nad stranami, tenká linka pod hlavičkou tabulky, fialový součet
        // (bez plné plochy). Titulek dokladu (.doc-title) i šedé labely zůstávají
        // neutrální (nepřebarvujeme). Semantické varianty (dobropis/storno) mají
        // vyšší specificitu (.head.credit-note …) → tenhle 1-třídový override je
        // nepřebije.
        return "\n/* ─── Branding override (per-supplier accent color) ─── */\n"
            . ".brand-name { color: {$color}; }\n"
            . "hr.accent-bar { background-color: {$color}; color: {$color}; }\n"
            . "table.party-tick td.tick-bar { border-bottom-color: {$color}; }\n"
            . "table.items th { border-bottom-color: {$color}; }\n"
            . "table.totals-table tr.grand td, table.totals-table tr.grand td.tot-label { color: {$color}; border-top-color: {$color}; }\n"
            . "table.totals-table tr.to-pay td { border-top-color: {$color}; color: {$color}; }\n"
            . "table.totals-table tr.subtotal td { border-top-color: {$lineSoft}; }\n"
            . "table.czk-recap td.czk-recap-title { color: {$color}; border-bottom-color: {$lineMedium}; }\n"
            . "table.czk-recap tr.grand td { color: {$color}; border-top-color: {$color}; }\n"
            . "table.czk-recap tr.subtotal td { border-top-color: {$lineSoft}; }\n"
            . ".isdoc-badge { color: {$color}; background: {$bgSoft}; border-color: {$badgeBorder}; }\n"
            . ".note { border-left-color: {$color}; }\n"
            . ".note.rc-note { border-left-color: #E8A547; }\n"
            . ".proforma-note { border-left-color: {$color}; }\n"
            . ".wr-title, .wr-link { color: {$color}; }\n";
    }

    /**
     * Per-variant accent CSS. Brandové varianty mají paletu fixní ve své CSS, takže
     * zatím nic nepřipojujeme. Seam pro budoucí variant-specific akcentové úpravy.
     *
     * @param array<string,mixed> $supplier
     */
    private static function variantAccentCss(string $variant, array $supplier): string
    {
        return match ($variant) {
            'spotted' => '', // styles/spotted.css nese fixní paletu #141414 / #D9512C / #F2EEE6
            default   => '',
        };
    }
}
