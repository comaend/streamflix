# STREAMFLIX (Umbrel-App `sflix-streamflix`)

* `umbrel-app.yml` – App-Manifest (Manifest-Version 1)
* `docker-compose.yml` – `app_proxy` + Dienst `web` (Build aus dem Dockerfile)
* `Dockerfile` – Alpine 3.20, nginx, PHP-FPM 8.3, FFmpeg, supervisor; läuft als unprivilegierter Benutzer
* `app/` – Anwendung (`public/` Webroot, `lib/` Logik, `database/` Schema + Migrationen, `scripts/` Scanner/Scheduler/Backup, `tests/`)
* `docker/` – nginx-, PHP-FPM-, supervisor-Konfiguration

Daten (SQLite, Bilder, Backups, Cache) liegen im Volume `${APP_DATA_DIR}/data` → `/data`. Medien: `/media` (read-only).
Siehe die README im Repository-Hauptverzeichnis für Installation und Betrieb.
