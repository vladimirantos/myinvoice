# Multi-instance branding — dvě instalace z jednoho repa

**Datum:** 2026-06-18
**Stav:** návrh ke schválení
**Kontext:** Nasazujeme myinvoice do druhého Rosti stacku pro novou firmu **spotted**
(čistá DB). Obě instance běží z jednoho repa / jednoho image, ale s jiným logem,
barvami faktury, e-maily a **úplně jiným designem PDF faktury**.

---

## Tvrdý invariant (nepřekročitelný)

**Stávající instance (`invoice.vladimirantos.cz`) se NESMÍ chovat jinak.**
Vyladěný design faktury zůstává beze změny. Celý mechanismus je proto **aditivní a
opt-in přes ENV s defaultem na dnešní chování**. Refaktor rendereru musí pro default
variantu produkovat shodný výstup — hlídá to regresní test (viz Testy).

---

## Co je „zdarma" (data, žádný kód)

Tyto věci jsou už dnes **per-instance data** (DB + `/data` volume) a nová firma si je
nastaví přímo v aplikaci po prvním přihlášení — neprogramuje se:

| Branding | Kde žije |
|---|---|
| Logo firmy | `/data/storage/supplier-logos/sup-{id}.png` (upload v Nastavení) |
| Razítko / podpis | `/data/storage/supplier-signatures/sup-{id}.png` |
| Akcentová barva faktury | `supplier.email_accent_color` (DB) → `PdfBranding` |
| E-mail branding (logo+barva) | per-supplier DB + nahraný soubor |
| E-mail šablony | tabulka `email_templates` (DB override + file defaults), editor v adminu |
| Údaje firmy, IČO/DIČ, banka… | `supplier` (DB), setup wizard |

**Pravidlo barvy:** i nová varianta faktury bere akcent z `supplier.email_accent_color`
(konzistentní s dnešní instancí), ne z žádné nové konfigurace.

---

## Rozsah

**V rozsahu (tento spec):** mechanismus per-instance varianty PDF faktury + scaffold
varianty `spotted` + provisioning druhého stacku + CI deploy obou stacků.

**Mimo rozsah (samostatný follow-up):** konkrétní **vizuální design** faktury `spotted`
(`invoice-spotted.twig` / `invoice-spotted.css` obsah). Tento spec dodá scaffold
(varianta = kopie defaultu, ať PDF rendruje); skutečný vzhled vznikne zvlášť přes
frontend-design, až bude branding firmy.

---

## Komponenta A — ENV varianta šablony faktury (kód)

Jediná část v kódu. Vše ostatní je data nebo infra.

### A1. `api/src/Service/Pdf/InvoicePdfRenderer.php`

Dnešní stav (zjištěno):
- Šablona natvrdo `render('invoice.twig')` (ř. 266), loader `FilesystemLoader(.../templates/invoice')` (ř. 345).
- CSS natvrdo `Bootstrap::rootDir().'/styles/invoice.css'` na ř. 207 a 250.
- Cache-key používá `filemtime` šablony (ř. 72) a `invoice.css` (ř. 71).
- Konstruktor už má injektovaný `private readonly Config $config`.

Změny:
1. **Resol varianty** — nový privátní helper, např. `resolveTemplateVariant(): array`
   vrací `['twig' => '<varianta>.twig', 'css' => Bootstrap::rootDir().'/styles/<varianta>.css']`.
   - Hodnotu čte z `Config` (klíč mapovaný z ENV `MYINVOICE_INVOICE_TEMPLATE`, default `invoice`),
     mapování dle stávajícího vzoru v `Config.php` pro ostatní `MYINVOICE_*`.
   - **Sanitizace:** povol jen `^[a-z0-9-]+$`; cokoli jiného → default `invoice` (anti path-traversal).
   - **Fallback:** pokud `<varianta>.twig` nebo `<varianta>.css` neexistuje → použij default
     `invoice` + `error_log` warning. Špatná konfigurace nesmí shodit fakturaci.
2. **Sjednotit použití** — render (ř. 266), obě CSS cesty (ř. 207, 250) i cache-key mtime
   (ř. 71–72) čtou z tohoto jednoho helperu. Cíl: výběr varianty žije na jednom místě,
   ne rozsypaný na 4.
3. **Default větev je bitově dnešní cesta** — když helper vrátí `invoice`, jsou to přesně
   `invoice.twig` + `styles/invoice.css` jako teď.

### A2. `api/src/Service/Pdf/PdfBranding.php`

