# StreamFlix Community App Store

Selbst gehostete Streaming-Plattform im Stil von Netflix für UmbrelOS (Raspberry Pi ARM64).

## Repository-Struktur

```text
streamflix-community-store/          <- Repository-Wurzel (auf GitHub hochladen)
├── README.md
├── LICENSE
├── umbrel-app-store.yml             <- Store-Metadaten (id + name)
├── .github/
│   └── workflows/
│       └── docker-publish.yml       <- Baut & pusht Docker-Image automatisch
└── streamflix-streamflix/           <- App-Ordner (Name muss der App-ID entsprechen)
    ├── umbrel-app.yml               -> App-Manifest (Umbrel erkennt die App daran)
    ├── docker-compose.yml           -> Verwendet vorgebautes Image (ghcr.io)
    ├── Dockerfile                   -> PHP 8.2 + Nginx + FFmpeg (ARM64)
    ├── health.php
    ├── README.md                    -> App-spezifische Infos
    ├── docker/
    │   └── nginx.conf
    ├── app/                         -> PHP-Anwendung (Frontend, API, Admin)
    ├── database/                    -> SQLite-Schema
    └── scripts/                     -> Medien-Scanner, Backup, Cleanup
```

## Wichtige Hinweise vor dem GitHub-Upload

### 1. GitHub-Benutzername ersetzen
Öffne `streamflix-streamflix/umbrel-app.yml` und ersetze jeden Vorkommen von
`comaend` durch deinen tatsächlichen GitHub-Benutzernamen.

### 2. App-Icon und Galerie (optional)
Lege unter `streamflix-streamflix/` folgende Bilder ab:
- `icon.png` (App-Icon im Store)
- `1.png`, `2.png`, `3.png` (Galerie-Screenshots)
Aktualisiere die URLs in `umbrel-app.yml` entsprechend.

### 3. Repository-Wurzel
Die ZIP darf keine zusätzliche überflüssige Ordnerebene enthalten.
Der Inhalt von `streamflix-community-store/` muss direkt im Wurzelverzeichnis
des GitHub-Repos landen.

### 4. GitHub Actions aktivieren
Nach dem Upload auf GitHub:
- Repository → Settings → Actions → General → "Read and write permissions" aktivieren
- Der Workflow baut automatisch das Docker-Image und pusht es zu GHCR
- Ohne das Image kann Umbrel die App nicht installieren

## Installation auf UmbrelOS

1. ZIP-Datei herunterladen und entpacken.
2. Alle Dateien auf GitHub hochladen (Repository `streamflix`).
3. GitHub Actions laufen lassen (Image wird automatisch gebaut).
4. UmbrelOS öffnen → App Store → Community App Stores.
5. GitHub-Repository-URL einfügen: `https://github.com/comaend/streamflix`
6. Den neuen Community Store öffnen und **StreamFlix** auswählen.
7. Auf **Installieren** klicken. Umbrel zieht das Image und startet den Container.
8. Nach der Installation StreamFlix über die Umbrel-App-Übersicht öffnen.
9. Erster Administrator-Account erstellen (Registrierung).
10. Im Admin-Panel (`/admin`) Medienordner hinzufügen und die Bibliothek scannen.

## Medienordner einbinden

Ein Container kann nicht automatisch auf beliebige Host-Verzeichnisse zugreifen. Binde deine Medienordner schreibgeschützt ein:

- Über UmbrelOS: Einstellungen → Speicher → USB-Festplatte / Netzwerkspeicher einbinden.
- Oder in `docker-compose.yml` weitere Bind-Mounts eintragen, z. B.:
  ```yaml
  volumes:
    - streamflix_data:/var/www/html/data
    - /media:/media:ro
    - /mnt/hdd/Movies:/media/Movies:ro
    - /mnt/hdd/Series:/media/Series:ro
  ```
  Danach App in Umbrel neu starten.

Im Admin-Panel unter **Medienordner** die Pfade (z. B. `/media/Movies`, `/media/Series`) registrieren und einen Scan starten.

## Technologie

- Backend: PHP 8.2, SQLite, REST API, PHP Sessions
- Frontend: HTML5, CSS3, Vanilla JavaScript
- Streaming: FFmpeg, HTML5 Video, HLS, HTTP Range Requests
- Webserver: Nginx
- Datenbank: SQLite (keine externe Datenbank)

## Support

Probleme und Feature-Requests über die GitHub-Issues des Repositories.
