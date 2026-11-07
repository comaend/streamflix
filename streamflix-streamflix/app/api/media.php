<?php
// StreamFlix — API: Medienbibliothek
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/media.php';
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'movies':
        $page  = max(1, (int) ($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int) ($_GET['limit'] ?? 20)));
        $genre = $_GET['genre'] ?? null;
        $search = $_GET['search'] ?? null;
        $items = Media::listMovies($page, $limit, $genre, $search);
        $total = Media::countMovies($genre, $search);
        jsonResponse(['items' => $items, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $limit))]);
        break;

    case 'series':
        $page  = max(1, (int) ($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int) ($_GET['limit'] ?? 20)));
        $genre = $_GET['genre'] ?? null;
        $search = $_GET['search'] ?? null;
        $items = Media::listSeries($page, $limit, $genre, $search);
        $total = Media::countSeries($genre, $search);
        jsonResponse(['items' => $items, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $limit))]);
        break;

    case 'movie':
        apiRequireAuth();
        $id = (int) ($_GET['id'] ?? 0);
        $movie = Media::getMovie($id);
        if (!$movie) {
            jsonResponse(['error' => 'Film nicht gefunden'], 404);
        }
        jsonResponse($movie);
        break;

    case 'series_detail':
        apiRequireAuth();
        $id = (int) ($_GET['id'] ?? 0);
        $series = Media::getSeries($id);
        if (!$series) {
            jsonResponse(['error' => 'Serie nicht gefunden'], 404);
        }
        jsonResponse($series);
        break;

    case 'genres':
        jsonResponse(['genres' => Media::listGenres()]);
        break;

    case 'search':
        $q = $_GET['q'] ?? '';
        jsonResponse(['results' => Media::search($q)]);
        break;

    default:
        jsonResponse(['error' => 'Unbekannte Aktion'], 404);
}
