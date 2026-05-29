# Hromadný import přijatých faktur z PDF/fotek — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Naimportovat 157 lokálních faktur (PDF/fotky) jako přijaté faktury + dodavatele do produkce, přes existující logiku appky, bez placeného Anthropic API.

**Architecture:** Asistent extrahuje data z faktur (zdarma, v chatu) do `manifest.json` ve tvaru, který vrací `AnthropicClient::extractInvoice()['data']`. Z `AiPdfExtractor` se vytkne post-extrakční logika do nové veřejné metody `createFromExtractedData()`. Jednorázový CLI skript `api/bin/import-manifest.php` projede manifest a pro každý záznam zavolá tuto metodu → vznikne přijatá faktura se vší business logikou (variabilní symbol, DPH klasifikace, ČNB kurz, zaokrouhlení, reverse charge, dedup, příloha PDF).

**Tech Stack:** PHP 8.5, Slim/PHP-DI kontejner, MariaDB 11, PHPUnit; deploy Rosti stack 508 (Docker).

---

## Soubory

- **Modify:** `api/src/Service/Import/AiPdfExtractor.php` — vytknout `createFromExtractedData()`, přidat do ní SHA-256 dedup; `extractAndCreate()` deleguje.
- **Create:** `api/bin/import-manifest.php` — CLI seed runner.
- **Create:** `api/tests/Integration/Import/CreateFromExtractedDataTest.php` — integrační test refaktoru.
- **Create (mimo git):** `faktury/manifest.json` — výstup extrakce (gitignored přes `/faktury/`).

---

## Task 1: Refaktor AiPdfExtractor — vytknout `createFromExtractedData()`

**Files:**
- Modify: `api/src/Service/Import/AiPdfExtractor.php`
- Test: `api/tests/Integration/Import/CreateFromExtractedDataTest.php`

- [ ] **Step 1: Napsat failing integrační test**

Create `api/tests/Integration/Import/CreateFromExtractedDataTest.php`:

```php
<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Import;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Import\AiPdfExtractor;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Ověřuje, že createFromExtractedData() vytvoří přijatou fakturu z manifest dat
 * (bez Anthropic volání) se správným dodavatelem, měnou a položkami.
 * Izolováno pod vendor s unikátním IČO, uklizeno v tearDown.
 */
#[Group('integration')]
final class CreateFromExtractedDataTest extends TestCase
{
    private Connection $db;
    private AiPdfExtractor $extractor;
    private int $supplierId = 0;
    private int $userId = 0;
    private array $piIds = [];
    private array $vendorIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        $container = Bootstrap::buildApp()->getContainer();
        $this->db = $container->get(Connection::class);
        $this->extractor = $container->get(AiPdfExtractor::class);
        $pdo = $this->db->pdo();
        $this->supplierId = (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn();
        $this->userId = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier/user — test vyžaduje seedovanou DB.');
        }
    }

    public function testCreatesPurchaseInvoiceFromManifestData(): void
    {
        $data = [
            'vendor' => ['company_name' => 'TEST Dodavatel SRO', 'ic' => '99999001', 'dic' => 'CZ99999001'],
            'customer' => [],
            'vendor_invoice_number' => 'TEST-MANIFEST-0001',
            'document_kind' => 'invoice',
            'issue_date' => '2099-01-15',
            'tax_date' => '2099-01-15',
            'due_date' => '2099-01-29',
            'currency' => 'CZK',
            'items' => [
                ['description' => 'Testovací služba', 'quantity' => 1, 'unit' => 'ks',
                 'unit_price_without_vat' => 1000, 'vat_rate' => 21],
            ],
            'total_without_vat' => 1000,
            'total_with_vat' => 1210,
        ];
        $pdfBytes = "%PDF-1.4\n% test\n";

        $res = $this->extractor->createFromExtractedData(
            $this->supplierId, $this->userId, $data, $pdfBytes, 'test.pdf', 'manifest'
        );

        $this->assertTrue($res['ok'], $res['error'] ?? '');
        $this->assertArrayHasKey('purchase_invoice_id', $res);
        $this->piIds[] = $res['purchase_invoice_id'];
        $this->vendorIds[] = $res['vendor_id'];

        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT vendor_invoice_number, currency_id FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$res['purchase_invoice_id']]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('TEST-MANIFEST-0001', $row['vendor_invoice_number']);

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM purchase_invoice_items WHERE purchase_invoice_id = ?');
        $stmt->execute([$res['purchase_invoice_id']]);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testSecondCallWithSamePdfBytesIsDeduped(): void
    {
        $data = [
            'vendor' => ['company_name' => 'TEST Dodavatel SRO', 'ic' => '99999001'],
            'customer' => [],
            'vendor_invoice_number' => 'TEST-MANIFEST-0002',
            'document_kind' => 'invoice',
            'issue_date' => '2099-02-15',
            'due_date' => '2099-02-15',
            'currency' => 'CZK',
            'items' => [['description' => 'X', 'quantity' => 1, 'unit' => 'ks', 'unit_price_without_vat' => 100, 'vat_rate' => 21]],
        ];
        $pdfBytes = "%PDF-1.4\n% dedup-test-unique\n";

        $first = $this->extractor->createFromExtractedData($this->supplierId, $this->userId, $data, $pdfBytes, 'd.pdf', 'manifest');
        $this->assertTrue($first['ok']);
        $this->piIds[] = $first['purchase_invoice_id'];
        $this->vendorIds[] = $first['vendor_id'];

        $second = $this->extractor->createFromExtractedData($this->supplierId, $this->userId, $data, $pdfBytes, 'd.pdf', 'manifest');
        $this->assertTrue($second['ok']);
        $this->assertSame($first['purchase_invoice_id'], $second['purchase_invoice_id']);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();
        foreach ($this->piIds as $id) {
            $pdo->prepare('DELETE FROM purchase_invoice_items WHERE purchase_invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
        }
        foreach (array_unique($this->vendorIds) as $vid) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$vid]);
        }
    }
}
```

