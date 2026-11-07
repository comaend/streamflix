CREATE TABLE schema_version (version INTEGER PRIMARY KEY, applied_at TEXT DEFAULT CURRENT_TIMESTAMP);

CREATE TABLE users (
  id INTEGER PRIMARY KEY,
  username TEXT NOT NULL UNIQUE COLLATE NOCASE,
  email TEXT,
  password_hash TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'user' CHECK (role IN ('superadmin','admin','moderator','user')),
  status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','blocked')),
  must_change_password INTEGER NOT NULL DEFAULT 0,
  last_login TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE profiles (
  id INTEGER PRIMARY KEY,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  avatar TEXT NOT NULL DEFAULT 'a1',
  pin_hash TEXT,
  is_kids INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_profiles_user ON profiles(user_id);

CREATE TABLE media_folders (
  id INTEGER PRIMARY KEY,
  path TEXT NOT NULL UNIQUE,
  label TEXT,
  type TEXT NOT NULL CHECK (type IN ('movies','series')),
  auto_scan INTEGER NOT NULL DEFAULT 1,
  enabled INTEGER NOT NULL DEFAULT 1,
  last_scan TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE genres (id INTEGER PRIMARY KEY, name TEXT NOT NULL UNIQUE COLLATE NOCASE);

CREATE TABLE movies (
  id INTEGER PRIMARY KEY,
  title TEXT NOT NULL,
  overview TEXT,
  year INTEGER,
  runtime INTEGER,
  age_rating TEXT,
  director TEXT,
  cast_list TEXT,
  poster TEXT,
  backdrop TEXT,
  credits_offset INTEGER,
  folder_id INTEGER REFERENCES media_folders(id) ON DELETE SET NULL,
  added_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_movies_added ON movies(added_at);
CREATE INDEX idx_movies_year ON movies(year);
CREATE INDEX idx_movies_title ON movies(title COLLATE NOCASE);

CREATE TABLE series (
  id INTEGER PRIMARY KEY,
  title TEXT NOT NULL,
  overview TEXT,
  year INTEGER,
  age_rating TEXT,
  cast_list TEXT,
  poster TEXT,
  backdrop TEXT,
  intro_start INTEGER,
  intro_end INTEGER,
  credits_offset INTEGER NOT NULL DEFAULT 30,
  folder_id INTEGER REFERENCES media_folders(id) ON DELETE SET NULL,
  dir_key TEXT UNIQUE,
  added_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_series_added ON series(added_at);
CREATE INDEX idx_series_title ON series(title COLLATE NOCASE);

CREATE TABLE seasons (
  id INTEGER PRIMARY KEY,
  series_id INTEGER NOT NULL REFERENCES series(id) ON DELETE CASCADE,
  number INTEGER NOT NULL,
  title TEXT,
  overview TEXT,
  UNIQUE (series_id, number)
);

CREATE TABLE episodes (
  id INTEGER PRIMARY KEY,
  series_id INTEGER NOT NULL REFERENCES series(id) ON DELETE CASCADE,
  season_id INTEGER NOT NULL REFERENCES seasons(id) ON DELETE CASCADE,
  number INTEGER NOT NULL,
  title TEXT,
  overview TEXT,
  runtime INTEGER,
  still TEXT,
  added_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (season_id, number)
);
CREATE INDEX idx_episodes_series ON episodes(series_id);

CREATE TABLE media_files (
  id INTEGER PRIMARY KEY,
  path TEXT NOT NULL UNIQUE,
  movie_id INTEGER REFERENCES movies(id) ON DELETE CASCADE,
  episode_id INTEGER REFERENCES episodes(id) ON DELETE CASCADE,
  folder_id INTEGER REFERENCES media_folders(id) ON DELETE CASCADE,
  size INTEGER NOT NULL DEFAULT 0,
  mtime INTEGER NOT NULL DEFAULT 0,
  duration REAL,
  width INTEGER,
  height INTEGER,
  container TEXT,
  vcodec TEXT,
  vprofile TEXT,
  pix_fmt TEXT,
  acodec TEXT,
  bitrate INTEGER,
  audio_tracks TEXT,
  intro_start INTEGER,
  intro_end INTEGER,
  credits_start INTEGER,
  seen INTEGER NOT NULL DEFAULT 0,
  CHECK ((movie_id IS NOT NULL) + (episode_id IS NOT NULL) = 1)
);
CREATE INDEX idx_mf_movie ON media_files(movie_id);
CREATE INDEX idx_mf_episode ON media_files(episode_id);
CREATE INDEX idx_mf_folder ON media_files(folder_id);

CREATE TABLE movie_genres (
  movie_id INTEGER NOT NULL REFERENCES movies(id) ON DELETE CASCADE,
  genre_id INTEGER NOT NULL REFERENCES genres(id) ON DELETE CASCADE,
  PRIMARY KEY (movie_id, genre_id)
);
CREATE INDEX idx_mg_genre ON movie_genres(genre_id);

CREATE TABLE series_genres (
  series_id INTEGER NOT NULL REFERENCES series(id) ON DELETE CASCADE,
  genre_id INTEGER NOT NULL REFERENCES genres(id) ON DELETE CASCADE,
  PRIMARY KEY (series_id, genre_id)
);
CREATE INDEX idx_sg_genre ON series_genres(genre_id);

CREATE TABLE subtitles (
  id INTEGER PRIMARY KEY,
  media_file_id INTEGER NOT NULL REFERENCES media_files(id) ON DELETE CASCADE,
  lang TEXT,
  label TEXT,
  kind TEXT NOT NULL CHECK (kind IN ('external','embedded')),
  path TEXT,
  stream_index INTEGER
);
CREATE INDEX idx_sub_file ON subtitles(media_file_id);

CREATE TABLE watch_progress (
  profile_id INTEGER NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
  kind TEXT NOT NULL CHECK (kind IN ('movie','episode')),
  item_id INTEGER NOT NULL,
  position REAL NOT NULL DEFAULT 0,
  duration REAL NOT NULL DEFAULT 0,
  completed INTEGER NOT NULL DEFAULT 0,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (profile_id, kind, item_id)
);
CREATE INDEX idx_wp_updated ON watch_progress(profile_id, updated_at);

CREATE TABLE watch_history (
  id INTEGER PRIMARY KEY,
  profile_id INTEGER NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
  kind TEXT NOT NULL CHECK (kind IN ('movie','episode')),
  item_id INTEGER NOT NULL,
  watched_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_wh_profile ON watch_history(profile_id, watched_at);

CREATE TABLE watchlist (
  profile_id INTEGER NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
  kind TEXT NOT NULL CHECK (kind IN ('movie','series')),
  item_id INTEGER NOT NULL,
  added_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (profile_id, kind, item_id)
);

CREATE TABLE ratings (
  profile_id INTEGER NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
  kind TEXT NOT NULL CHECK (kind IN ('movie','series')),
  item_id INTEGER NOT NULL,
  rating INTEGER NOT NULL CHECK (rating BETWEEN 1 AND 5),
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (profile_id, kind, item_id)
);

CREATE TABLE user_settings (
  user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
  language TEXT NOT NULL DEFAULT 'de'
);

CREATE TABLE profile_settings (
  profile_id INTEGER PRIMARY KEY REFERENCES profiles(id) ON DELETE CASCADE,
  audio_lang TEXT NOT NULL DEFAULT '',
  subtitle_lang TEXT NOT NULL DEFAULT '',
  subtitles_on INTEGER NOT NULL DEFAULT 0,
  autoplay_next INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE sessions (
  id TEXT PRIMARY KEY,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  profile_id INTEGER,
  ip TEXT,
  user_agent TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_sessions_user ON sessions(user_id);

CREATE TABLE scan_history (
  id INTEGER PRIMARY KEY,
  folder_id INTEGER REFERENCES media_folders(id) ON DELETE SET NULL,
  folder_path TEXT,
  started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at TEXT,
  status TEXT NOT NULL DEFAULT 'running' CHECK (status IN ('running','done','error')),
  total INTEGER NOT NULL DEFAULT 0,
  processed INTEGER NOT NULL DEFAULT 0,
  added INTEGER NOT NULL DEFAULT 0,
  updated INTEGER NOT NULL DEFAULT 0,
  removed INTEGER NOT NULL DEFAULT 0,
  errors TEXT,
  heartbeat INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_scan_folder ON scan_history(folder_id, started_at);

CREATE TABLE activity_logs (
  id INTEGER PRIMARY KEY,
  user_id INTEGER,
  username TEXT,
  action TEXT NOT NULL,
  detail TEXT,
  ip TEXT,
  level TEXT NOT NULL DEFAULT 'info' CHECK (level IN ('info','warn','error')),
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_log_created ON activity_logs(created_at);
CREATE INDEX idx_log_action ON activity_logs(action);

CREATE TABLE active_streams (
  token TEXT PRIMARY KEY,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  profile_id INTEGER NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
  kind TEXT NOT NULL,
  item_id INTEGER NOT NULL,
  media_file_id INTEGER NOT NULL REFERENCES media_files(id) ON DELETE CASCADE,
  mode TEXT NOT NULL CHECK (mode IN ('direct','remux','transcode')),
  ip TEXT,
  title TEXT,
  bitrate INTEGER,
  started_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_ping INTEGER NOT NULL,
  position REAL NOT NULL DEFAULT 0,
  ffmpeg_pid INTEGER,
  killed INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX idx_as_ping ON active_streams(last_ping);

CREATE TABLE system_settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);

CREATE TABLE backups (
  id INTEGER PRIMARY KEY,
  filename TEXT NOT NULL UNIQUE,
  size INTEGER NOT NULL DEFAULT 0,
  kind TEXT NOT NULL DEFAULT 'manual' CHECK (kind IN ('manual','auto','pre-restore')),
  note TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE rate_limits (
  key TEXT PRIMARY KEY,
  count INTEGER NOT NULL,
  window_start INTEGER NOT NULL
);

INSERT INTO system_settings(key,value) VALUES
 ('platform_name','STREAMFLIX'),
 ('accent_color','#e50914'),
 ('registration_enabled','1'),
 ('max_users','0'),
 ('max_streams','4'),
 ('max_transcodes','1'),
 ('default_language','de'),
 ('default_subtitle','off'),
 ('video_quality','720'),
 ('cache_max_mb','2048'),
 ('auto_scan_enabled','1'),
 ('auto_scan_hours','6'),
 ('maintenance_mode','0'),
 ('backup_auto','1'),
 ('backup_hours','24'),
 ('backup_keep','7'),
 ('login_bg','');
