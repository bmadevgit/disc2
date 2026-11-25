CREATE TABLE uat_tracks_new (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 case_id INTEGER NOT NULL REFERENCES cases(id) ON DELETE CASCADE,
 track_type TEXT NOT NULL CHECK(track_type IN ('fact','discipline_nonserious','discipline_serious')),
 acknowledged_date TEXT NOT NULL DEFAULT '', first_meeting_date TEXT NOT NULL DEFAULT '',
 start_date TEXT NOT NULL DEFAULT '', duration_days INTEGER NOT NULL DEFAULT 0,
 due_date TEXT NOT NULL DEFAULT '', start_override_reason TEXT NOT NULL DEFAULT '',
 created_at TEXT DEFAULT CURRENT_TIMESTAMP,
 process_mode TEXT NOT NULL DEFAULT 'committee', process_other TEXT NOT NULL DEFAULT '',
 set_no INTEGER NOT NULL DEFAULT 1 CHECK(set_no >= 1 AND (track_type='discipline_serious' OR set_no=1)),
 UNIQUE(case_id,track_type,set_no)
);
INSERT INTO uat_tracks_new SELECT uat_tracks.*,1 FROM uat_tracks;
-- Copy the historical high-water mark even if the highest issued ID was deleted.
UPDATE sqlite_sequence SET seq=(SELECT MAX(seq) FROM sqlite_sequence WHERE name IN ('uat_tracks','uat_tracks_new'))
 WHERE name='uat_tracks_new';
DROP TABLE uat_tracks;
ALTER TABLE uat_tracks_new RENAME TO uat_tracks;

ALTER TABLE uat_track_subjects ADD COLUMN ended_at TEXT;
ALTER TABLE uat_subject_findings ADD COLUMN committee_opinion TEXT NOT NULL DEFAULT '';
ALTER TABLE uat_subject_findings ADD COLUMN appointing_authority_opinion TEXT NOT NULL DEFAULT '';
ALTER TABLE uat_subject_findings ADD COLUMN allegation TEXT NOT NULL DEFAULT '';
ALTER TABLE uat_subject_findings ADD COLUMN recommend_serious INTEGER NOT NULL DEFAULT 0 CHECK(recommend_serious IN (0,1));

CREATE TABLE uat_decisions_new (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 case_id INTEGER NOT NULL REFERENCES cases(id) ON DELETE CASCADE,
 subject_id INTEGER NOT NULL REFERENCES uat_subjects(id) ON DELETE CASCADE,
 route_type TEXT NOT NULL CHECK(route_type IN ('nonserious','serious')),
 report_kk_date TEXT NOT NULL DEFAULT '', kk_resolution_date TEXT NOT NULL DEFAULT '',
 kk_resolution TEXT NOT NULL DEFAULT '', commander_decision TEXT NOT NULL DEFAULT '',
 order_number TEXT NOT NULL DEFAULT '', order_date TEXT NOT NULL DEFAULT '', finished_date TEXT NOT NULL DEFAULT '',
 decision_path TEXT NOT NULL DEFAULT 'order_issued', termination_reason TEXT NOT NULL DEFAULT '',
 proposal_letter_number TEXT NOT NULL DEFAULT '', other_outcome TEXT NOT NULL DEFAULT '', other_note TEXT NOT NULL DEFAULT '',
 track_id INTEGER REFERENCES uat_tracks(id) ON DELETE CASCADE,
 UNIQUE(track_id,subject_id)
);
INSERT INTO uat_decisions_new SELECT d.*, (
 SELECT t.id FROM uat_tracks t WHERE t.case_id=d.case_id AND t.track_type='discipline_' || d.route_type
) FROM uat_decisions d;
-- Copy the historical high-water mark even if the highest issued ID was deleted.
UPDATE sqlite_sequence SET seq=(SELECT MAX(seq) FROM sqlite_sequence WHERE name IN ('uat_decisions','uat_decisions_new'))
 WHERE name='uat_decisions_new';
DROP TABLE uat_decisions;
ALTER TABLE uat_decisions_new RENAME TO uat_decisions;

CREATE TABLE uat_escalations (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 subject_id INTEGER NOT NULL REFERENCES uat_subjects(id) ON DELETE CASCADE,
 from_track_id INTEGER NOT NULL REFERENCES uat_tracks(id),
 to_track_id INTEGER NOT NULL REFERENCES uat_tracks(id),
 effective_date TEXT NOT NULL, reference TEXT NOT NULL,
 snapshot TEXT NOT NULL,
 actor_id TEXT NOT NULL, actor_name TEXT NOT NULL,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(subject_id,from_track_id)
);
