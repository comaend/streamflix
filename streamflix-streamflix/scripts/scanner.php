#!/usr/bin/env php
<?php
// StreamFlix — Medien-Scanner
// Erkennt Filme und Serien, extrahiert Metadaten via ffprobe und erstellt Poster via ffmpeg.
// Aufruf: php scripts/scanner.php --folder-id=ID --scan-id=ID
//         oder php scripts/scanner.php (alle aktiven Ordner scannen)
declare(strict_types=1);

require_once __DIR__ . '/../app/includes/config.php';
require_once __DIR__ . '/../app/includes/db.php';
require_once __DIR__ . '/../app/includes/functions.php';
require_once __DIR__ . '/../app/includes/media.php';

$folderId = 0;
$scanId   = 0;
foreach ($argv as $arg) {
    if (strpos($arg, '--folder-id=') === 0) $folderId = (int) substr($arg, 12);
    if (strpos($arg, '--scan-id=') === 0)     $scanId   = (int) substr($arg, 10);
}

$db = Database::get();
$folders = $folderId
    ? [$db->prepare("SELECT * FROM media_folders WHERE id = ?")->execute([$folderId])->fetch()]
    : $db->query("SELECT * FROM media_folders WHERE is_active = 1")->fetchAll();

if (!$folders || (is_array($folders) && !isset($folders[0]) && !$folderId)) {
    fwrite(STDERR, "Keine aktiven Medienordner gefunden.\n");
    exit(1);
}
if ($folderId && !$folders[0]) { fwrite(STDERR, "Ordner nicht gefunden.\n"); exit(1); }

$videoExts = ['mkv','mp4','webm','avi','mov','ts','m4v'];
$posterDir = APP_DATA_DIR . '/posters';
@mkdir($posterDir, 0777, true);

function probe(string $file): array {
    $out = ['duration'=>0,'width'=>null,'height'=>null,'video_codec'=>null,'audio_codec'=>null];
    $cmd = escapeshellcmd('ffprobe -v error -select_streams v:0 -show_entries stream=width,height,codec_name -show_entries format=duration -of csv=p=0 ' . escapeshellarg($file));
    $res = @shell_exec($cmd);
    if ($res) {
        $p = explode(',', trim($res));
        $out['width'] = $p[0] !== '' ? (int) $p[0] : null;
        $out['height'] = $p[1] !== '' ? (int) $p[1] : null;
        $out['video_codec'] = $p[2] !== '' ? $p[2] : null;
        $out['duration'] = $p[3] !== '' ? (float) $p[3] : 0;
    }
    $cmd2 = escapeshellcmd('ffprobe -v error -select_streams a:0 -show_entries stream=codec_name -of csv=p=0 ' . escapeshellarg($file));
    $res2 = @shell_exec($cmd2);
    if ($res2) {
        $p2 = explode(',', trim($res2));
        $out['audio_codec'] = $p2[0] !== '' ? $p2[0] : null;
    }
    return $out;
}

function extractPoster(string $file, string $dest): bool {
    $cmd = escapeshellcmd('ffmpeg -y -v error -ss 5 -i ' . escapeshellarg($file) . ' -frames:v 1 -q:v 2 ' . escapeshellarg($dest) . ' 2>/dev/null');
    @shell_exec($cmd);
    return file_exists($dest);
}

function safeTitle(string $name): string {
    return preg_replace('/[^a-z0-9_-]/i', '_', $name);
}

