<?php
// StreamFlix — Medienbibliothek-Funktionen
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

class Media {
    public static function listMovies(int $page = 1, int $limit = 20, ?string $genre = null, ?string $search = null): array {
        $db = Database::get();
        $sql = "SELECT m.*, GROUP_CONCAT(DISTINCT g.name) AS genres
                FROM movies m
                LEFT JOIN movie_genres mg ON m.id = mg.movie_id
                LEFT JOIN genres g ON mg.genre_id = g.id
                WHERE 1=1";
        $params = [];
        if ($genre) {
            $sql .= " AND g.slug = ?";
            $params[] = $genre;
        }
        if ($search) {
            $sql .= " AND m.title LIKE ?";
            $params[] = "%$search%";
        }
        $sql .= " GROUP BY m.id ORDER BY m.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = ($page - 1) * $limit;
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function countMovies(?string $genre = null, ?string $search = null): int {
        $db = Database::get();
        $sql = "SELECT COUNT(DISTINCT m.id) AS c FROM movies m
                LEFT JOIN movie_genres mg ON m.id = mg.movie_id
                LEFT JOIN genres g ON mg.genre_id = g.id
                WHERE 1=1";
        $params = [];
        if ($genre) {
            $sql .= " AND g.slug = ?";
            $params[] = $genre;
        }
        if ($search) {
            $sql .= " AND m.title LIKE ?";
            $params[] = "%$search%";
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public static function getMovie(int $id): ?array {
        $db = Database::get();
        $stmt = $db->prepare("SELECT * FROM movies WHERE id = ?");
        $stmt->execute([$id]);
        $movie = $stmt->fetch();
        if (!$movie) {
            return null;
        }
        $movie['genres'] = self::genresForMovie($id);
        $movie['media_file'] = self::mediaFile('movie', $id);
        return $movie;
    }

    public static function genresForMovie(int $movieId): array {
        $db = Database::get();
        $stmt = $db->prepare(
            "SELECT g.name, g.slug FROM genres g
             JOIN movie_genres mg ON g.id = mg.genre_id
             WHERE mg.movie_id = ?"
        );
        $stmt->execute([$movieId]);
        return $stmt->fetchAll();
    }

    public static function mediaFile(string $type, int $mediaId): ?array {
        $db = Database::get();
        $stmt = $db->prepare(
            "SELECT * FROM media_files WHERE media_type = ? AND media_id = ? LIMIT 1"
        );
        $stmt->execute([$type, $mediaId]);
        return $stmt->fetch();
    }

    public static function listSeries(int $page = 1, int $limit = 20, ?string $genre = null, ?string $search = null): array {
        $db = Database::get();
        $sql = "SELECT s.*, GROUP_CONCAT(DISTINCT g.name) AS genres
                FROM series s
                LEFT JOIN series_genres sg ON s.id = sg.series_id
                LEFT JOIN genres g ON sg.genre_id = g.id
                WHERE 1=1";
        $params = [];
        if ($genre) {
            $sql .= " AND g.slug = ?";
            $params[] = $genre;
        }
        if ($search) {
            $sql .= " AND s.title LIKE ?";
            $params[] = "%$search%";
        }
        $sql .= " GROUP BY s.id ORDER BY s.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = ($page - 1) * $limit;
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function countSeries(?string $genre = null, ?string $search = null): int {
        $db = Database::get();
        $sql = "SELECT COUNT(DISTINCT s.id) AS c FROM series s
                LEFT JOIN series_genres sg ON s.id = sg.series_id
                LEFT JOIN genres g ON sg.genre_id = g.id
                WHERE 1=1";
        $params = [];
        if ($genre) {
            $sql .= " AND g.slug = ?";
            $params[] = $genre;
        }
        if ($search) {
            $sql .= " AND s.title LIKE ?";
            $params[] = "%$search%";
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public static function getSeries(int $id): ?array {
        $db = Database::get();
        $stmt = $db->prepare("SELECT * FROM series WHERE id = ?");
        $stmt->execute([$id]);
        $series = $stmt->fetch();
        if (!$series) {
            return null;
        }
        $series['genres'] = self::genresForSeries($id);
        $series['seasons'] = self::seasonsForSeries($id);
        return $series;
    }

    public static function genresForSeries(int $seriesId): array {
        $db = Database::get();
        $stmt = $db->prepare(
            "SELECT g.name, g.slug FROM genres g
             JOIN series_genres sg ON g.id = sg.genre_id
             WHERE sg.series_id = ?"
        );
        $stmt->execute([$seriesId]);
        return $stmt->fetchAll();
    }

    public static function seasonsForSeries(int $seriesId): array {
        $db = Database::get();
        $stmt = $db->prepare(
            "SELECT * FROM seasons WHERE series_id = ? ORDER BY season_number ASC"
        );
        $stmt->execute([$seriesId]);
        $seasons = $stmt->fetchAll();
        foreach ($seasons as &$season) {
            $stmt2 = $db->prepare(
                "SELECT * FROM episodes WHERE season_id = ? ORDER BY episode_number ASC"
            );
            $stmt2->execute([$season['id']]);
            $season['episodes'] = $stmt2->fetchAll();
        }
        return $seasons;
    }

    public static function listGenres(): array {
        $db = Database::get();
        $stmt = $db->query("SELECT * FROM genres ORDER BY name ASC");
        return $stmt->fetchAll();
    }

    public static function search(string $q): array {
        $db = Database::get();
        $like = "%$q%";
        $movies = [];
        $stmt = $db->prepare("SELECT id, title, year, poster FROM movies WHERE title LIKE ? LIMIT 10");
        $stmt->execute([$like]);
        while ($row = $stmt->fetch()) {
            $row['type'] = 'movie';
            $movies[] = $row;
        }
        $series = [];
        $stmt2 = $db->prepare("SELECT id, title, year, poster FROM series WHERE title LIKE ? LIMIT 10");
        $stmt2->execute([$like]);
        while ($row = $stmt2->fetch()) {
            $row['type'] = 'series';
            $series[] = $row;
        }
        return ['movies' => $movies, 'series' => $series];
    }

    public static function stats(): array {
        $db = Database::get();
        $fetch = function (string $sql, array $params = []): int {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        };
        return [
            'movies'     => $fetch("SELECT COUNT(*) FROM movies"),
            'series'     => $fetch("SELECT COUNT(*) FROM series"),
            'episodes'   => $fetch("SELECT COUNT(*) FROM episodes"),
            'users'      => $fetch("SELECT COUNT(*) FROM users"),
            'profiles'   => $fetch("SELECT COUNT(*) FROM profiles"),
            'watchlist'  => $fetch("SELECT COUNT(*) FROM watchlist"),
            'history'    => $fetch("SELECT COUNT(*) FROM watch_history"),
            'folders'    => $fetch("SELECT COUNT(*) FROM media_folders"),
            'active'     => $fetch("SELECT COUNT(*) FROM active_streams"),
        ];
    }
}
