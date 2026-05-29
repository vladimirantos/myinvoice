-- MyInvoice.cz — Rozšíření sloupce `ic` pro zahraniční registrační čísla
--
-- České IČO má 8 číslic, ale importy z Fakturoid/iDoklad přenáší do `ic`
-- pole `registration_no`, které u zahraničních subjektů drží registrační
-- číslo libovolné délky (Handelsregister, company number apod.). Původní
-- VARCHAR(10) přetékal:
--   SQLSTATE[22001]: String data, right truncated:
--   1406 Data too long for column 'ic'
--
-- Rozšiřujeme na VARCHAR(32) v `clients` i `supplier` (oba mohou držet
-- zahraniční subjekt). `ares_cache.ic` záměrně NEMĚNÍME — je to PK pro
-- ARES lookupy, které jsou výhradně české (8místné IČO).
--
-- Idempotence: MODIFY je opakovatelně bezpečný (no-op při už správné šířce).

SET NAMES utf8mb4;

ALTER TABLE clients
  MODIFY COLUMN ic VARCHAR(32) NULL;

ALTER TABLE supplier
  MODIFY COLUMN ic VARCHAR(32) NULL;
