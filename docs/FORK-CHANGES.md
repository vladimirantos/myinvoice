# Fork changes — `vladimirantos/myinvoice` vs upstream `radekhulan/myinvoice`

Soupis VŠECH odchylek našeho forku od upstreamu. Slouží jako vodítko při mergi nové
upstream verze. **Aktualizuj při každé další fork změně.**

Base: poslední mergnutá upstream verze = **v4.43.3** (merge 2026-06-30; předtím v4.42.0, v4.33.0, v4.6.0).
Aktuální fork rozsah: `git log upstream/master..master` (po fetchi upstreamu).

## ⚠️ Hlavní pravidlo při mergi
**Když upstream přidá funkci, která je ekvivalent té naší → ADOPTUJ UPSTREAM, naši zahoď.**
Naše vlastní implementace jsou jen „dokud to autor nedodá". Výjimka = záměrný custom
design (sekce B) a instance-specific věci (sekce E), které upstream nikdy mít nebude.

## Merge postup (z paměti `rosti-deploy-myinvoice`)
```bash
git fetch upstream
git merge vX.Y.Z --no-edit
# kolize čísel migrací: naše migrace přečísluj NAD upstream max (git mv)
# po mergi zkontroluj: .gitignore má Rosti blok, VERSION, entrypoint volá migrate.php
git push origin master   # → CI/CD nasadí
```

---

