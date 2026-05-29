# Spec: Hromadný import přijatých faktur z PDF/fotek (bez Anthropic API)

**Datum:** 2026-05-29
**Stav:** návrh k revizi

## Kontext a cíl

Uživatel má lokální složku `faktury/` se **157 soubory** (153 PDF, 3 JPG, 1 HEIC),
členěno po letech 2022–2026. Chce je naimportovat jako **přijaté faktury**
(`purchase_invoices`) + **dodavatele** (`clients` s `is_vendor=1`) do produkce
(Rosti stack 508).

MyInvoice má vestavěný AI import (`AiPdfExtractor` → `AnthropicClient`), ale ten
volá placené Anthropic API (BYOK), což uživatel nechce. Klíčová myšlenka:
**extrakci dat udělá Claude přímo v chatu (zdarma)**, a vznikne strukturovaný
manifest, který se nahraje **stejnou logikou appky** jako AI import — jen bez
volání Anthropicu.

## Omezení / požadavky

- **Žádné Anthropic API.** Extrakci dělá asistent v konverzaci, ne app runtime.
- **Účetní přesnost.** Data jdou do reálného účetnictví → povinný **review krok**
  (uživatel zkontroluje manifest před zápisem).
- **Maximální recyklace kódu** (zvolená cesta C) — žádné syrové SQL INSERTy;
  veškerá business logika (variabilní symbol, DPH klasifikace, ČNB kurz,
  zaokrouhlení, reverse charge, dedup, příloha PDF) se znovupoužije.
- **Idempotence.** Opakované spuštění nevytvoří duplicity.
- Cílem je produkce (DB na stacku 508), zdroj souborů je lokální Mac.

## Architektura — 3 komponenty

### 1. Refaktor `AiPdfExtractor` (enabler, beze změny chování)

Z `extractAndCreate()` se vytkne post-extrakční logika (aktuálně řádky ~121–219:
validace, cross-tenant guard, resolve dodavatele, `createDraft`, attach PDF) do
nové veřejné metody:

```php
public function createFromExtractedData(
    int $supplierId,
    int $userId,
    array $data,            // tvar shodný s AnthropicClient::extractInvoice()['data']
    string $fileBytes,      // bytes přílohy (PDF) pro attach + dedup hash
    ?string $originalFilename = null,
): array                    // {ok, purchase_invoice_id?, vendor_id?, source, error?}
```

`extractAndCreate()` zůstane zpětně kompatibilní: dedup podle SHA-256 + ISDOC
priorita + `anthropic->extractInvoice()` → delegace na `createFromExtractedData()`.
Refaktor je behavior-preserving (čistě extrakce metody) a zlepší testovatelnost
stávajícího AI importu.

### 2. Extrakce dat (dělá asistent, zdarma) → `manifest.json`

Asistent přečte všech 157 souborů v konverzaci. Fotky (3 JPG + 1 HEIC) převede na
PDF přes `sips` (macOS), ať jdou stejnou cestou a sedí i příloha. Z každé faktury
vytáhne strukturovaná data **přesně ve tvaru, který vrací `extractInvoice()['data']`**:

```json
{
  "file": "2024/03_2024/wedos-4024xxxxxx.pdf",
  "vendor": { "company_name": "...", "ic": "...", "dic": "..." },
  "customer": {},
  "vendor_invoice_number": "4024xxxxxx",
  "document_kind": "invoice|credit_note|advance|receipt",
  "issue_date": "YYYY-MM-DD",
  "tax_date": "YYYY-MM-DD|null",
  "due_date": "YYYY-MM-DD",
  "currency": "CZK|EUR|USD|...",
  "items": [
    { "description": "...", "quantity": 1, "unit": "ks",
      "unit_price_without_vat": 1000, "vat_rate": 21 }
  ],
  "total_without_vat": 1000,
  "total_with_vat": 1210,
  "total_with_vat_rounded": 1210,
  "already_paid": false,
  "advance_reference": null
}
```

- `customer` se nechává prázdný → cross-tenant guard se přeskočí (vše jsou přijaté
  faktury, tenant je vždy odběratel).
- Pole respektují validaci `validateAiData()` (povinné: vendor s company_name/ic,
  vendor_invoice_number, issue_date YYYY-MM-DD, currency ISO 4217, ≥1 item s
  description/quantity/unit_price_without_vat).

### 3. Seed skript `api/bin/import-manifest.php`

CLI skript ve stylu `import-worker.php`. Argument `--manifest=<cesta>`
(a `--supplier-id=`, `--user-id=`). Pro každý záznam:
1. načte bytes souboru (relativně k base dir manifestu),
2. zavolá `AiPdfExtractor::createFromExtractedData(supplierId, userId, $entry, $bytes, basename)`,
3. zaloguje výsledek (ok / duplicate / error) do stdout + souhrn na konci.

Re-runnable: dedup v `createFromExtractedData` (SHA-256 PDF i číslo+datum+vendor).

## Data flow

```
faktury/ (Mac)
  → [asistent: čtení + sips konverze fotek] → manifest.json
  → [uživatel: REVIEW částek/DPH/měny]
  → [zabalit faktury/ + manifest.json → stack: docker cp do kontejneru]
  → docker exec stack-app-1 php api/bin/import-manifest.php --manifest=/tmp/import/manifest.json
  → purchase_invoices + clients(is_vendor) na produkci
```

## Error handling & reporting

- Seed skript zpracovává faktury **nezávisle** — chyba u jedné nezastaví ostatní.
- Per soubor: `[OK #id]` / `[DUP #id]` / `[FAIL: důvod]`.
- Na konci souhrn: vytvořeno / duplicity / chyby (z N).
- Chyby z `createFromExtractedData` (validace, vendor=tenant, create failed) se
  vypíší s názvem souboru, aby šly cíleně opravit v manifestu a re-runnout.

## Idempotence

- Dvojitý dedup ve stávající logice: (a) SHA-256 přílohy (`findIdByPdfHash`),
  (b) vendor+číslo+datum (`findIdByVendorInvoice`). Re-run → existující se přeskočí.

## Mimo rozsah (YAGNI)

- Žádné nové UI (import je jednorázový, přes CLI).
- Žádná změna AI import flow kromě vytknutí metody.
- Žádné syrové SQL / migrace pro data.
- OCR kvalita fotek: pokud bude HEIC/JPG nečitelný, danou položku označíme v
  manifestu k ručnímu doplnění (nezdržuje zbytek).

## Rizika

- **Přesnost extrakce** u 157 zahraničních faktur (DPH režim, měna, reverse
  charge) — mitigace: povinný review manifestu + sanity-check logika appky
  (`maybeFlagTotalsMismatch`, `extraction_warning`) zůstává aktivní.
- **Objem extrakce** (157 souborů v jednom chatu je token-náročné) — řeší se po
  dávkách, případně paralelním workflow po explicitním souhlasu uživatele.
- **Přenos souborů na stack** — faktury jsou soukromé; přenos přes SSH/docker cp,
  složka `faktury/` je gitignored i dockerignored (neukládá se do image/gitu).

## Testy

- Unit test refaktoru: `createFromExtractedData()` s fixture `$data` vytvoří
  fakturu se správnými poli (varsymbol, currency, items) — bez Anthropic mocku.
- Ověření, že `extractAndCreate()` se chová stejně jako dřív (regrese).
- Smoke: seed skript proti malému manifestu (2–3 záznamy) na stacku.