foreach ($folders as $folder) {
    $root = $folder['path'];
    $type = $folder['type'];
    if (!is_dir($root)) {
        fwrite(STDERR, "Ordner nicht lesbar: $root\n");
        continue;
    }
    $localScanId = 0;
    if ($folderId) {
        $db->prepare("INSERT INTO scan_history (media_folder_id, status, started_at) VALUES (?, 'running', datetime('now'))")
           ->execute([$folder['id']]);
        $localScanId = (int) $db->lastInsertId();
    }

    $filesFound = 0; $moviesAdded = 0; $seriesAdded = 0;
    $videoFiles = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $ext = strtolower(pathinfo($f->getFilename(), PATHINFO_EXTENSION));
        if (!in_array($ext, $videoExts)) continue;
        $videoFiles[] = $f->getPathname();
    }

    // Serien erkennen: Verzeichnisstruktur <Show>/Staffel N/ oder Season N/ mit SxxEyy
    $seriesMap = [];
    foreach ($videoFiles as $file) {
        $rel = strpos($file, $root) === 0 ? substr($file, strlen($root) + 1) : $file;
        $parts = explode(DIRECTORY_SEPARATOR, $rel);
        $fileName = pathinfo($file, PATHINFO_FILENAME);
        $meta = probe($file);
        $filesFound++;

        $seasonNum = null; $epNum = null;
        if (preg_match('/S(\d+)[.\s_-]*E(\d+)/i', $fileName, $m)) { $seasonNum = (int)$m[1]; $epNum = (int)$m[2]; }
        elseif (preg_match('/(\d+)[xX](\d+)/', $fileName, $m)) { $seasonNum = (int)$m[1]; $epNum = (int)$m[2]; }

        if ($seasonNum !== null && $epNum !== null && count($parts) > 1) {
            $showName = $parts[0];
            if (!isset($seriesMap[$showName])) {
                $seriesMap[$showName] = ['id' => null, 'seasons' => []];
                $existing = $db->prepare("SELECT id FROM series WHERE title = ?")->execute([$showName])->fetch();
                if ($existing) { $seriesMap[$showName]['id'] = (int) $existing['id']; }
                else {
                    $poster = $posterDir . '/' . safeTitle($showName) . '.jpg';
                    extractPoster($file, $poster);
                    $db->prepare("INSERT INTO series (title, description, year, poster, created_at, updated_at) VALUES (?, '', NULL, ?, datetime('now'), datetime('now'))")
                       ->execute([$showName, $poster]);
                    $seriesMap[$showName]['id'] = (int) $db->lastInsertId();
                    $seriesAdded++;
                }
            }
            $sid = $seriesMap[$showName]['id'];
            if (!isset($seriesMap[$showName]['seasons'][$seasonNum])) {
                $existing = $db->prepare("SELECT id FROM seasons WHERE series_id = ? AND season_number = ?")->execute([$sid, $seasonNum])->fetch();
                if ($existing) { $seriesMap[$showName]['seasons'][$seasonNum] = (int) $existing['id']; }
                else {
                    $db->prepare("INSERT INTO seasons (series_id, season_number, title, created_at) VALUES (?, ?, ?, datetime('now'))")
                       ->execute([$sid, $seasonNum, 'Staffel ' . $seasonNum]);
                    $seriesMap[$showName]['seasons'][$seasonNum] = (int) $db->lastInsertId();
                }
            }
            $seasonId = $seriesMap[$showName]['seasons'][$seasonNum];
            $existingEp = $db->prepare("SELECT id FROM episodes WHERE season_id = ? AND episode_number = ?")->execute([$seasonId, $epNum])->fetch();
            if (!$existingEp) {
                $poster = $posterDir . '/' . safeTitle($showName) . '_s' . $seasonNum . 'e' . $epNum . '.jpg';
                extractPoster($file, $poster);
                $runtime = $meta['duration'] ? (int) round($meta['duration'] / 60) : null;
                $db->prepare("INSERT INTO episodes (season_id, episode_number, title, description, runtime, file_path, subtitle_path, created_at)
                             VALUES (?, ?, ?, NULL, ?, ?, NULL, datetime('now'))")
                   ->execute([$seasonId, $epNum, 'Episode ' . $epNum, '', $runtime, $file]);
                $epId = (int) $db->lastInsertId();
                $db->prepare("INSERT INTO media_files (media_type, media_id, file_path, file_size, duration, width, height, video_codec, audio_codec, created_at)
                             VALUES ('episode', ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))")
                   ->execute([$epId, $file, (int) filesize($file), $meta['duration'] ? (int) $meta['duration'] : null, $meta['width'], $meta['height'], $meta['video_codec'], $meta['audio_codec']]);
            }
        } elseif ($type === 'movies' || $type === 'mixed' || count($parts) === 1) {
            $title = ucfirst(str_replace(['_', '.'], ' ', $fileName));
            $existing = $db->prepare("SELECT id FROM movies WHERE title = ?")->execute([$title])->fetch();
            if (!$existing) {
                $poster = $posterDir . '/' . safeTitle($title) . '.jpg';
                extractPoster($file, $poster);
                $runtime = $meta['duration'] ? (int) round($meta['duration'] / 60) : null;
                $db->prepare("INSERT INTO movies (title, description, year, runtime, poster, backdrop, rating, created_at, updated_at)
                             VALUES (?, '', NULL, ?, ?, NULL, 0, datetime('now'), datetime('now'))")
                   ->execute([$title, $runtime, $poster]);
                $mid = (int) $db->lastInsertId();
                $db->prepare("INSERT INTO media_files (media_type, media_id, file_path, file_size, duration, width, height, video_codec, audio_codec, created_at)
                             VALUES ('movie', ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))")
                   ->execute([$mid, $file, (int) filesize($file), $meta['duration'] ? (int) $meta['duration'] : null, $meta['width'], $meta['height'], $meta['video_codec'], $meta['audio_codec']]);
                $moviesAdded++;
            }
        }
    }

    if ($folderId) {
        $db->prepare("UPDATE scan_history SET status = 'success', files_found = ?, movies_added = ?, series_added = ?, finished_at = datetime('now') WHERE id = ?")
           ->execute([$filesFound, $moviesAdded, $seriesAdded, $localScanId]);
        $db->prepare("UPDATE media_folders SET last_scan = datetime('now') WHERE id = ?")->execute([$folder['id']]);
        fwrite(STDOUT, "Ordner '{$folder['name]}': $filesFound Dateien, $moviesAdded Filme, $seriesAdded Serien\n");
    } else {
        fwrite(STDOUT, "Ordner '{$folder['name]}': $filesFound Dateien, $moviesAdded Filme, $seriesAdded Serien\n");
    }
}
fwrite(STDOUT, "Scan abgeschlossen.\n");
