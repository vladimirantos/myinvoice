# Fork changes — `vladimirantos/myinvoice` vs upstream `radekhulan/myinvoice`

Soupis VŠECH odchylek našeho forku od upstreamu. Slouží jako vodítko při mergi nové
upstream verze. **Aktualizuj při každé další fork změně.**

Base: poslední mergnutá upstream verze = **v4.6.0** (merge commit `72ef26d`, 2026-05-29).
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

## D. Rozšíření IČ pro zahraniční registrační čísla — ADOPT UPSTREAM, kdyby dodal
| Soubor | Co |
|---|---|
| `db/migrations/0076_widen_ic_for_foreign_registration.sql` | `clients.ic`/`supplier.ic` → VARCHAR(32) (přečíslováno z 0070 kvůli kolizi s upstream `0070_payment_due_unit`) |
| kód `99c045c` | související import handling |

**Merge akce:** Když upstream přidá ekvivalentní rozšíření `ic` → **adoptuj jejich, naši 0076
dropni.** Vždy hlídej **kolize čísel migrací** (upstream přidává `00NN_*.sql`); naše vlastní
přečísluj nad jejich max (idempotentní, re-run neškodí).

## E. Instance-specific — NIKDY upstream
| Soubor | Co | Pozn. |
|---|---|---|
| `db/migrations/0077_mark_all_invoices_paid.sql` | Jednorázové: všechny faktury → `paid` (po importu historických dokladů na TÉTO instanci) | ⚠️ Běží na každé čisté DB → kdokoli jiný by si označil faktury zaplacené. **Do upstreamu NIKDY.** Zvážit odstranění po doběhnutí (idempotentní, takže neškodí, ale je matoucí). |
| `/faktury/` (gitignored) | Soukromá naskenovaná PDF pro jednorázový import | mimo git |

## F. Docs / specs — KEEP (bez konfliktu)
`docs/superpowers/specs/*`, `docs/superpowers/plans/*`, tento soubor. Naše plánovací dokumenty,
upstream je nemá → bez konfliktu.

---
_Naposledy aktualizováno: 2026-05-30 (po redesignu faktury + uploadu razítka)._
