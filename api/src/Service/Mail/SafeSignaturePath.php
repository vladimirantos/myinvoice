<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail;

/**
 * Validuje + resolvuje `supplier.signature_path` (razítko/podpis) na bezpečnou
 * absolutní cestu — přesné zrcadlo {@see SafeLogoPath}, jen jiný cílový adresář.
 *
 * Defense-in-depth proti LFI přes podstrčený `signature_path` (stejná třída jako
 * security report @andrejtomci #2 u loga). Razítko se v PDF čte přes <img src=abs>,
 * takže read sink musí mít vlastní validaci pro případ, že by se hodnota dostala
 * do DB jinou cestou než přes StampAction.
 *
 * Povolené tvary (StampConverter pište jen tyhle):
 *   storage/supplier-signatures/sup-{N}.png
 *   storage/supplier-signatures/sup-{N}.svg
 *
 * Vrací **absolutní cestu** při splnění všech podmínek (prefix, basename match,
 * extension allowlist, realpath uvnitř SAFE_DIR, soubor existuje), jinak null.
 */
final class SafeSignaturePath
{
    private const ALLOWED_EXT = ['png', 'jpg', 'jpeg', 'svg', 'webp'];
    private const SAFE_DIR    = 'storage/supplier-signatures';

    public static function resolve(?string $signaturePath, int $supplierId): ?string
    {
        if ($signaturePath === null || $signaturePath === '' || $supplierId <= 0) return null;
        $signaturePath = (string) $signaturePath;

        if (strpos($signaturePath, "\0") !== false || strpos($signaturePath, '..') !== false) return null;

        $rel = ltrim($signaturePath, '/');
        $expectedPrefix = self::SAFE_DIR . '/sup-' . $supplierId;
        if (!str_starts_with($rel, $expectedPrefix . '.')) return null;

        $ext = strtolower((string) pathinfo($rel, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXT, true)) return null;

        $basename = basename($rel);
        if ($basename !== 'sup-' . $supplierId . '.' . $ext) return null;

        $rootDir = \MyInvoice\Infrastructure\Config\RuntimePaths::base();
        $abs = $rootDir . '/' . $rel;

        $real = @realpath($abs);
        if ($real === false) return null;
        $safeBase = @realpath($rootDir . '/' . self::SAFE_DIR);
        if ($safeBase === false || !str_starts_with($real, $safeBase . DIRECTORY_SEPARATOR)) {
            return null;
        }
        if (!is_file($real)) return null;

        return $real;
    }
}