- `accentCss(string $color, string $variant = 'invoice'): string` — dispatch na per-variant
  privátní metodu. Default parametr `'invoice'` → **dnešní logika beze změny**.
- Pro `spotted` přidat metodu, která přebarvuje CSS třídy `spotted` šablony. (mPDF neumí CSS
  custom properties → explicitní per-class override, stejně jako dnes.) Pro scaffold může
  zatím delegovat na default, naplní se s reálným designem ve follow-upu.
- Renderer předá `accentCss($color, $variant)`.

### A3. Nové soubory v repu (instance-specific, aditivní)

- `api/templates/invoice/invoice-spotted.twig` — scaffold = kopie `invoice.twig`.
- `styles/invoice-spotted.css` — scaffold = kopie `invoice.css`.

Upstream tyhle soubory nikdy nemá → **bez merge konfliktů**. Mechanismus (A1/A2) je malý
isolovaný diff; zapsat do `docs/FORK-CHANGES.md` jako KEEP fork přídavek.

---

## Komponenta B — druhý Rosti stack (provisioning)

- Nový stack na Rosti, vlastní doména **`invoice.spotted-ai.com`**.
- Vlastní čistý `/data` volume → čistá DB + prázdné storage. První deploy: `docker-entrypoint.sh`
  pustí `migrate.php`, projedou se všechny migrace na prázdné DB (žádný ruční krok).
- Vlastní `docker-compose.rosti.spotted.yml` (**gitignored**, secrets) — odvozený z dnešního
  `docker-compose.rosti.yml`, ale s:
  - vlastními DB credentials (jiné heslo),
  - `MYINVOICE_APP_URL: https://invoice.spotted-ai.com`,
  - SMTP nové firmy (vlastní schránka / From),
  - **čerstvě vygenerovanými** `MYINVOICE_PEPPER` a `MYINVOICE_SECRET_KEY` (NE kopírovat ze
    stávající instance),
  - `MYINVOICE_INVOICE_TEMPLATE: spotted`.
- `.gitignore`: přidat `docker-compose.rosti.spotted.yml` (resp. rozšířit vzor na
  `docker-compose.rosti*.yml`).
- **První spuštění:** login → setup wizard → údaje firmy spotted, upload loga + razítka,
  accent color v jejích barvách, override e-mail šablon (DB) v jejích barvách.

---

## Komponenta C — CI deploy obou stacků

`.github/workflows/release.yml` dnes: jeden `deploy` job, build+push image do GHCR, pak
„Call pull+up endpoint" s `secrets.PULL_ENDPOINT` (retry + tolerance ne-JSON).

Změna:
- **Build/push image jednou** (sdílený GHCR image pro oba stacky).
- **Deploy přes matrix** přes dva pull+up endpointy jako GH secrets:
  `PULL_ENDPOINT` (stávající) + `PULL_ENDPOINT_SPOTTED` (nový stack).
- Zachovat stávající robustifikaci (retry, `--max-time`, tolerance ne-JSON) per endpoint.
- Selhání jednoho endpointu nezablokuje druhý (matrix `fail-fast: false`), ale workflow
  skončí červeně, aby bylo vidět, že jeden stack nedoběhl.

---

## Testy

- **Regrese defaultu (povinné):** render faktury bez `MYINVOICE_INVOICE_TEMPLATE` (= default
  `invoice`) je shodný před/po refaktoru — snapshot vyrendrovaného HTML/CSS (před mPDF).
  Garantuje tvrdý invariant.
- Varianta: `MYINVOICE_INVOICE_TEMPLATE=spotted` → renderer načte `invoice-spotted.twig`
  + `styles/invoice-spotted.css`.
- Fallback: neexistující varianta → default + zalogovaný warning, render nespadne.
- Sanitizace: hodnota mimo `[a-z0-9-]` (vč. `../`) → default, žádný traversal.
- Smoke: oba scaffoldy vyrendrují validní PDF.

---

## Merge-friendliness (fork hygiena)

- Nové soubory (`invoice-spotted.*`) upstream nemá → 0 konfliktů.
- Mechanismus v rendereru/PdfBranding = malý isolovaný diff; `PdfBranding` je už dnes KEEP
  fork soubor (sekce B v FORK-CHANGES).
- Zapsat do `docs/FORK-CHANGES.md`: nová sekce „G. Per-instance varianta faktury" (KEEP).

---

## Potvrzeno

- Slug: **`spotted`** (názvy souborů + ENV).
- Doména: **`invoice.spotted-ai.com`**.
