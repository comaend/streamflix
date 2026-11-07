# StreamFlix App

Selbst gehostete Streaming-Plattform (Netflix-ähnlich) für UmbrelOS.

## Technologie

- Backend: PHP 8.2, SQLite, REST API
- Frontend: HTML5, CSS3, Vanilla JavaScript
- Streaming: FFmpeg, HTML5 Video, HLS, HTTP Range Requests
- Webserver: Nginx

## Installation

Die Installation erfolgt über den Umbrel Community App Store (siehe README im Repository-Stamm).
Das Docker-Image wird automatisch über GitHub Actions gebaut und zu GHCR gepusht.
Keine manuellen Docker-Befehle erforderlich.

## Administrator

Nach der Installation wird der erste registrierte Benutzer automatisch zum Superadmin.
Weitere Benutzer und Rollen lassen sich im Admin-Panel unter `/admin` verwalten.

## Medien

Lege Filme unter `/media/Movies` und Serien unter `/media/Series` ab (oder beliebige andere Pfade)
und binde sie schreibgeschützt in den Container ein. Verwalte die Ordner im Admin-Panel unter
**Medienordner**.
