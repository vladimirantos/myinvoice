# Multi-instance branding — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Umožnit dvě brandované instalace myinvoice z jednoho repa — per-instance varianta PDF faktury volená přes ENV, scaffold varianty `spotted`, druhý Rosti stack a CI deploy obou stacků.

**Architecture:** Aditivní opt-in přes ENV `MYINVOICE_INVOICE_TEMPLATE` (default `invoice` = dnešní chování). Malá izolovaná třída `InvoiceTemplateResolver` (sanitizace + fallback) řeší výběr šablony/CSS; `InvoicePdfRenderer` ji používá místo 4 hardcoded cest; `PdfBranding::accentCss` dostane variant-seam. Stávající instance se nesmí změnit — hlídá to po konstrukci (default větev = identické cesty) + unit testy.

**Tech Stack:** PHP 8.5 (Slim, Twig, mPDF), PHPUnit, GitHub Actions, Rosti (rosticli + GHCR).

**Spec:** `docs/superpowers/specs/2026-06-18-multi-instance-invoice-branding-design.md`

---

## Tvrdý invariant
Stávající instance (`invoice.vladimirantos.cz`, žádné `MYINVOICE_INVOICE_TEMPLATE`) renderuje
faktury beze změny. Každý task to musí zachovat. Default větev resolveru = `invoice.twig` +
`styles/invoice.css`; `accentCss` s default variantou připojuje prázdný řetězec.

## File Structure
- **Create** `api/src/Service/Pdf/InvoiceTemplateResolver.php` — čistá třída: sanitizace + existence-check + fallback. Jediná odpovědnost: „z konfigurované hodnoty vrať platné cesty".
- **Create** `api/tests/Unit/Service/Pdf/InvoiceTemplateResolverTest.php`
- **Create** `api/tests/Unit/Service/Pdf/PdfBrandingVariantTest.php`
- **Create** `api/tests/Unit/Infrastructure/Config/ConfigInvoiceTemplateEnvTest.php`
- **Create** `api/templates/invoice/invoice-spotted.twig` (scaffold = kopie default)
- **Create** `styles/invoice-spotted.css` (scaffold = kopie default)
- **Modify** `api/src/Infrastructure/Config/Config.php` — env-map řádek
- **Modify** `api/src/Service/Pdf/PdfBranding.php` — `accentCss(array, string $variant='invoice')`
- **Modify** `api/src/Service/Pdf/InvoicePdfRenderer.php` — použít resolver (mtime, CSS, render name, brandAccentCss)
- **Modify** `.gitignore` — `docker-compose.rosti*.yml`
- **Modify** `.github/workflows/release.yml` — matrix deploy
- **Modify** `docs/FORK-CHANGES.md` — sekce G

---

## Task 0: Prerekvizita — lokální PHP deps pro testy

**Files:** žádné (jen prostředí). Repo vyžaduje PHP ^8.5; lokálně je 8.4.7 (parsuje stejně).

- [ ] **Step 1: Nainstaluj api závislosti pro běh PHPUnitu**

```bash
cd /Users/vladimirantos/Projects/myinvoice/api
PHP8=/opt/homebrew/Cellar/php/8.4.7/bin/php
$PHP8 $(which composer) install --ignore-platform-req=php
```

Expected: `vendor/` se vytvoří, `vendor/bin/phpunit` existuje.

- [ ] **Step 2: Ověř, že stávající testy projdou (baseline)**

```bash
cd /Users/vladimirantos/Projects/myinvoice/api
/opt/homebrew/Cellar/php/8.4.7/bin/php vendor/bin/phpunit --testsuite Unit 2>&1 | tail -15
```

Expected: zelená (nebo známý baseline). Pokud něco padá už teď, zapiš a pokračuj — není to z naší změny.

---

## Task 1: Config — ENV mapování `MYINVOICE_INVOICE_TEMPLATE`

