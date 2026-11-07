<?php
// StreamFlix — Video-Streaming (Range-Requests, Direktwiedergabe, HLS)
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

class Streaming {
    private const CHUNK = 8192;

    public static function serveFile(string $filePath, string $mimeType = 'video/mp4'): void {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            http_response_code(404);
            exit;
        }
        $size = (int) filesize($filePath);
        $range = $_SERVER['HTTP_RANGE'] ?? '';

        header('Accept-Ranges: bytes');
        header('Content-Type: ' . $mimeType);
        header('Cache-Control: public, max-age=86400');

        if ($range === '') {
            header('Content-Length: ' . $size);
            readfile($filePath);
            exit;
        }

        if (!preg_match('/bytes=(\d+)-(\d*)/', $range, $matches)) {
            http_response_code(400);
            exit;
        }
        $start = (int) $matches[1];
        $end = $matches[2] !== '' ? (int) $matches[2] : $size - 1;
        if ($start >= $size) {
            header('Content-Range: bytes */' . $size);
            http_response_code(416);
            exit;
        }
        $end = min($end, $size - 1);
        $length = $end - $start + 1;

        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        header('Content-Length: ' . $length);
        http_response_code(206);

        $fp = fopen($filePath, 'rb');
        fseek($fp, $start);
        $remaining = $length;
        while ($remaining > 0 && !feof($fp)) {
            $read = (int) min($remaining, self::CHUNK);
            echo fread($fp, $read);
            $remaining -= $read;
        }
        fclose($fp);
        exit;
    }

    public static function hlsPlaylist(string $filePath): void {
        // Einfache HLS-Playlist (ohne Transkodierung, direkte Segmentierung durch Client nicht nötig).
        // Wir liefern eine minimale M3U8, die auf die Direktwiedergabe verweist, damit
        // Player die Datei als HLS behandeln können. Falls echte Transkodierung gewünscht,
        // muss FFmpeg separat aufgerufen werden (siehe Admin/Einstellungen).
        header('Content-Type: application/vnd.apple.mpegurl');
        header('Cache-Control: no-store');
        $base = rtrim($_SERVER['REQUEST_URI'] ?? '', 'hls.m3u8');
        echo "#EXTM3U\n";
        echo "#EXT-X-VERSION:3\n";
        echo "#EXT-X-TARGETDURATION:6\n";
        echo "#EXT-X-MEDIA-SEQUENCE:0\n";
        echo "#EXTINF:6.000,\n";
        echo "$base" . "stream.php?" . http_build_query([
            'file' => urlencode($filePath),
        ]) . "\n";
        echo "#EXT-X-ENDLIST\n";
        exit;
    }
}
