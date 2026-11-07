# STREAMFLIX – Community App Store für UmbrelOS

Selbst gehostete Streaming-Plattform im Netflix-Stil für **deine eigenen** Filme und Serien.
Dieses Repository ist ein **Umbrel Community App Store** mit der App `sflix-streamflix`.

## Funktionen
Mehrere Konten mit Profilen (PIN, Kinderprofil, eigene Liste/Verlauf/Positionen/Bewertungen), Weiterschauen, Empfehlungen,
Suche & Filter, Untertitel, Audiospuren, Intro/Abspann überspringen, nächste Episode, Direct Play oder HLS (FFmpeg),
Medienordner-Manager mit Scanner, vollständiges Admin-Center (Dashboard, Benutzer, Profile, Medien, Ordner, Streams,
Server, Einstellungen, Protokoll, Backups), Rollen (Superadmin/Admin/Moderator/User). Alles lokal, ohne Cloud, ohne Kosten.

## Installation (ohne Docker-Befehle)
1. Neues **öffentliches** Repository auf GitHub anlegen (z. B. `streamflix-community-store`).
2. Den **Inhalt** dieses Ordners hochladen (`umbrel-app-store.yml` muss direkt im Hauptverzeichnis des Repos liegen, daneben der Ordner `sflix-streamflix`). Am einfachsten: Auf GitHub „Add file → Upload files“, alle Dateien/Ordner per Drag & Drop hineinziehen. (Der versteckte Ordner `.github` ist optional.)
3. UmbrelOS öffnen → **App Store** → oben rechts **⋯** → **Community App Stores** → die Repository-URL einfügen (`https://github.com/DEIN-NAME/streamflix-community-store`) → hinzufügen.
4. Den neuen Store öffnen → **STREAMFLIX** → **Installieren**. Beim ersten Start baut Umbrel das Image aus dem mitgelieferten Dockerfile; auf einem Raspberry Pi kann das **mehrere Minuten** dauern. Bitte Geduld.
5. STREAMFLIX öffnen → **Administrator-Konto erstellen** (das erste Konto wird Superadmin).
6. **Admin-Center** (`/admin/`, Menü oben rechts) → **Medienordner** → **Ordner hinzufügen** → Typ wählen → speichern → Scan starten.
7. Zurück zur Startseite und Filme/Serien ansehen.

> **Hinweis zur Anmeldung:** STREAMFLIX nutzt eigene Konten. Das Umbrel-Passwort wird vor der App bewusst *nicht* abgefragt
> (`PROXY_AUTH_ADD: "false"`). Schalte die Registrierung im Admin-Center ab, wenn niemand sonst ein Konto anlegen soll.
> Setze die App nicht ohne weiteres direkt ins Internet; nutze für Fernzugriff z. B. Tailscale.

## Medien einbinden (wichtig)
Ein Container sieht nicht beliebige Ordner. STREAMFLIX bekommt von Umbrel **genau eine** Freigabe, **schreibgeschützt**:

| Auf dem Umbrel-Host | Im Container |
|---|---|
| `${UMBREL_ROOT}/data/storage` (der „Speicher“ von Umbrel, im Dateien-Bereich sichtbar) | `/media` (read-only) |

* Lege Medien über die Umbrel-Oberfläche **Dateien** (Files) im Speicher ab, z. B. `Downloads/Filme`, `Downloads/Serien`.
  Im Admin-Center trägst du dann `/media/downloads/Filme` ein (Groß-/Kleinschreibung wie im Datei-Browser; „Durchsuchen“ hilft).
* Das Admin-Panel kann **keine** neuen Host-Verzeichnisse freigeben und fasst keine Container-Konfiguration an. Alles außerhalb von `/media` wird abgelehnt.
* **Zusätzliche Festplatten:** Eine USB-Festplatte funktioniert, wenn Umbrel sie innerhalb des Speichers (`data/storage/...`) einhängt. Prüfe nach dem Anschließen in der Umbrel-Oberfläche „Dateien“, ob die Platte unter Speicher/Externer Speicher erscheint, und **starte STREAMFLIX danach neu** (Umbrel → App → Neustart), damit der neue Mount sichtbar wird. Erscheint die Platte dort nicht, kann diese App sie auf deiner Umbrel-Version nicht sehen. In dem Fall ist eine Anpassung der `volumes:` in `sflix-streamflix/docker-compose.yml` nötig (nur die Quellpfadzeile links des `:`).
* Wird ein Laufwerk getrennt, löscht der Scanner **nichts**; er meldet nur „Ordner nicht lesbar“.

### Ordnerstruktur & Metadaten
```
Filme/        Film (2020).mp4 | Film.2019.1080p.mkv
Serien/       Serienname/Season 1/Serienname.S01E01.mkv   (auch 1x01 oder „Staffel 1“)
```
* Titel und Jahr werden aus Dateinamen erkannt. Liegen **Kodi-NFO-Dateien** (`film.nfo`, `tvshow.nfo`) daneben, werden Titel, Beschreibung, Genres, Regie, Besetzung und Altersfreigabe übernommen.
* Bilder: `poster.jpg`/`folder.jpg`/`fanart.jpg` neben den Dateien werden lokal kopiert. Fehlen sie, erzeugt FFmpeg Vorschaubilder aus dem Video.
* Es werden **keine Online-Metadatenanbieter** abgefragt (läuft damit auch offline). Genres (für die Reihen Action, Horror …) kommen aus NFO-Dateien, aus Ordnernamen wie `Anime`/`Dokumentationen` oder aus der **manuellen Bearbeitung** im Admin-Center (Medien → Bearbeiten, inkl. eigener Poster).
* Untertitel: `.srt/.vtt/.ass` neben dem Video (`Film.de.srt`) sowie eingebettete Textuntertitel.
* Intro überspringen: aus Kapitelmarken („Intro“/„Opening“) oder pro Serie im Admin-Center (Intro Start/Ende) einstellbar.