**Files:**
- Modify: `api/src/Infrastructure/Config/Config.php` (env-map tabulka, ~ř. 240)
- Test: `api/tests/Unit/Infrastructure/Config/ConfigInvoiceTemplateEnvTest.php`

- [ ] **Step 1: Napiš failing test**

```php
<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Infrastructure\Config;

use MyInvoice\Infrastructure\Config\Config;
use PHPUnit\Framework\TestCase;

final class ConfigInvoiceTemplateEnvTest extends TestCase
{
    private string $tmpDir;
    /** @var string|false */
    private $envBackup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->envBackup = getenv('MYINVOICE_INVOICE_TEMPLATE');
        $this->tmpDir = sys_get_temp_dir() . '/myinvoice-tplenv-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0700, true);
        file_put_contents($this->tmpDir . '/cfg.php', "<?php\nreturn ['db'=>['host'=>'127.0.0.1','port'=>3306,'name'=>'x','user'=>'x','pass'=>'x']];\n");
    }

    protected function tearDown(): void
    {
        if ($this->envBackup === false) {
            putenv('MYINVOICE_INVOICE_TEMPLATE');
            unset($_ENV['MYINVOICE_INVOICE_TEMPLATE'], $_SERVER['MYINVOICE_INVOICE_TEMPLATE']);
        } else {
            putenv('MYINVOICE_INVOICE_TEMPLATE=' . $this->envBackup);
        }
        @unlink($this->tmpDir . '/cfg.php');
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    public function testEnvOverridesInvoiceTemplate(): void
    {
        putenv('MYINVOICE_INVOICE_TEMPLATE=spotted');
        $_ENV['MYINVOICE_INVOICE_TEMPLATE'] = 'spotted';
        $_SERVER['MYINVOICE_INVOICE_TEMPLATE'] = 'spotted';
        $config = Config::load($this->tmpDir);
        self::assertSame('spotted', $config->get('pdf.invoice_template'));
    }

    public function testDefaultWhenEnvAbsent(): void
    {
        putenv('MYINVOICE_INVOICE_TEMPLATE');
        unset($_ENV['MYINVOICE_INVOICE_TEMPLATE'], $_SERVER['MYINVOICE_INVOICE_TEMPLATE']);
        $config = Config::load($this->tmpDir);
        self::assertSame('invoice', $config->get('pdf.invoice_template', 'invoice'));
    }
}
```

- [ ] **Step 2: Spusť test — musí padnout**

Run: `cd api && /opt/homebrew/Cellar/php/8.4.7/bin/php vendor/bin/phpunit --filter ConfigInvoiceTemplateEnvTest`
Expected: FAIL na `testEnvOverridesInvoiceTemplate` (get vrací null, ne 'spotted').

- [ ] **Step 3: Přidej env-map řádek do `Config.php`**

V metodě s env→klíč tabulkou (vrací pole, sekce `// App`, hned za `MYINVOICE_LOCALE`):

```php
            'MYINVOICE_LOCALE'      => ['app.locale_default', 'string'],
            'MYINVOICE_INVOICE_TEMPLATE' => ['pdf.invoice_template', 'string'],
```

- [ ] **Step 4: Spusť test — musí projít**

Run: `cd api && /opt/homebrew/Cellar/php/8.4.7/bin/php vendor/bin/phpunit --filter ConfigInvoiceTemplateEnvTest`
Expected: PASS (oba).

- [ ] **Step 5: Commit**

```bash
cd /Users/vladimirantos/Projects/myinvoice
git add api/src/Infrastructure/Config/Config.php api/tests/Unit/Infrastructure/Config/ConfigInvoiceTemplateEnvTest.php
git commit -m "feat(pdf): ENV MYINVOICE_INVOICE_TEMPLATE → pdf.invoice_template"
```

---

## Task 2: `InvoiceTemplateResolver` (sanitizace + fallback)