- [ ] **Step 2: Spustit test — musí selhat (metoda neexistuje)**

Run: `cd api && ./vendor/bin/phpunit --filter CreateFromExtractedDataTest`
Expected: FAIL — `Call to undefined method MyInvoice\Service\Import\AiPdfExtractor::createFromExtractedData()` (nebo skip pokud chybí cfg.php/DB → pak ověření přesuneme na Task 2 smoke test).

- [ ] **Step 3: Refaktor — vytknout metodu**

V `api/src/Service/Import/AiPdfExtractor.php`:

(a) V `extractAndCreate()` nahradit blok od `$data = $extracted['data'];` (ř. ~121) až po konec metody (ř. ~219) tímto:

```php
        $data = $extracted['data'];

        $result = $this->createFromExtractedData($supplierId, $userId, $data, $pdfBytes, $originalFilename, 'ai');
        // Doplnit model/usage z AI odpovědi (createFromExtractedData je nezná).
        if (($result['ok'] ?? false)) {
            $result['model'] = $extracted['model'] ?? null;
            $result['usage'] = $extracted['usage'] ?? null;
        }
        return $result;
    }

    /**
     * Vytvoří přijatou fakturu z již extrahovaných dat (tvar shodný s
     * AnthropicClient::extractInvoice()['data']) — bez volání AI. Sdíleno AI
     * importem i jednorázovým manifest importem (api/bin/import-manifest.php).
     *
     * @param array<string,mixed> $data
     * @return array{ok:bool, purchase_invoice_id?:int, vendor_id?:int, source:string, error?:string, ai_data?:array, duplicate?:bool}
     */
    public function createFromExtractedData(
        int $supplierId,
        int $userId,
        array $data,
        string $fileBytes,
        ?string $originalFilename = null,
        string $source = 'ai',
    ): array {
        // SHA-256 dedup (idempotence pro re-run manifest importu i AI re-upload).
        $sha256 = hash('sha256', $fileBytes);
        $existingId = $this->repo->findIdByPdfHash($supplierId, $sha256);
        if ($existingId !== null) {
            return ['ok' => true, 'purchase_invoice_id' => $existingId, 'source' => 'duplicate', 'duplicate' => true];
        }

        $validationError = $this->validateAiData($data);
        if ($validationError !== null) {
            return ['ok' => false, 'error' => 'Data neprošla validací: ' . $validationError, 'ai_data' => $data, 'source' => $source];
        }
```

