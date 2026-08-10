#!/usr/bin/env sh
set -eu

# Bind-mountnutý /data (např. Rosti `./data:/data`) přebírá ownership hostitele
# (root) a maskuje tím `chown www-data /data` z Dockerfile (build-time). Apache
# pak běží jako www-data a nemůže psát logy/storage → file logging mlčí, import
# worker se nespustí (nohup … >> /data/log/… selže), uploady/PDF/přílohy padají.
# Entrypoint běží jako root, takže ownership srovnáme tady. Plný rekurzivní chown
# jen když je /data ještě root-vlastněný (první start) — na dalších startech skip,
# ať to není pomalé na velkém volume.
WWW_UID="$(id -u www-data 2>/dev/null || echo 33)"
if [ -d /data ] && [ "$(stat -c %u /data 2>/dev/null || echo 0)" != "$WWW_UID" ]; then
  echo "[entrypoint] srovnávám ownership /data → www-data (bind-mount fix)"
  chown -R www-data:www-data /data 2>/dev/null || true
fi

if [ "${MYINVOICE_SKIP_MIGRATIONS:-0}" != "1" ]; then
  attempts="${MYINVOICE_MIGRATE_ATTEMPTS:-20}"
  delay="${MYINVOICE_MIGRATE_DELAY:-3}"
  current_attempt=1
  while :; do
    if php /var/www/html/api/bin/migrate.php; then
      break
    fi
    if [ "$current_attempt" -ge "$attempts" ]; then
      echo "Migration failed after $attempts attempts. Aborting startup." >&2
      exit 1
    fi
    echo "Migration attempt $current_attempt/$attempts failed. Retrying in ${delay}s..." >&2
    current_attempt=$((current_attempt + 1))
    sleep "$delay"
  done
fi

# Vestavěný cron (default zapnutý; multi-replica deployment si dá MYINVOICE_ENABLE_CRON=0,
# jinak by úlohy běžely v každé replice). Spouští se PO migracích, aby schéma bylo hotové.
if [ "${MYINVOICE_ENABLE_CRON:-1}" != "0" ]; then
  # Cron v Debianu nedědí ENV kontejneru → vydumpujeme ho pro wrapper. Obsahuje tajemství
  # (DB heslo, SMTP, klíče), proto jen pro root + www-data (0640), ne world-readable.
  export -p > /etc/myinvoice-cron.env
  chmod 0640 /etc/myinvoice-cron.env
  chown root:www-data /etc/myinvoice-cron.env 2>/dev/null || true
  # Selhání cronu nesmí shodit kontejner (Apache poběží dál).
  if cron; then
    echo "[entrypoint] vestavěný cron spuštěn (logy v \${MYINVOICE_DATA_DIR}/log/cron)"
  else
    echo "[entrypoint] VAROVÁNÍ: cron se nepodařilo spustit — pokračuji bez něj" >&2
  fi
fi

exec apache2-foreground