**Files:**
- Create: `api/src/Service/Pdf/InvoiceTemplateResolver.php`
- Test: `api/tests/Unit/Service/Pdf/InvoiceTemplateResolverTest.php`

- [ ] **Step 1: Napiš failing test**

```php
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
```

- [ ] **Step 2: Spusť — musí padnout**

Run: `cd api && /opt/homebrew/Cellar/php/8.4.7/bin/php vendor/bin/phpunit --filter InvoiceTemplateResolverTest`
Expected: FAIL (třída neexistuje).

- [ ] **Step 3: Vytvoř třídu**

`api/src/Service/Pdf/InvoiceTemplateResolver.php`:

```php
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
```

- [ ] **Step 4: Spusť — musí projít**

Run: `cd api && /opt/homebrew/Cellar/php/8.4.7/bin/php vendor/bin/phpunit --filter InvoiceTemplateResolverTest`
Expected: PASS (všech 5).

- [ ] **Step 5: Commit**

```bash
cd /Users/vladimirantos/Projects/myinvoice
git add api/src/Service/Pdf/InvoiceTemplateResolver.php api/tests/Unit/Service/Pdf/InvoiceTemplateResolverTest.php
git commit -m "feat(pdf): InvoiceTemplateResolver — sanitizace + fallback varianty faktury"
```

---

## Task 3: `PdfBranding::accentCss` variant-seam (invariant)

**Files:**
- Modify: `api/src/Service/Pdf/PdfBranding.php` (ř. 73 signatura + návrat)
- Test: `api/tests/Unit/Service/Pdf/PdfBrandingVariantTest.php`

- [ ] **Step 1: Napiš failing test (default = beze změny)**

```php
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

    public function testUnknownVariantDoesNotBreak(): void
    {
        // Neznámá varianta nesmí shodit (scaffold zatím sdílí default akcent).
        $css = PdfBranding::accentCss($this->supplier(), 'spotted');
        self::assertIsString($css);
    }
}
```

- [ ] **Step 2: Spusť — musí padnout**

Run: `cd api && /opt/homebrew/Cellar/php/8.4.7/bin/php vendor/bin/phpunit --filter PdfBrandingVariantTest`
Expected: FAIL (accentCss nemá 2. parametr → ArgumentCountError).

- [ ] **Step 3: Přidej variant-seam (výstup defaultu beze změny)**

V `api/src/Service/Pdf/PdfBranding.php` změň signaturu metody (ř. 73) z:

```php
    public static function accentCss(array $supplier): string
    {
```

na:

```php
    public static function accentCss(array $supplier, string $variant = 'invoice'): string
    {
```

A na KONCI metody změň `return "...";` (poslední `return` skládající CSS string) tak, že
celý dosavadní výsledek ulož do proměnné a připoj per-variant rozšíření. Konkrétně: dosavadní

```php
        return "\n/* ─── Branding override (per-supplier accent color) ─── */\n"
            . ".brand-name { color: {$color}; }\n"
            // … (celý stávající řetězec beze změny) …
            . ".wr-title, .wr-link { color: {$color}; }\n";
    }
```

nahraď za:

```php
        $base = "\n/* ─── Branding override (per-supplier accent color) ─── */\n"
            . ".brand-name { color: {$color}; }\n"
            // … (celý stávající řetězec PONECHAT beze změny) …
            . ".wr-title, .wr-link { color: {$color}; }\n";

        // Per-variant rozšíření akcentu. Default i scaffold 'spotted' sdílí stejné třídy
        // (spotted.css je zatím kopie invoice.css), takže nic nepřipojujeme — výstup pro
        // stávající instanci je bitově identický. Až bude mít spotted vlastní třídy,
        // přidá se sem větev (a barva dál přijde z $supplier.email_accent_color).
        $variantCss = match ($variant) {
            default => '',
        };

        return $base . $variantCss;
    }
```

(Pozn.: nezasahuj do výpočtu `$color`/`$lineSoft`/… nad tím — měň jen finální skládání.)