(b) Zbytek přesunutého kódu (cross-tenant guard od `$tenantIc = $this->fetchTenantIc(...)`, vendor resolve, `createDraft` + `attachPdf` + return) ponechat BEZE ZMĚNY uvnitř nové metody, jen u úspěšného returnu nahradit `'source' => 'ai'` za `'source' => $source` a odstranit z něj `'model'`/`'usage'` (ty doplňuje volající). Konec metody:

```php
        try {
            $invoiceId = $this->createDraft($data, $supplierId, $userId, $resolved['id']);
            $this->attachPdf($invoiceId, $supplierId, $fileBytes, $originalFilename);
            return [
                'ok'                  => true,
                'purchase_invoice_id' => $invoiceId,
                'vendor_id'           => $resolved['id'],
                'source'              => $source,
                'ai_data'             => $data,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Vytvoření faktury selhalo: ' . $e->getMessage(), 'ai_data' => $data, 'source' => 'create_failed'];
        }
    }
```

POZOR: SHA-256 dedup zůstává i na začátku `extractAndCreate()` (ř. 60–71) — vyhne se zbytečnému AI volání. V `createFromExtractedData` je podruhé kvůli manifest cestě (která `extractAndCreate` neprochází).

- [ ] **Step 4: Spustit test — musí projít**

Run: `cd api && ./vendor/bin/phpunit --filter CreateFromExtractedDataTest`
Expected: PASS (2 testy), nebo SKIP bez cfg.php/DB.

- [ ] **Step 5: Commit**

```bash
git add api/src/Service/Import/AiPdfExtractor.php api/tests/Integration/Import/CreateFromExtractedDataTest.php
git commit -m "refactor(import): vytkni AiPdfExtractor::createFromExtractedData() pro reuse bez AI"
```

---

## Task 2: Seed skript `api/bin/import-manifest.php`

**Files:**
- Create: `api/bin/import-manifest.php`

- [ ] **Step 1: Napsat skript**

Create `api/bin/import-manifest.php`:

```php
<?php

declare(strict_types=1);

/**
 * Jednorázový import přijatých faktur z manifestu (data extrahovaná mimo app,
 * bez Anthropic API). Každý záznam projde stejnou logikou jako AI import přes
 * AiPdfExtractor::createFromExtractedData(). Re-runnable (dedup SHA-256 + číslo+datum+vendor).
 *
 * Usage:
 *   php api/bin/import-manifest.php --manifest=/tmp/import/manifest.json \
 *       [--supplier-id=1] [--user-id=1] [--base-dir=/tmp/import]
 */

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Service\Import\AiPdfExtractor;

$opts = getopt('', ['manifest:', 'supplier-id::', 'user-id::', 'base-dir::']);
$manifestPath = $opts['manifest'] ?? null;
if (!is_string($manifestPath) || !is_file($manifestPath)) {
    fwrite(STDERR, "Usage: php import-manifest.php --manifest=PATH [--supplier-id=1] [--user-id=1] [--base-dir=DIR]\n");
    exit(1);
}
$supplierId = (int) ($opts['supplier-id'] ?? 1);
$userId     = (int) ($opts['user-id'] ?? 1);
$baseDir    = rtrim((string) ($opts['base-dir'] ?? dirname($manifestPath)), '/');

$entries = json_decode((string) file_get_contents($manifestPath), true);
if (!is_array($entries)) {
    fwrite(STDERR, "Manifest není validní JSON pole.\n");
    exit(2);
}

$extractor = Bootstrap::buildApp()->getContainer()->get(AiPdfExtractor::class);

$created = 0; $dup = 0; $failed = 0; $i = 0; $n = count($entries);
foreach ($entries as $entry) {
    $i++;
    $file = (string) ($entry['file'] ?? '');
    $path = $baseDir . '/' . ltrim($file, '/');
    if ($file === '' || !is_file($path)) {
        $failed++;
        fwrite(STDOUT, "[{$i}/{$n}] FAIL: soubor nenalezen: {$file}\n");
        continue;
    }
    try {
        $res = $extractor->createFromExtractedData(
            $supplierId, $userId, $entry, (string) file_get_contents($path), basename($file), 'manifest'
        );
    } catch (\Throwable $e) {
        $failed++;
        fwrite(STDOUT, "[{$i}/{$n}] FAIL: {$file}: " . $e->getMessage() . "\n");
        continue;
    }
    if (!($res['ok'] ?? false)) {
        $failed++;
        fwrite(STDOUT, "[{$i}/{$n}] FAIL: {$file}: " . ($res['error'] ?? 'neznámá chyba') . "\n");
        continue;
    }
    if (($res['duplicate'] ?? false)) {
        $dup++;
        fwrite(STDOUT, "[{$i}/{$n}] DUP #{$res['purchase_invoice_id']}: {$file}\n");
        continue;
    }
    $created++;
    fwrite(STDOUT, "[{$i}/{$n}] OK #{$res['purchase_invoice_id']} (vendor {$res['vendor_id']}): {$file}\n");
}

fwrite(STDOUT, "\n=== Souhrn: vytvořeno {$created}, duplicity {$dup}, chyby {$failed} (z {$n}) ===\n");
exit($failed > 0 ? 6 : 0);
```

