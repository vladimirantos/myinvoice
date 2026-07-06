# Poznámky — design (2026-07-06)

Fork feature (upstream nemá). Volné poznámky v sekci **Dokumenty**: formulář pro přidání,
výpis, editace, mazání.

## Rozhodnutí (schváleno Vladimírem)

- **Volné poznámky** — jen titulek + text, žádná vazba na faktury/klienty/dokumenty.
- **Markdown** — text se píše v Markdownu a vykresluje formátovaně; renderuje sdílený
  mini-renderer (extrahovaný z `admin/Update.vue` do `web/src/utils/markdown.ts`,
  escapuje HTML → bez XSS, bez nové závislosti).
- **Společné pro instanci** — bez supplier/user scope (jednouživatelská instance).
- **Varianta A UI** — jedna stránka `/notes`: seznam karet (titulek, datum poslední změny,
  vyrenderovaný obsah), fulltext filtr (klient-side, titulek+text), modal pro novou/editaci
  (titulek + textarea s přepínačem Náhled), mazání s `confirm()`.

## Architektura

- **DB:** `notes` (id, title VARCHAR(200), body MEDIUMTEXT, created_at, updated_at) —
  migrace `0126_notes.sql`, idempotentní.
- **API:** `GET/POST/PUT/DELETE /api/notes` v auth skupině; `NotesAction` (validace:
  titulek povinný, ≤200 znaků; body ≤1 MB) + `NoteRepository`; činnosti do activity logu
  (`note.created/updated/deleted`).
- **FE:** `pages/notes/Notes.vue` + `api/notes.ts`; menu položka v Dokumenty
  (ikona tužka+čtverec), i18n cs/en. Řazení dle `updated_at DESC`.
- **Chyby:** API vrací standardní `Json::error` (`validation_failed` 400, `not_found` 404);
  FE toasty jako ostatní stránky.
- **Testy:** `NotesActionValidationTest` (unit, bez DB — Connection je lazy);
  CRUD end-to-end pokrývá CI + ověření na produkci po deploy.

## Fork dopady

Viz `docs/FORK-CHANGES.md` sekce K (migrace číslo 0126 — hlídat kolizi s upstreamem;
`markdown.ts` extrakce se dotýká `Update.vue`).