- [ ] **Step 4: Spusť — musí projít**

Run: `cd api && /opt/homebrew/Cellar/php/8.4.7/bin/php vendor/bin/phpunit --filter PdfBrandingVariantTest`
Expected: PASS (oba).

- [ ] **Step 5: Commit**

```bash
cd /Users/vladimirantos/Projects/myinvoice
git add api/src/Service/Pdf/PdfBranding.php api/tests/Unit/Service/Pdf/PdfBrandingVariantTest.php
git commit -m "feat(pdf): PdfBranding::accentCss variant-seam (default beze změny)"
```

---

## Task 4: Zapojit resolver do `InvoicePdfRenderer`

**Files:**
- Modify: `api/src/Service/Pdf/InvoicePdfRenderer.php` (ř. 70–73 mtime, 207, 250 CSS, 266 render, 315 brandAccentCss)

Žádný nový test (chování pokryto Task 2/3 + manuální smoke v Task 8). Cíl: výběr varianty
na jednom místě, default větev = identické cesty jako dnes.

- [ ] **Step 1: Přidej helper pro resolved variantu**

Do třídy (např. pod `brandAccentCss`, ř. ~319) přidej:

```php
    /** @return array{variant:string, twigName:string, twigPath:string, cssPath:string} */
    private function resolvedTemplate(): array
    {
        return (new InvoiceTemplateResolver(Bootstrap::rootDir()))
            ->resolve((string) $this->config->get('pdf.invoice_template', 'invoice'));
    }
```

A nahoře doplň import:

```php
use MyInvoice\Service\Pdf\InvoiceTemplateResolver;
```

(Pozn.: pokud je `InvoiceTemplateResolver` ve stejném namespace `MyInvoice\Service\Pdf`,
import není nutný — ověř namespace v hlavičce souboru.)

- [ ] **Step 2: Cache-key mtime používá variantu (ř. 70–73)**

Nahraď:

```php
        $tplMtime = max(
            @filemtime(Bootstrap::rootDir() . '/styles/invoice.css') ?: 0,
            @filemtime(Bootstrap::rootDir() . '/api/templates/invoice/invoice.twig') ?: 0,
            @filemtime(__FILE__) ?: 0,
        );
```

za:

```php
        $tpl = $this->resolvedTemplate();
        $tplMtime = max(
            @filemtime($tpl['cssPath']) ?: 0,
            @filemtime($tpl['twigPath']) ?: 0,
            @filemtime(__FILE__) ?: 0,
        );
```

- [ ] **Step 3: CSS cesta v `renderHtmlAndCss` (ř. 207) + `renderHtml` (ř. 250)**

Na obou místech nahraď:

```php
        $cssPath = Bootstrap::rootDir() . '/styles/invoice.css';
```

za:

```php
        $cssPath = $this->resolvedTemplate()['cssPath'];
```

- [ ] **Step 4: Render používá variantní twig name (ř. 266)**

Nahraď `return $twig->render('invoice.twig', [` za:

```php
        return $twig->render($this->resolvedTemplate()['twigName'], [
```

- [ ] **Step 5: `brandAccentCss` předá variantu do PdfBranding (ř. 315)**

Nahraď tělo:

```php
    private function brandAccentCss(array $supplier): string
    {
        // Sdíleno s výkazem víceprací — viz PdfBranding::accentCss.
        return PdfBranding::accentCss($supplier);
    }
```

za:

```php
    private function brandAccentCss(array $supplier): string
    {
        // Sdíleno s výkazem víceprací — viz PdfBranding::accentCss.
        return PdfBranding::accentCss($supplier, $this->resolvedTemplate()['variant']);
    }
```

- [ ] **Step 6: Lint + ověř, že default cesta je nedotčená**