- [ ] **Step 2: Lokální lint (syntaxe)**

Run: `php -l api/bin/import-manifest.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add api/bin/import-manifest.php
git commit -m "feat(import): jednorázový seed skript import-manifest.php (manifest → přijaté faktury)"
```

---

## Task 3: Extrakce dat → `manifest.json` (provádí asistent)

**Files:**
- Create (gitignored): `faktury/manifest.json`

- [ ] **Step 1: Převést fotky na PDF (macOS sips)**

Pro 3 JPG + 1 HEIC vytvořit PDF vedle originálu, ať jdou stejnou cestou:

```bash
cd /Users/vladimirantos/Projects/myinvoice/faktury
find . -type f \( -iname '*.jpg' -o -iname '*.jpeg' -o -iname '*.heic' \) -print0 \
 | while IFS= read -r -d '' f; do sips -s format pdf "$f" --out "${f%.*}.pdf"; done
```
Expected: pro každý obrázek vznikne `*.pdf` ve stejné složce.

- [ ] **Step 2: Přečíst faktury a vytvořit manifest**

Asistent přečte všechny PDF (po letech 2022→2026, po dávkách) a zapíše
`faktury/manifest.json` — JSON pole, každý záznam:
```json
{ "file": "<relativní cesta od faktury/>",
  "vendor": {"company_name": "...", "ic": "...", "dic": "..."},
  "customer": {},
  "vendor_invoice_number": "...",
  "document_kind": "invoice",
  "issue_date": "YYYY-MM-DD", "tax_date": "YYYY-MM-DD|null", "due_date": "YYYY-MM-DD",
  "currency": "CZK",
  "items": [{"description": "...", "quantity": 1, "unit": "ks", "unit_price_without_vat": 0, "vat_rate": 21}],
  "total_without_vat": 0, "total_with_vat": 0 }
```
Pravidla extrakce:
- `file` ukazuje na PDF (u fotek na převedené PDF z kroku 1).
- `customer` vždy `{}` (přeskočí cross-tenant guard).
- Nečitelnou položku označit `"_needs_review": true` + co chybí; nezdržovat zbytek.
- Zahraniční faktury (Amazon/JetBrains/Ubiquiti): `vat_rate: 0` u všech řádků → stávající logika dopočítá reverse charge; měnu nechat dle PDF (USD/EUR), ČNB kurz dopočítá app.

- [ ] **Step 3: Validovat manifest (JSON + povinná pole)**

Run:
```bash
cd /Users/vladimirantos/Projects/myinvoice
php -r '$e=json_decode(file_get_contents("faktury/manifest.json"),true);
$bad=0; foreach($e as $i=>$x){ foreach(["file","vendor","vendor_invoice_number","issue_date","currency","items"] as $k){ if(empty($x[$k])){fwrite(STDERR,"#$i chybí $k\n");$bad++;}}}
echo "Záznamů: ".count($e).", chybných: $bad\n";'
```
Expected: `chybných: 0` (kromě položek označených `_needs_review`).

---

## Task 4: Review gate (uživatel)

- [ ] **Step 1: Uživatel zkontroluje `faktury/manifest.json`**

Projít částky / DPH režim / měnu / datumy proti PDF, hlavně u zahraničních faktur a u záznamů s `_needs_review`. Opravit přímo v JSON. Toto je brána — bez odsouhlasení se nepokračuje na zápis do produkce.

---

## Task 5: Deploy na stack a spuštění importu

**Files:** žádné nové (operační task)

