# Redesign vzhledu faktury (PDF) — minimalistický layout dle předlohy

**Datum:** 2026-05-30
**Stav:** návrh k odsouhlasení
**Autor:** Vladimír Antoš + Claude

## 1. Cíl

Sjednotit vzhled PDF faktury s referenční předlohou (`faktury/202601.pdf`) — čistý,
minimalistický, vzdušný layout. Není to systém přepínatelných „skinů" (ten záměr
padl) — jde o **jednu novou podobu faktury**, která nahradí stávající.

Vizuální cíl je odladěn v brainstorming companionu jako **skin A / verze v10**.

## 2. Východisko vs. cíl

Stávající šablona (`api/templates/invoice/invoice.twig` + `styles/invoice.css`)
renderuje přes **mPDF** a vypadá „boxovatě" a těžce. Předloha je minimalistická.
Klíčové rozdíly:

| Prvek | Předloha (v10) | Stávající šablona |
|---|---|---|
| Hlavička tabulky položek | bílá, tenká spodní linka, šedé verzálky | plný fialový pruh, bílý text |
| Strany (dodavatel/odběratel) | bez orámování, prostý text | orámované boxy s podkladem `#FBFAFD` |
| Metadata (banka, VS, platba, data) | vmíchaná do sloupců stran | samostatná tabulka „meta-grid" |
| Součet (celkem) | bez plné plochy — silná fialová linka + fialový text | plný fialový řádek, bílý text |
| Akcent nad „Faktura" | fialový pruh vpravo nahoře | — |
| Proužky nad „Dodavatel/Odběratel" | malé fialové | — |
| Logo vlevo nahoře | je | je (přes branding) |
| Razítko/podpis vpravo dole | je | **není** |

## 3. Rozhodnutí (odsouhlasena)

1. **Logo vlevo nahoře** — využít stávající upload loga (branding); zobrazit v novém layoutu vlevo nahoře.
2. **Razítko vpravo dole** — **nový** upload obrázku razítka/podpisu (PNG), protějšek loga.
3. **Extra pole** (DUZP, konst. symbol, měna, zakázka, vaše číslo/smlouva, CZK přepočet,
   sleva, odečet zálohy, K úhradě): jádro (banka, VS, způsob platby, data) jde do sloupců
   stran; **zbytek zůstává v kompaktním metadatovém pruhu** pod stranami (nic se nezahazuje).
4. **Styl** — plně minimalistický dle v10.
5. Žádný skin-selektor, žádné globální nastavení, žádná „skin" migrace.

## 4. Vizuální cíl (v10) — rozložení shora dolů

1. **Hlavička:** vlevo logo (nebo monogram + název jako fallback), vpravo fialový pruh →
   „Faktura {číslo}" → podtitul „Daňový doklad" (resp. typ dokladu). Bez plné spodní čáry.
2. **Strany:** dvousloupcově. Nad každým malý fialový proužek + verzálkový label
   „Dodavatel" / „Odběratel". Pod ním název, adresa, IČO/DIČ. Do levého sloupce navíc
   **Bankovní účet, Variabilní symbol, Způsob platby**; do pravého **Datum vystavení,
   Datum splatnosti, Datum zdan. plnění** — vše jako řádky „label vlevo šedě / hodnota vpravo".
3. **Kompaktní metadatový pruh** pod stranami: měna, konst. symbol, zakázka,
   vaše číslo/smlouva, ISDOC badge, RC poznámka apod. Pruh je součástí layoutu vždy;
   jednotlivé řádky se vykreslí jen když je pole vyplněné (`{% if %}` guardy jako dnes),
   takže prázdná pole nezabírají místo.
4. **Tabulka položek:** bílá hlavička s tenkou spodní linkou, šedé verzálky, rozvolněné
   sloupce. Sloupce pro plátce DPH: Popis · Mn. · Jed. · Cena/j · DPH · Bez DPH · S DPH.
   Pro neplátce: Popis · Mn. · Jed. · Cena/j · Celkem.
5. **Rekapitulace (vpravo):** řádky základ/DPH → **silná fialová linka** → velký fialový
   „Celkem" (bez plné plochy). Dále případně odečet zálohy / K úhradě, kurz ČNB.
6. **QR vlevo dole** + popisek „QR Platba". **Razítko vpravo dole.**
7. **Patička:** živnostenský rejstřík (commercial_register) + řádek firma · web · e-mail.
   mPDF page-footer „Používá MyInvoice.cz" zůstává.

## 5. Architektura a dotčené soubory