```bash
cd /Users/vladimirantos/Projects/myinvoice
/opt/homebrew/Cellar/php/8.4.7/bin/php -l api/src/Service/Pdf/InvoicePdfRenderer.php
cd api && /opt/homebrew/Cellar/php/8.4.7/bin/php vendor/bin/phpunit --testsuite Unit 2>&1 | tail -8
```

Expected: `No syntax errors`, Unit zelená.

- [ ] **Step 7: Commit**

```bash
cd /Users/vladimirantos/Projects/myinvoice
git add api/src/Service/Pdf/InvoicePdfRenderer.php
git commit -m "feat(pdf): InvoicePdfRenderer volí variantu šablony/CSS přes resolver"
```

---

## Task 5: Scaffold varianty `spotted` (kopie defaultu)

**Files:**
- Create: `api/templates/invoice/invoice-spotted.twig`
- Create: `styles/invoice-spotted.css`

- [ ] **Step 1: Zkopíruj default jako scaffold**

```bash
cd /Users/vladimirantos/Projects/myinvoice
cp api/templates/invoice/invoice.twig api/templates/invoice/invoice-spotted.twig
cp styles/invoice.css styles/invoice-spotted.css
```

- [ ] **Step 2: Přidej hlavičkový komentář, že jde o scaffold**

Do prvního řádku `api/templates/invoice/invoice-spotted.twig` za stávající úvodní komentář
přidej řádek:

```twig
{# VARIANTA: spotted — scaffold (zatím = default). Vizuál se naplní v samostatném follow-upu. #}
```

A do `styles/invoice-spotted.css` na první řádek:

```css
/* VARIANTA: spotted — scaffold (zatím = kopie invoice.css). Vizuál až ve follow-upu. */
```

- [ ] **Step 3: Twig parse + resolver test se spotted soubory**

```bash
cd /Users/vladimirantos/Projects/myinvoice/api
/opt/homebrew/Cellar/php/8.4.7/bin/php -r '
require "vendor/autoload.php";
$src = file_get_contents("templates/invoice/invoice-spotted.twig");
$t = new \Twig\Environment(new \Twig\Loader\ArrayLoader(["t"=>$src]));
$t->addFunction(new \Twig\TwigFunction("t", fn(...$a)=>""));
$t->parse($t->tokenize(new \Twig\Source($src,"t")));
echo "TWIG OK\n";'
```

Expected: `TWIG OK`.

- [ ] **Step 4: Commit**

```bash
cd /Users/vladimirantos/Projects/myinvoice
git add api/templates/invoice/invoice-spotted.twig styles/invoice-spotted.css
git commit -m "feat(pdf): scaffold varianty faktury 'spotted' (kopie default)"
```

---

## Task 6: `.gitignore` + provisioning compose (dokumentace)

**Files:**
- Modify: `.gitignore`

`docker-compose.rosti.spotted.yml` je secret → do gitu NEpatří. Plán dodá jeho obsah jako
referenci; soubor vytvoří provozní krok mimo git.

- [ ] **Step 1: Rozšiř .gitignore vzor**

V `.gitignore` najdi řádek `docker-compose.rosti.yml` a nahraď ho za:

```
docker-compose.rosti.yml
docker-compose.rosti.*.yml
```

- [ ] **Step 2: Commit**

```bash
cd /Users/vladimirantos/Projects/myinvoice
git add .gitignore
git commit -m "chore: gitignore docker-compose.rosti.*.yml (per-stack secrets)"
```

- [ ] **Step 3: Vytvoř lokální compose pro spotted (mimo git)**

Zkopíruj `docker-compose.rosti.yml` → `docker-compose.rosti.spotted.yml` a uprav:
- `MYINVOICE_APP_URL: "https://invoice.spotted-ai.com"`
- `MYINVOICE_INVOICE_TEMPLATE: "spotted"`  *(nový řádek do `environment:` app služby)*
- nové DB heslo (app i db sekce), `MYINVOICE_DB_PASS` = `MARIADB_PASSWORD`
- **čerstvé** `MYINVOICE_PEPPER` a `MYINVOICE_SECRET_KEY`:

```bash
echo "PEPPER:     $(openssl rand -base64 32)"
echo "SECRET_KEY: $(openssl rand -base64 32)"
```

- SMTP nové firmy (`MYINVOICE_SMTP_*`, `*_FROM_EMAIL`, `*_FROM_NAME`)

Ověř, že soubor není trackovaný:

```bash
cd /Users/vladimirantos/Projects/myinvoice
git check-ignore docker-compose.rosti.spotted.yml && echo "ignored OK"
```

Expected: `ignored OK`.

---

## Task 7: CI — matrix deploy obou stacků

**Files:**
- Modify: `.github/workflows/release.yml`

Cíl: build/push image jednou, pak deploy přes oba pull+up endpointy. Druhý endpoint =
nový GH secret `PULL_ENDPOINT_SPOTTED`.

- [ ] **Step 1: Přečti aktuální deploy krok**

```bash
cd /Users/vladimirantos/Projects/myinvoice
sed -n '17,120p' .github/workflows/release.yml
```

- [ ] **Step 2: Převed „Call pull+up endpoint" na matrix přes endpointy**

V jobu, kde běží deploy, přidej pod `jobs:` (nebo do stávajícího jobu) **matrix jen pro
notifikační krok**. Nejjednodušší bezpečná varianta bez duplikace buildu: ponech build/push
beze změny a uprav jen krok „Call pull+up endpoint" tak, že iteruje přes seznam endpointů
z env. Nahraď v tom kroku úvod `PULL_ENDPOINT="$PULL_ENDPOINT"` smyčkou:

```yaml
      - name: Call pull+up endpoint (oba stacky)
        env:
          PULL_ENDPOINT: ${{ secrets.PULL_ENDPOINT }}
          PULL_ENDPOINT_SPOTTED: ${{ secrets.PULL_ENDPOINT_SPOTTED }}
        run: |
          set -u
          endpoints="$PULL_ENDPOINT $PULL_ENDPOINT_SPOTTED"
          overall_rc=0
          for EP in $endpoints; do
            [ -n "$EP" ] || { echo "::warning::endpoint prázdný, přeskakuji"; continue; }
            echo "── deploy endpoint: ${EP%%\?*} ──"
            attempts=5
            ok=0
            for i in $(seq 1 $attempts); do
              RESPONSE=$(curl -sS --connect-timeout 15 --max-time 300 "$EP") && CURL_RC=0 || CURL_RC=$?
              STATUS=$(printf '%s' "$RESPONSE" | grep -o '"status"[^,]*' || true)
              if [ "$CURL_RC" = "0" ]; then
                echo "✓ OK (pokus $i)"; ok=1; break
              fi
              echo "::warning::pokus $i neúspěšný (curl_rc=$CURL_RC, status='${STATUS:-<ne-JSON>}')"
              sleep 10
            done
            [ "$ok" = "1" ] || { echo "::error::endpoint ${EP%%\?*} nedoběhl po $attempts pokusech"; overall_rc=1; }
          done
          exit $overall_rc
```

(Drží naši robustifikaci: retry, `--max-time`, tolerance ne-JSON. Selhání jednoho endpointu
nezablokuje druhý, ale workflow skončí červeně.)

- [ ] **Step 3: Odstraň starý samostatný „Test that PULL_ENDPOINT is set" guard, pokud koliduje**

Pokud existuje krok `Test that PULL_ENDPOINT is set` se `test -n "$PULL_ENDPOINT"`, ponech ho
(stávající stack je povinný); nový endpoint je volitelný (smyčka prázdný přeskočí).

- [ ] **Step 4: YAML lint**

