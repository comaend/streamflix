#!/bin/sh
# Startet als root nur, um dem Datenverzeichnis die richtigen Rechte zu geben,
# danach läuft alles als unprivilegierter Benutzer "app".
set -e
mkdir -p /data /tmp
chown -R app:app /data 2>/dev/null || true
chmod 750 /data 2>/dev/null || true
exec su-exec app "$@"
