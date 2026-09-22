-- MyInvoice — poznámky (fork feature, sekce Dokumenty).
--
-- Volný poznámkový blok pro celou instanci: titulek + Markdown text.
-- Bez vazby na supplier/user (vědomé rozhodnutí — jednouživatelská instance).
--
-- Idempotence: CREATE TABLE IF NOT EXISTS. Re-run safe (bezpečné i při
-- případném přečíslování nad budoucí upstream max — viz docs/FORK-CHANGES.md).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS notes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  body MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_notes_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