```bash
cd /Users/vladimirantos/Projects/myinvoice
/opt/homebrew/Cellar/php/8.4.7/bin/php -r '$y=file_get_contents(".github/workflows/release.yml"); echo substr_count($y,"\t")===0 ? "no tabs OK\n":"TABS!\n";'
python3 -c "import yaml,sys; yaml.safe_load(open('.github/workflows/release.yml')); print('YAML OK')" 2>/dev/null || echo "ověř YAML ručně (python yaml chybí)"
```

Expected: `no tabs OK`, `YAML OK`.

- [ ] **Step 5: Commit**

```bash
cd /Users/vladimirantos/Projects/myinvoice
git add .github/workflows/release.yml
git commit -m "ci(release): matrix pull+up deploy obou stacků (PULL_ENDPOINT + _SPOTTED)"
```

- [ ] **Step 6: (mimo repo) Přidej GH secret**

V GitHub repo → Settings → Secrets → Actions přidej `PULL_ENDPOINT_SPOTTED` (pull+up URL
nového Rosti stacku). Bez něj se druhý deploy jen přeskočí (workflow nespadne).

---

## Task 8: FORK-CHANGES sekce G + finální verifikace

**Files:**
- Modify: `docs/FORK-CHANGES.md`

- [ ] **Step 1: Přidej sekci G**

Před řádek `_Naposledy aktualizováno:` vlož:

```markdown
## G. Per-instance varianta faktury — KEEP (fork feature, multi-instance)
ENV `MYINVOICE_INVOICE_TEMPLATE` (default `invoice`) volí variantu PDF šablony. Default = dnešní
chování (stávající instance se nemění). Soubory: `InvoiceTemplateResolver` (sanitizace+fallback),
`PdfBranding::accentCss($supplier, $variant)` seam, `InvoicePdfRenderer` (mtime/CSS/render dle
resolveru), varianta `invoice-spotted.twig` + `styles/invoice-spotted.css`. Upstream tyhle
soubory nemá → bez konfliktů; mechanismus je malý isolovaný diff. CI (`release.yml`) deployuje
oba stacky přes matrix endpointů.

```

- [ ] **Step 2: Plný Unit test suite + PHP lint dotčených**

```bash
cd /Users/vladimirantos/Projects/myinvoice/api
PHP8=/opt/homebrew/Cellar/php/8.4.7/bin/php
$PHP8 vendor/bin/phpunit --testsuite Unit 2>&1 | tail -10
for f in src/Service/Pdf/InvoiceTemplateResolver.php src/Service/Pdf/PdfBranding.php src/Service/Pdf/InvoicePdfRenderer.php src/Infrastructure/Config/Config.php; do $PHP8 -l "$f"; done
```

Expected: Unit zelená, vše `No syntax errors`.

- [ ] **Step 3: Manuální smoke (invariant + varianta) — v běžící app / Dockeru**

Stávající instance (bez ENV) → stáhni PDF faktury, vizuálně porovnej s dnešním (musí být shodné).
Pak lokálně/na test stacku nastav `MYINVOICE_INVOICE_TEMPLATE=spotted`, ověř, že faktura stále
rendruje (scaffold = vypadá jako default). Zapiš výsledek.

- [ ] **Step 4: Commit**

```bash
cd /Users/vladimirantos/Projects/myinvoice
git add docs/FORK-CHANGES.md
git commit -m "docs(fork): sekce G — per-instance varianta faktury"
```

---

## Self-review (autor plánu)
- **Spec coverage:** Invariant → Task 3/4 (default beze změny) + Task 8 smoke. Komponenta A → Task 1–5. Komponenta B → Task 6. Komponenta C → Task 7. „Zdarma (data)" → žádný kód (správně). Mimo rozsah (vizuál spotted) → scaffold v Task 5, naplnění follow-up. ✓
- **Placeholders:** žádné TBD; kód kompletní v každém kroku. ✓
- **Type consistency:** `resolve()` vrací `{variant,twigName,twigPath,cssPath}` napříč Task 2/4; `accentCss(array,string)` v Task 3/4; config klíč `pdf.invoice_template` v Task 1/4. ✓
