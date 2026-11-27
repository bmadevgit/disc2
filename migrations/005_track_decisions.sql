CREATE TABLE IF NOT EXISTS uat_track_decisions (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 case_id INTEGER NOT NULL REFERENCES cases(id) ON DELETE CASCADE,
 track_id INTEGER NOT NULL UNIQUE REFERENCES uat_tracks(id) ON DELETE CASCADE,
 route_type TEXT NOT NULL CHECK(route_type IN ('nonserious','serious')),
 report_kk_date TEXT NOT NULL DEFAULT '',
 kk_resolution_date TEXT NOT NULL DEFAULT '',
 kk_resolution TEXT NOT NULL DEFAULT '',
 commander_decision TEXT NOT NULL DEFAULT '',
 order_number TEXT NOT NULL DEFAULT '',
 order_date TEXT NOT NULL DEFAULT '',
 finished_date TEXT NOT NULL DEFAULT '',
 updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);