Render beze změny toku: `InvoicePdfRenderer::renderHtml()` → Twig `invoice.twig` (+ `css`
poslané do mPDF zvlášť) → mPDF. Cache se invaliduje přes mtime šablony/CSS/rendereru
(existující mechanika) — po deployi se PDF přegenerují.

| Vrstva | Soubor | Změna |
|---|---|---|
| DB | `db/migrations/0078_supplier_stamp.sql` | `ADD COLUMN stamp_path VARCHAR(255) NULL` na `supplier` |
| Backend upload | `api/src/Action/Settings/EmailBrandingAction.php` (nebo nová `StampAction`) | `uploadStamp` / `deleteStamp` (zrcadlí `uploadLogo`/`deleteLogo`) |
| Backend bezpečnost | `api/src/Service/Mail/SafeLogoPath.php` → analogická `SafeStampPath` | validace cesty `storage/supplier-stamps/sup-{sid}.{ext}` |
| Backend konverze | `SupplierLogoConverter` → reuse/obdoba pro razítko | normalizace PNG, max rozměry |
| Backend render | `api/src/Service/Pdf/PdfBranding.php` | `stampPath()` (gate jako logo) + akcentové selektory v `accentCss` |
| Backend render | `api/src/Service/Pdf/InvoicePdfRenderer.php` | předat `stamp_path` do Twig kontextu |
| Routy | tam, kde jsou branding routy | `POST/DELETE /api/settings/email-branding/stamp` |
| Šablona | `api/templates/invoice/invoice.twig` | přestavba layoutu (sekce 4) |
| Styl | `styles/invoice.css` | minimalistický redesign |
| Frontend | `web/src/pages/admin/Settings.vue` + `web/src/api/settings.ts` | upload/náhled razítka (vedle loga) |
| i18n | `web/src/i18n/cs.json`, `en.json` | popisky razítka |

## 6. mPDF omezení (zásadní pro implementaci)

- **Žádný flexbox/grid.** Layout výhradně přes `<table>` (v10 mockup je jen vizuální cíl).
- Mezery přes `&nbsp;` spacery / `width` na buňkách (ne padding na `<div>`) — viz stávající
  poznámky v CSS/šabloně.
- Bez vnořených tabulek tam, kde rozbíjí `border-collapse` (viz hlavička loga).
- SVG razítko/logo: jen mPDF-kompatibilní (stávající `SafeLogoPath`/`svgIsMpdfCompatible`).
- Akcentové prvky (pruh, proužky, linka) musí být přebarvitelné přes
  `PdfBranding::accentCss` — tedy odvozené od `#3B2D83`, ne natvrdo jiné.

## 7. Okrajové případy (nesmí se rozbít)

- **Typy dokladů:** faktura, **proforma** (bez DPH řádků, „není daňový doklad" poznámka),
  **dobropis** (credit-note — červený akcent), **storno** (cancellation — šedý, přeškrtnutý).
  Semantické barvy zůstávají; minimal redesign je nepřebarvuje.
- **Neplátce DPH** — užší sada sloupců, řádek „Není plátce DPH".
- **Více sazeb DPH** — souhrnné řádky základ/DPH + „Celkem bez DPH / DPH celkem".
- **Cizí měna** — blok „Přepočet do CZK" (kurz ČNB).
- **Sleva / odečet zálohy / K úhradě** — řádky v rekapitulaci.
- **Výkaz víceprací (2. strana)** — sdílí `.head`; akcentové změny hlavičky se projeví i tam,
  layout výkazu jinak beze změny.
- **Branding accent** — vlastní barva dodavatele přebarví i nové akcentové prvky.
- **Bez loga** — fallback monogram/název (jako dnes). **Bez razítka** — prostě se nevykreslí.

## 8. Testy a verifikace

- PHPUnit: existující PDF render testy musí projít; přidat test, že `stamp_path` doputuje
  do kontextu a že upload/delete razítka funguje (zrcadlo logo testů).
- Vizuální verifikace: vygenerovat PDF reálné faktury (plátce DPH, jedna sazba),
  porovnat s `202601.pdf`. Dále smoke: proforma, dobropis, neplátce, cizí měna, výkaz.
- Bezpečnost: `SafeStampPath` brání path traversal / mass-assign (stejně jako logo, sec #2).

## 9. Mimo rozsah

- Více skinů / přepínač skinů.
- Změna e-mailových šablon (řeší se brandingem zvlášť).
- Konfigurovatelné pozice prvků, vlastní fonty.

## 10. Otevřené body k potvrzení

- Razítko gate: zobrazit jen při zapnutém brandingu (jako logo), nebo vždy když je nahráno?
  **Návrh:** stejný gate jako logo (konzistence).
- Číslo migrace `0078` — ověřit, že nekoliduje s upstream při příštím mergi.