## Wiedergabe & Raspberry Pi
* **Direct Play** (kein CPU-Aufwand): MP4/M4V/MOV mit H.264+AAC/MP3 sowie WebM.
* **Remux (HLS)**: z. B. MKV mit H.264 → Video wird nur umgepackt, Audio nach AAC. Sehr leicht.
* **Transkodierung** (x264, Software): HEVC, AVI/MPEG-4, 10-Bit usw. Ein Raspberry Pi schafft das je nach Modell nur in niedriger Auflösung in Echtzeit; Standard sind 720p und **1 gleichzeitige Transkodierung** (Admin → Einstellungen). Hardware-Beschleunigung ist nicht enthalten.
* Mehrere Audiospuren: Bei Wechsel wird per HLS neu gestartet. Spulen in transkodierten Streams außerhalb des bereits erzeugten Bereichs startet ebenfalls neu.
* Tipp: Bereite Medien möglichst als H.264/AAC (MP4 oder MKV) vor.

## Backups
Admin-Center → Backups: Datenbank (Benutzer, Profile, Verläufe, Metadaten, Einstellungen) + lokale Bilder als ZIP, manuell oder automatisch (Standard: täglich, 7 aufbewahrt). Wiederherstellung nur für Superadmins. Originalmedien werden nie gesichert. Die Dateien liegen auf dem Umbrel unter `app-data/sflix-streamflix/data/backups`.

## Update
1. Quellcode ändern/ersetzen und in `sflix-streamflix/umbrel-app.yml` die **`version`** erhöhen (z. B. `"1.0.1"`).
2. Auf GitHub hochladen. 3. In Umbrel erscheint das Update im App Store. Daten bleiben erhalten, Datenbank-Migrationen laufen automatisch (`app/database/migrations/NNN_*.sql`).

## Fehlerbehebung
| Problem | Lösung |
|---|---|
| Store wird nicht erkannt | `umbrel-app-store.yml` muss im Hauptverzeichnis des Repos liegen, Repo muss öffentlich sein, URL ohne `/tree/...`. |
| App wird nicht angezeigt | Der Ordner muss exakt `sflix-streamflix` heißen (App-ID = Store-ID `sflix` + `-streamflix`). |
| Installation hängt lange | Das Image wird auf dem Pi gebaut (mehrere Minuten). Siehe unten, falls Umbrel `build:` nicht unterstützt. |
| „Ordner nicht gefunden“ | Pfad muss mit `/media/` beginnen und dem Datei-Browser entsprechen (Groß/Klein). „Durchsuchen“ nutzen. |
| Nach Scan keine Titel | Typ des Ordners prüfen (Filme/Serien), Admin → Medienordner → Scan-Meldungen ansehen, Protokoll prüfen. |
| Ruckeln / Fehler bei Wiedergabe | Admin → Aktive Streams: Modus „Transcode“ ist rechenintensiv. Medien als H.264/AAC bereitstellen. |
| Stream bricht ab | „Maximale gleichzeitige Streams/Transkodierungen“ in den Einstellungen prüfen. |
| Passwort vergessen | Anderer Admin: Benutzer → Bearbeiten → Passwort zurücksetzen. |
| Wartungsmodus-Aussperrung | Nur Moderator/Admin/Superadmin können sich im Wartungsmodus anmelden. |

### Falls Umbrel `build:` in der docker-compose.yml nicht akzeptiert
Das Projekt ist so gebaut, dass **kein** vorgefertigtes Image nötig ist. Sollte deine Umbrel-Version das Bauen beim Installieren nicht unterstützen, nutze die mitgelieferte GitHub-Action:
1. Der Workflow `.github/workflows/build-image.yml` baut bei jedem Push auf `main` ein Multi-Arch-Image (arm64 + amd64) und veröffentlicht es als `ghcr.io/DEIN-NAME/streamflix:1.0.0`.
2. Mache das Paket in GitHub (Profil → Packages → streamflix → Package settings) **öffentlich**.
3. Ersetze in `sflix-streamflix/docker-compose.yml` die Zeile `build: .` durch `image: ghcr.io/DEIN-NAME/streamflix:1.0.0` (kleingeschrieben) und lade die Datei hoch.

## Entwicklung / Tests
* Struktur: `sflix-streamflix/app` (PHP 8.3, SQLite), `docker/` (nginx, php-fpm, supervisor), `Dockerfile`.
* End-to-End-Test gegen eine laufende Instanz: `python3 sflix-streamflix/app/tests/smoke_test.py http://HOST:PORT`.

Lizenz: MIT (siehe `LICENSE`). Das Branding „STREAMFLIX“ ist eigenständig; es werden keine Netflix-Marken oder -Assets verwendet.