- [ ] **Step 1: Nasadit nový kód (refaktor + skript) na produkci**

```bash
cd /Users/vladimirantos/Projects/myinvoice
git push origin master   # CI/CD build amd64 → ghcr → Rosti pull+restart
```
Po deploy ověřit, že kontejner běží nový image (viz memory: pull+up race):
```bash
rosticli stacks ssh docker inspect stack-app-1 --format '{{.Image}}'
rosticli stacks ssh docker image inspect ghcr.io/vladimirantos/myinvoice:latest --format '{{.Id}}'
# pokud nesedí: rosticli stacks ssh docker compose up -d app
```
Expected: stejné digesty; `docker exec stack-app-1 test -f api/bin/import-manifest.php` projde.

- [ ] **Step 2: Přenést faktury + manifest na stack a do kontejneru**

```bash
cd /Users/vladimirantos/Projects/myinvoice
tar czf /tmp/faktury-import.tgz -C faktury .
scp -P 28291 /tmp/faktury-import.tgz root@ssh.rosti.cz:/tmp/   # port viz rosticli stacks info
rosticli stacks ssh sh -c 'mkdir -p /srv/stack/import && tar xzf /tmp/faktury-import.tgz -C /srv/stack/import'
rosticli stacks ssh docker cp /srv/stack/import stack-app-1:/tmp/import
```
Expected: `/tmp/import/manifest.json` + faktury existují v kontejneru.

- [ ] **Step 3: SMOKE — import jen prvních 2–3 záznamů**

```bash
rosticli stacks ssh docker exec stack-app-1 sh -c 'php -r "\$e=json_decode(file_get_contents(\"/tmp/import/manifest.json\"),true); file_put_contents(\"/tmp/import/manifest.smoke.json\", json_encode(array_slice(\$e,0,3)));"'
rosticli stacks ssh docker exec stack-app-1 php api/bin/import-manifest.php --manifest=/tmp/import/manifest.smoke.json --supplier-id=1 --user-id=1 --base-dir=/tmp/import
```
Expected: `OK #<id>` pro každý, souhrn `chyby 0`. Ověřit v UI (Přijaté faktury) i v DB:
```bash
rosticli stacks ssh docker exec stack-db-1 sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" myinvoice -e "SELECT id,vendor_invoice_number,status,total_with_vat FROM purchase_invoices ORDER BY id DESC LIMIT 5;"'
```

- [ ] **Step 4: Plný import**

```bash
rosticli stacks ssh docker exec stack-app-1 php api/bin/import-manifest.php --manifest=/tmp/import/manifest.json --supplier-id=1 --user-id=1 --base-dir=/tmp/import
```
Expected: souhrn `vytvořeno X, duplicity Y, chyby Z (z 157)`. Smoke faktury z kroku 3 se objeví jako `DUP` (idempotence). Vypsané `FAIL` řádky opravit v manifestu a skript spustit znovu (duplicity se přeskočí).

- [ ] **Step 5: Verifikace + úklid**

```bash
rosticli stacks ssh docker exec stack-db-1 sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" myinvoice -e "SELECT COUNT(*) AS faktur, (SELECT COUNT(*) FROM clients WHERE is_vendor=1) AS dodavatelu FROM purchase_invoices;"'
rosticli stacks ssh rm -rf /srv/stack/import /tmp/faktury-import.tgz
rosticli stacks ssh docker exec stack-app-1 rm -rf /tmp/import
```
Expected: počet faktur ≈ počet validních záznamů manifestu; dočasné soubory smazány (faktury přílohy zůstávají v `/data/storage/purchase-invoices`).

---

## Poznámky k provedení

- **Test execution (Task 1):** produkční image je `--no-dev` (bez phpunit). Test spusť v lokálním dev prostředí s `composer install` (dev deps) a `cfg.php` mířícím na lokální DB (stack na portu 8888, DB 127.0.0.1:3307). Bez něj test SKIPne — praktickou bránou je pak Task 5 smoke test.
- **Idempotence:** celý import lze opakovat; dedup zabrání duplicitám. Bezpečné spouštět po opravách manifestu.
- **Soukromí:** `faktury/` je gitignored i dockerignored; na stack se přenáší jen dočasně přes SSH a po importu se maže (přílohy zůstávají v storage volume).
