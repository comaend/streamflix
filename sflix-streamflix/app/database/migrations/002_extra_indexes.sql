CREATE INDEX IF NOT EXISTS idx_wh_item ON watch_history(kind, item_id);
CREATE INDEX IF NOT EXISTS idx_ratings_profile ON ratings(profile_id);
CREATE INDEX IF NOT EXISTS idx_watchlist_profile ON watchlist(profile_id, added_at);