## A. Rosti deploy / infra — KEEP (upstream tohle nemá / má jinak)
| Soubor | Co | Merge akce |
|---|---|---|
| `.github/workflows/release.yml` | Rosti CI/CD (rosticli `setup-cicd` + naše robustifikace „Call pull+up endpoint" kroku: retry + tolerance ne-JSON proti proxy timeoutu) | **Keep ours.** Upstream má jiný `docker-publish.yml` (tagy). Náš release.yml řídí náš deploy. |
| `docker-compose.rosti.yml` | Rosti stack (gitignored — secrets) | Keep, mimo git |
| `docker-entrypoint.sh` | `chown www-data /data` při startu (bind-mount ownership fix, `a610c58`) | **Keep ours**, dokud upstream nevyřeší ownership ekvivalentně |
| `.gitignore`, `.dockerignore` | Rosti blok + `/faktury/` + `.superpowers/` | Merge ručně (drž naše řádky) |

## B. Redesign PDF faktury — KEEP (záměrný custom design, NEadoptovat upstream)
Minimalistický redesign dle vlastní předlohy (spec `docs/superpowers/specs/2026-05-30-faktura-redesign-design.md`). Detaily mPDF v paměti `faktura-pdf-mpdf`.

| Soubor | Co |
|---|---|
| `api/templates/invoice/invoice.twig` | Přepsaný layout: accent `<hr>` nad „Faktura", proužky stran `<td width>`, metadata ve sloupcích, bílá hlavička tabulky, součet bez plné plochy, patička v page-footeru, bez ISDOC badge |
| `styles/invoice.css` | Minimalistický styl k tomu |
| `api/src/Service/Pdf/InvoicePdfRenderer.php` | `resolveSignaturePath()` + předání `signature_path` do Twig; `margin_bottom` 18→26 + `margin_footer` 9 (místo pro patičku) |
| `api/src/Service/Pdf/PdfBranding.php` | `accentCss` přebarvuje nové akcentové prvky (accent-bar hr, party-tick td, grand border-top…) |

**Merge akce:** Při konfliktu **drž naši verzi** těchto souborů (je to náš design). Pokud
upstream zásadně vylepší šablonu (nové pole, daňová logika), přenes JEN tu logiku ručně
do našeho layoutu — nepřebírej celý jejich template/CSS.

## C. Upload razítka/podpisu — ADOPT UPSTREAM, kdyby ho dodal
Dotažení uploadu pro **upstream sloupec** `supplier.signature_path` (existuje v `0001_init`,
ale upstream pro něj zatím nemá UI/endpoint). Razítko se renderuje vpravo dole v PDF.

| Soubor | Co |
|---|---|
| `api/src/Service/Mail/SafeSignaturePath.php` | NOVÝ — validace cesty (zrcadlo `SafeLogoPath`, dir `supplier-signatures`) |
| `api/src/Service/Mail/SupplierLogoConverter.php` | `+$subdir` param (default `supplier-logos`) → reuse pro razítko; return navíc klíč `path` |
| `api/src/Action/Settings/EmailBrandingAction.php` | `uploadSignature` / `deleteSignature` |
| `api/src/Routes.php` | `POST/DELETE /api/settings/email-branding/signature` |
| `api/src/Action/Settings/SettingsAction.php` | `has_signature` flag |
| `web/src/api/settings.ts`, `web/src/pages/admin/Settings.vue`, `web/src/i18n/{cs,en}.json` | UI razítka v Nastavení (branding sekce) + i18n |

**Merge akce:** Pokud **upstream přidá vlastní signature/razítko upload** (na `signature_path`)
→ **zahoď naše (všechny soubory výše) a vezmi jejich.** Je to jejich sloupec, nejspíš na něm
postaví. Pozor: naše `SupplierLogoConverter` má navíc `$subdir` — pokud naše mažeme, vrať
converter na upstream verzi.

**Stav po mergi v4.33.0 (2026-06-16): KEEP — naše razítko zůstává.** Upstream přidal jen
**kryptografický PAdES podpis** (P12 cert + TSA, migrace `0076_supplier_pdf_signing`,
`0077_supplier_tsa_auth`, `0091–0093_signing_profiles`, stránka `ElectronicSignatures.vue`) —
to je *jiná* feature než náš vizuální obrázek do `signature_path`. `EmailBrandingAction` upstream
upload razítka nemá. Settings konflikty vyřešeny **sloučením obojího** (naše razítko + jejich
signing profily). `Settings.vue`: upstream přesunul currency-účty do nové `BankAccounts.vue`, tak
jsme **vzali upstream verzi a znovu aplikovali jen náš razítko blok** (script + branding UI).

## D. Rozšíření IČ pro zahraniční registrační čísla — ✅ ADOPTOVÁNO UPSTREAM (v4.33.0)
**Vyřešeno při mergi v4.33.0:** upstream dodal ekvivalent `db/migrations/0085_widen_ic_column.sql`
(`clients.ic`/`supplier.ic` → VARCHAR(20)). Naši `0076_widen_ic_for_foreign_registration.sql`
jsme **dropli** (`git rm`). VARCHAR(20) pokrývá i švýcarské `CHE-xxx.xxx.xxx` (15 znaků).
Commit `99c045c` byl jen ta migrace (žádný separátní import kód) → nic osiřelého nezůstalo.

_Pravidlo do budoucna:_ vždy hlídej **kolize čísel migrací** (upstream přidává `00NN_*.sql`);
naše vlastní přečísluj nad jejich max (idempotentní, re-run neškodí).

## E. Instance-specific — NIKDY upstream
| Soubor | Co | Pozn. |
|---|---|---|
| `db/migrations/0113_mark_all_invoices_paid.sql` | Jednorázové: všechny faktury → `paid` (po importu historických dokladů na TÉTO instanci). Přečíslováno z 0077 → 0113 (nad upstream max 0112) při mergi v4.33.0. | ⚠️ Běží na každé čisté DB → kdokoli jiný by si označil faktury zaplacené. **Do upstreamu NIKDY.** Zvážit odstranění po doběhnutí (idempotentní, takže neškodí, ale je matoucí). |
| `/faktury/` (gitignored) | Soukromá naskenovaná PDF pro jednorázový import | mimo git |

## F. Docs / specs — KEEP (bez konfliktu)
`docs/superpowers/specs/*`, `docs/superpowers/plans/*`, tento soubor. Naše plánovací dokumenty,
upstream je nemá → bez konfliktu.

---
## B. Redesign PDF faktury — KEEP + přeneseno (v4.33.0)
Při mergi v4.33.0 jsme **drželi náš layout/CSS** (`invoice.twig`, `invoice.css`, `PdfBranding.php`),
ale **ručně přenesli novou daňovou logiku** z upstreamu do našich `party-kv` tabulek a meta-stripu:
SK plátce DPH (DIČ vs IČ DPH, #120), národní daňová čísla klienta (SK/DE/AT/PL/HU `tax_number`,
#120), identifikovaná osoba (§ 6g–6l, #94 — vynechání řádku „Není plátce DPH" na zahr. RC dokladu).
Upstream font `jetbrainsmono` jsme do našeho CSS *nepřebírali* (držíme `DejaVu Sans Mono`).

---
## G. Multi-instance brandová varianta — KEEP (fork feature, 2026-06-18)
Dvě instalace z jednoho repa/image, odlišený branding per stack. Branding (logo, barva,
email accent) je per-instance **data** (DB + `/data` volume); jediný kód je výběr varianty:

| Co | Soubory |
|---|---|
| Výběr varianty faktury (ENV `MYINVOICE_INVOICE_TEMPLATE`, default `invoice`) | `InvoiceTemplateResolver` (sanitizace `[a-z0-9-]` + fallback), `InvoicePdfRenderer` (mtime/CSS/render/brandAccentCss dle resolveru), `Config` env-map |
| `PdfBranding::accentCss($s, $variant)` | default `invoice` beze změny (early-return seam); brandové varianty nesou paletu ve své CSS |
| Varianta faktury **spotted** | `api/templates/invoice/spotted.twig` + `styles/spotted.css` (černá #141414 / oranžová #D9512C / béžová #F2EEE6). Typografie (2026-06-30, dle předlohy): **Hanken Grotesk** (sans — nadpisy/labely/názvy), **Source Serif** (patky — adresy, popisy položek, próza), **JetBrains Mono** (čísla). Statické řezy `api/resources/fonts/{HankenGrotesk,SourceSerif}-{Regular,Bold}.ttf` instancované z variable fontů (mPDF je neumí) + OFL licence; registrované v `MpdfFontConfig` (aditivně, default `invoice` jede dál na Montserrat). QR platba v závěrečném pásu vpravo vedle poděkování. |
| Výchozí logo brandové varianty (2026-06-30) | `styles/spotted-logo.png` (zapečené lockup logo, předem flattnuté na bílý podklad bez alfy kvůli mPDF SMask #152). `InvoicePdfRenderer::variantDefaultLogoPath()` ho použije jako `logo_path`, **když dodavatel žádné vlastní nenahrál** (uploaded má přednost). Hledá `styles/{variant}-logo.png` → default `invoice` žádné nemá → null (vladimirantos faktura beze změny). Mtime assetu je v cache-signatuře. Zdrojové logo: `spotted/spotted logo/spotted-lockup-on-light.png` (gitignored working složka). |
| Brandová varianta e-mailů (ENV `MYINVOICE_BRAND_VARIANT`, default `''`) | `_layout.html.twig` opt-in `spotted` téma (default byte-identický); `brand_variant` injektován v `Mailer` + `EmailBrandingAction` preview; `Config` env-map |
| CI deploy obou stacků | `release.yml`: stack 1 (vladimirantos, 508) přes Rosti webhook `PULL_ENDPOINT`; stack 2 (spotted, 542) přes **SSH** (`SPOTTED_SSH_KEY` secret → `ssh root@ssh.rosti.cz:29019` → `docker compose pull && up`). Spotted je panel-managed stack pod company Spotted s.r.o. (7570), který rosticli/webhook nedosáhne. GHCR image je **veřejný** → bez registry creds. **Stack 2 má `if: always()`** — je nezávislý, nesmí ho blokovat selhání flaky stack-1 webhooku (2026-07-02). |
| Per-stack compose (secrets) | `docker-compose.rosti.*.yml` (gitignored); spotted: `…spotted.yml` s `MYINVOICE_INVOICE_TEMPLATE=spotted`, `MYINVOICE_BRAND_VARIANT=spotted`, vlastní DB/SMTP/PEPPER/SECRET_KEY. Compose je na 542 **Rosti-managed** (autoritativní je panel editor, `/srv/stack/docker-compose.yml`). |

**Tvrdý invariant:** stávající instance (`invoice.vladimirantos.cz`) tyto ENV nenastavuje →
default chování beze změny (ověřeno: resolver vrací dnešní cesty, `accentCss` default i
`_layout` default jsou byte-identické). Upstream tyhle soubory nemá → bez merge konfliktů.
Spec/plán: `docs/superpowers/{specs,plans}/2026-06-18-multi-instance-invoice-branding*`.

---
## H. Merge v4.42.0 (2026-06-28) — řešení konfliktů

5 konfliktních souborů (vše v oblasti PDF designu / razítka), vyřešeno:

**KEEP náš design (sekce B):**
- `invoice.twig`: party-tick accent bar (ne upstream `party-customer` box), naše šířky sloupců
  (26/10/6/13/9/18/18), paid-badge + qr-box (ne upstream `pay-panel` — platební metoda je u nás
  už nahoře v `party-kv` „Způsob platby", takže žádná ztráta info), patička v page-footeru
  (ne upstream inline `div.footer`).
- `invoice.css`: party-tick, discount-row, czk-recap border. Smazáno osiřelé `.parties td.party-customer`
  a nepřevzaté `.pay-panel`/`.bank-*`/`.vat` CSS (k neadoptovaným upstream prvkům).
- `PdfBranding.php`: náš `accentCss` (cílí naše třídy: accent-bar, party-tick, grand border-top).

**ADOPTOVÁNO z upstreamu (nová funkcionalita / fix, přeneseno do našeho layoutu):**
- **Výkaz materiálu** (migrace `0114_work_report_materials`): celá `{% if _has_material %}` tabulka
  na 2. stránce + detekce material-položky pro proklik (`_is_mat_item`) + dynamický titulek
  `_doc_type` („Výkaz prací a materiálu / víceprací / materiálu") — vloženo do našeho `doc-title`
  s accent-barem. Material tabulka používá `class="items"` → dědí náš design automaticky.
- `PdfLogoFlattener` (issue #152): splácnutí alfa kanálu PNG loga (auto-merge, vlastní upstream soubor).
- `SupplierLogoConverter::delete()`: přidána přípona `.pdf.png` do úklidu (drží náš `$subdir` param).
- `.page-supplier` kerning fix (letter-spacing 0.15pt + montserrat) — sloučeno s naším `margin-top`.

**Marginy:** drženy naše (`margin_bottom 26` + `margin_footer 9`, side 15) kvůli 3řádkové patičce —
ne upstream 18 / side 12.

**⚠️ Migrace `0113_mark_all_invoices_paid.sql` — VĚDOMĚ NEPŘEČÍSLOVÁNA** (odchylka od pravidla výše).
Upstream přidal `0113_payment_orders.sql` (kolize čísla), ale migrátor trackuje podle **plného názvu**
(`migrations.filename` PK), takže dvě `0113_*` koexistují bez problému — naše je už `applied` na obou
instancích → přeskočí se. Přečíslování na 0123 by migrátor vyhodnotil jako novou migraci a **spustil ji
znovu → označil by i aktuálně nezaplacené faktury jako paid (poškození dat na živé instanci).** Pravidlo
„přečísluj nad max" platí jen pro idempotentní migrace; tato není. Necháváme na 0113. (Případný cleanup =
`git rm` po doběhnutí — záznam v `migrations` na produkci zůstane, fresh DB ji pak přeskočí.)

---
## I. Merge v4.43.3 (2026-06-30) — bez konfliktů

Čistý auto-merge (12 commitů: recurring uzávěrky, GPC měnové účty, import iDoklad PDF / ISDOC
zaokrouhlení, archivace strojového zdroje přijaté faktury). **Žádný konflikt** — upstream se
nedotkl našich design/razítko souborů (jediný dotek `Routes.php` = auto-merge). Náš design faktury
beze změny.

Nová migrace `0123_purchase_invoice_source_artifact.sql` — **bez kolize** s naší fork migrací
(ta zůstává `0113_mark_all_invoices_paid.sql`; dobře, že nebyla minule přečíslována na 0123).

---
_Naposledy aktualizováno: 2026-06-30 (merge upstream v4.43.3)._
