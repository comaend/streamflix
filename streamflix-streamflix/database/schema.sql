-- StreamFlix — SQLite Datenbankschema
-- Alle Tabellen, Indizes und Fremdschlüssel für die Streaming-Plattform.

PRAGMA foreign_keys = ON;

-- ---------------------------------------------------------------------------
-- Benutzer
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    username        TEXT NOT NULL UNIQUE,
    email           TEXT NOT NULL UNIQUE,
    password_hash   TEXT NOT NULL,
    role            TEXT NOT NULL DEFAULT 'user' CHECK (role IN ('superadmin','admin','moderator','user')),
    is_active       INTEGER NOT NULL DEFAULT 1,
    last_login      TEXT,
    created_at      TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_users_username ON users(username);
CREATE INDEX IF NOT EXISTS idx_users_email    ON users(email);
CREATE INDEX IF NOT EXISTS idx_users_role     ON users(role);

-- ---------------------------------------------------------------------------
-- Profile (mehrere pro Benutzer)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS profiles (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id             INTEGER NOT NULL,
    name                TEXT NOT NULL,
    avatar              TEXT,
    pin                 TEXT,
    language            TEXT NOT NULL DEFAULT 'de',
    subtitle_settings   TEXT NOT NULL DEFAULT '{}', -- JSON
    created_at          TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_profiles_user   ON profiles(user_id);
CREATE INDEX IF NOT EXISTS idx_profiles_name   ON profiles(name);

-- ---------------------------------------------------------------------------
-- Genres
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS genres (
    id    INTEGER PRIMARY KEY AUTOINCREMENT,
    name  TEXT NOT NULL UNIQUE,
    slug  TEXT NOT NULL UNIQUE
);

CREATE INDEX IF NOT EXISTS idx_genres_slug ON genres(slug);

-- ---------------------------------------------------------------------------
-- Filme
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS movies (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    title          TEXT NOT NULL,
    original_title TEXT,
    description    TEXT,
    year           INTEGER,
    release_date   TEXT,
    runtime        INTEGER,          -- Minuten
    rating         REAL DEFAULT 0,   -- IMDb/TMDB Bewertung
    poster         TEXT,             -- Pfad zum Poster
    backdrop       TEXT,             -- Pfad zum Hintergrundbild
    imdb_id        TEXT,
    tmdb_id        TEXT,
    created_at     TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at     TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_movies_title   ON movies(title);
CREATE INDEX IF NOT EXISTS idx_movies_year    ON movies(year);
CREATE INDEX IF NOT EXISTS idx_movies_rating  ON movies(rating);
CREATE INDEX IF NOT EXISTS idx_movies_created ON movies(created_at);

-- ---------------------------------------------------------------------------
-- Serien
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS series (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    title          TEXT NOT NULL,
    original_title TEXT,
    description    TEXT,
    year           INTEGER,
    poster         TEXT,
    backdrop       TEXT,
    imdb_id        TEXT,
    tmdb_id        TEXT,
    created_at     TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at     TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_series_title   ON series(title);
CREATE INDEX IF NOT EXISTS idx_series_year    ON series(year);
CREATE INDEX IF NOT EXISTS idx_series_created ON series(created_at);

-- ---------------------------------------------------------------------------
-- Staffeln
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS seasons (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    series_id     INTEGER NOT NULL,
    season_number INTEGER NOT NULL,
    title         TEXT,
    description   TEXT,
    poster        TEXT,
    created_at    TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE,
    UNIQUE (series_id, season_number)
);

CREATE INDEX IF NOT EXISTS idx_seasons_series ON seasons(series_id);

-- ---------------------------------------------------------------------------
-- Episoden
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS episodes (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    season_id      INTEGER NOT NULL,
    episode_number INTEGER NOT NULL,
    title          TEXT,
    description    TEXT,
    runtime        INTEGER,
    file_path      TEXT NOT NULL,
    subtitle_path  TEXT,
    created_at     TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (season_id) REFERENCES seasons(id) ON DELETE CASCADE,
    UNIQUE (season_id, episode_number)
);

CREATE INDEX IF NOT EXISTS idx_episodes_season ON episodes(season_id);

-- ---------------------------------------------------------------------------
-- Mediendateien (Metadaten zu den Originaldateien)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS media_files (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    media_type    TEXT NOT NULL CHECK (media_type IN ('movie','series','episode')),
    media_id      INTEGER NOT NULL,
    file_path     TEXT NOT NULL,
    file_size     INTEGER,        -- Bytes
    duration      INTEGER,        -- Sekunden
    width         INTEGER,
    height        INTEGER,
    video_codec   TEXT,
    audio_codec   TEXT,
    created_at    TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_media_files_type_id ON media_files(media_type, media_id);

-- ---------------------------------------------------------------------------
-- Film-Genres (n:m)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS movie_genres (
    movie_id INTEGER NOT NULL,
    genre_id INTEGER NOT NULL,
    PRIMARY KEY (movie_id, genre_id),
    FOREIGN KEY (movie_id) REFERENCES movies(id) ON DELETE CASCADE,
    FOREIGN KEY (genre_id) REFERENCES genres(id) ON DELETE CASCADE
);

-- ---------------------------------------------------------------------------
-- Serien-Genres (n:m)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS series_genres (
    series_id INTEGER NOT NULL,
    genre_id  INTEGER NOT NULL,
    PRIMARY KEY (series_id, genre_id),
    FOREIGN KEY (series_id) REFERENCES series(id) ON DELETE CASCADE,
    FOREIGN KEY (genre_id) REFERENCES genres(id) ON DELETE CASCADE
);

-- ---------------------------------------------------------------------------
-- Untertitel
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS subtitles (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    media_type   TEXT NOT NULL CHECK (media_type IN ('movie','episode')),
    media_id     INTEGER NOT NULL,
    language     TEXT NOT NULL,
    file_path    TEXT NOT NULL,
    created_at   TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_subtitles_media ON subtitles(media_type, media_id);

-- ---------------------------------------------------------------------------
-- Wiedergabeverlauf
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS watch_history (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL,
    profile_id   INTEGER NOT NULL,
    media_type   TEXT NOT NULL CHECK (media_type IN ('movie','series','episode')),
    media_id     INTEGER NOT NULL,
    position     INTEGER NOT NULL DEFAULT 0,
    duration     INTEGER NOT NULL DEFAULT 0,
    watched_at   TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
    FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_watch_history_profile ON watch_history(profile_id, watched_at);

-- ---------------------------------------------------------------------------
-- Wiedergabefortschritt (Fortsetzen)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS watch_progress (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL,
    profile_id   INTEGER NOT NULL,
    media_type   TEXT NOT NULL CHECK (media_type IN ('movie','series','episode')),
    media_id     INTEGER NOT NULL,
    position     INTEGER NOT NULL DEFAULT 0,
    duration     INTEGER NOT NULL DEFAULT 0,
    updated_at   TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
    FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE CASCADE,
    UNIQUE (user_id, profile_id, media_type, media_id)
);

CREATE INDEX IF NOT EXISTS idx_watch_progress_profile ON watch_progress(profile_id);

-- ---------------------------------------------------------------------------
-- Meine Liste (Watchlist)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS watchlist (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL,
    profile_id   INTEGER NOT NULL,
    media_type   TEXT NOT NULL CHECK (media_type IN ('movie','series','episode')),
    media_id     INTEGER NOT NULL,
    added_at     TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
    FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE CASCADE,
    UNIQUE (user_id, profile_id, media_type, media_id)
);

CREATE INDEX IF NOT EXISTS idx_watchlist_profile ON watchlist(profile_id);

-- ---------------------------------------------------------------------------
-- Bewertungen
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ratings (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL,
    profile_id   INTEGER NOT NULL,
    media_type   TEXT NOT NULL CHECK (media_type IN ('movie','series','episode')),
    media_id     INTEGER NOT NULL,
    rating       INTEGER NOT NULL CHECK (rating BETWEEN 1 AND 5),
    created_at   TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
    FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE CASCADE,
    UNIQUE (user_id, profile_id, media_type, media_id)
);

CREATE INDEX IF NOT EXISTS idx_ratings_profile ON ratings(profile_id);

-- ---------------------------------------------------------------------------
-- Benutzereinstellungen
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_settings (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL UNIQUE,
    key     TEXT NOT NULL,
    value   TEXT,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ---------------------------------------------------------------------------
# Profileinstellungen
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS profile_settings (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    profile_id  INTEGER NOT NULL UNIQUE,
    key         TEXT NOT NULL,
    value       TEXT,
    FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE CASCADE
);

-- ---------------------------------------------------------------------------
-- Sessions
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sessions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INTEGER NOT NULL,
    token        TEXT NOT NULL UNIQUE,
    expires_at   TEXT NOT NULL,
    created_at   TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_sessions_token ON sessions(token);
CREATE INDEX IF NOT EXISTS idx_sessions_expires ON sessions(expires_at);

-- ---------------------------------------------------------------------------
-- Medienordner
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS media_folders (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT NOT NULL,
    path        TEXT NOT NULL,
    type        TEXT NOT NULL CHECK (type IN ('movies','series','mixed')),
    is_active   INTEGER NOT NULL DEFAULT 1,
    last_scan   TEXT,
    created_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_media_folders_path ON media_folders(path);

-- ---------------------------------------------------------------------------
-- Scan-Historie
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS scan_history (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    media_folder_id INTEGER NOT NULL,
    status         TEXT NOT NULL DEFAULT 'running', -- running, success, error
    files_found    INTEGER NOT NULL DEFAULT 0,
    movies_added   INTEGER NOT NULL DEFAULT 0,
    series_added   INTEGER NOT NULL DEFAULT 0,
    started_at     TEXT NOT NULL DEFAULT (datetime('now')),
    finished_at    TEXT,
    error          TEXT,
    FOREIGN KEY (media_folder_id) REFERENCES media_folders(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_scan_history_folder ON scan_history(media_folder_id);

-- ---------------------------------------------------------------------------
-- Aktivitätsprotokoll
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_logs (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER,
    action      TEXT NOT NULL,
    entity_type TEXT,
    entity_id   INTEGER,
    details     TEXT,
    ip          TEXT,
    created_at  TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_activity_logs_user   ON activity_logs(user_id);
CREATE INDEX IF NOT EXISTS idx_activity_logs_action ON activity_logs(action);
CREATE INDEX IF NOT EXISTS idx_activity_logs_created ON activity_logs(created_at);

-- ---------------------------------------------------------------------------
-- Aktive Streams
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS active_streams (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    profile_id  INTEGER NOT NULL,
    media_type  TEXT NOT NULL CHECK (media_type IN ('movie','series','episode')),
    media_id    INTEGER NOT NULL,
    start_time  TEXT NOT NULL DEFAULT (datetime('now')),
    position    INTEGER NOT NULL DEFAULT 0,
    mode        TEXT NOT NULL DEFAULT 'direct', -- direct, hls, transcode
    ip          TEXT,
    bitrate     INTEGER,
    FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE,
    FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_active_streams_user ON active_streams(user_id);

-- ---------------------------------------------------------------------------
-- Systemeinstellungen
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS system_settings (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    key     TEXT NOT NULL UNIQUE,
    value   TEXT
);

-- ---------------------------------------------------------------------------
-- Backups
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS backups (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT NOT NULL,
    type        TEXT NOT NULL DEFAULT 'database',
    file_path   TEXT NOT NULL,
    size        INTEGER NOT NULL DEFAULT 0,
    created_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ---------------------------------------------------------------------------
-- Standardgenres einfügen
-- ---------------------------------------------------------------------------
INSERT OR IGNORE INTO genres (name, slug) VALUES
    ('Action', 'action'),
    ('Abenteuer', 'abenteuer'),
    ('Science-Fiction', 'science-fiction'),
    ('Horror', 'horror'),
    ('Animation', 'animation'),
    ('Dokumentation', 'dokumentation'),
    ('Komödie', 'komödie'),
    ('Drama', 'drama'),
    ('Thriller', 'thriller'),
    ('Krimi', 'krimi');

-- ---------------------------------------------------------------------------
-- Standardeinstellungen
-- ---------------------------------------------------------------------------
INSERT OR IGNORE INTO system_settings (key, value) VALUES
    ('platform_name', 'StreamFlix'),
    ('registration_enabled', '1'),
    ('max_users', '50'),
    ('max_concurrent_streams', '5'),
    ('default_language', 'de'),
    ('default_subtitle', 'de'),
    ('video_quality', 'auto'),
    ('cache_size', '512'),
    ('auto_scan', '1'),
    ('maintenance_mode', '0');
