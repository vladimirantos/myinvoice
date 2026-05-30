-- MyInvoice.cz — Jednorázová datová oprava: označit všechny faktury jako zaplacené
--
-- ⚠️ NENÍ to schémová migrace, ale jednorázový datový zásah pro tuto konkrétní
-- instanci (soukromý deploy Vladimíra Antoše). Po hromadném importu historických
-- dokladů (2022–2026) se všechny faktury značí jako uhrazené.
--
-- Rozsah: VŠECHNY řádky tabulky `invoices` bez ohledu na status (včetně draftů
-- i stornovaných) → status = 'paid'. Záměr potvrzen uživatelem.
--
-- paid_at: zachováváme existující datum úhrady (u už zaplacených faktur je
-- přesnější než due_date); kde chybí, doplníme datem splatnosti (`due_date`,
-- které je NOT NULL, takže výsledek nikdy není NULL).
--
-- Idempotence: opakovaný běh je no-op — status už je 'paid' a COALESCE drží
-- jednou nastavené paid_at.

SET NAMES utf8mb4;

UPDATE invoices
   SET paid_at = COALESCE(paid_at, due_date),
       status  = 'paid';
