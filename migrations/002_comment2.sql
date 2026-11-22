ALTER TABLE uat_case_meta ADD COLUMN case_type TEXT NOT NULL DEFAULT '';
ALTER TABLE uat_case_meta ADD COLUMN case_type_other TEXT NOT NULL DEFAULT '';
ALTER TABLE uat_case_meta ADD COLUMN is_test INTEGER NOT NULL DEFAULT 0;
ALTER TABLE uat_case_meta ADD COLUMN test_scenario TEXT NOT NULL DEFAULT '';

ALTER TABLE uat_subjects ADD COLUMN reference_code TEXT NOT NULL DEFAULT '';
ALTER TABLE uat_subjects ADD COLUMN is_unknown INTEGER NOT NULL DEFAULT 0;
ALTER TABLE uat_subjects ADD COLUMN level_name TEXT NOT NULL DEFAULT '';
CREATE UNIQUE INDEX IF NOT EXISTS idx_uat_subject_reference ON uat_subjects(case_id, reference_code) WHERE reference_code<>'';

ALTER TABLE uat_tracks ADD COLUMN process_mode TEXT NOT NULL DEFAULT 'committee';
ALTER TABLE uat_tracks ADD COLUMN process_other TEXT NOT NULL DEFAULT '';
ALTER TABLE uat_subject_findings ADD COLUMN action_type TEXT NOT NULL DEFAULT '';
ALTER TABLE uat_subject_findings ADD COLUMN offense_basis TEXT NOT NULL DEFAULT '';

CREATE TABLE IF NOT EXISTS uat_committee_sets (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 track_id INTEGER NOT NULL REFERENCES uat_tracks(id) ON DELETE CASCADE,
 set_code TEXT NOT NULL,
 active INTEGER NOT NULL DEFAULT 1,
 order_date TEXT NOT NULL DEFAULT '', order_number TEXT NOT NULL DEFAULT '',
 acknowledged_date TEXT NOT NULL DEFAULT '', first_meeting_date TEXT NOT NULL DEFAULT '',
 start_date TEXT NOT NULL DEFAULT '', duration_days INTEGER NOT NULL DEFAULT 0, due_date TEXT NOT NULL DEFAULT '',
 created_at TEXT DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(track_id,set_code)
);
CREATE TABLE IF NOT EXISTS uat_committee_set_subjects (
 committee_set_id INTEGER NOT NULL REFERENCES uat_committee_sets(id) ON DELETE CASCADE,
 subject_id INTEGER NOT NULL REFERENCES uat_subjects(id) ON DELETE CASCADE,
 ended_at TEXT,
 PRIMARY KEY(committee_set_id,subject_id)
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_uat_one_active_committee_member ON uat_committee_set_subjects(subject_id) WHERE ended_at IS NULL;

CREATE TABLE IF NOT EXISTS uat_referrals (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 track_id INTEGER NOT NULL REFERENCES uat_tracks(id) ON DELETE CASCADE,
 subject_id INTEGER NOT NULL REFERENCES uat_subjects(id) ON DELETE CASCADE,
 ordered_date TEXT NOT NULL DEFAULT '', received_date TEXT NOT NULL DEFAULT '', due_date TEXT NOT NULL DEFAULT '',
 status TEXT NOT NULL DEFAULT 'pending', finished_date TEXT NOT NULL DEFAULT '', note TEXT NOT NULL DEFAULT '',
 UNIQUE(track_id,subject_id)
);

ALTER TABLE uat_decisions ADD COLUMN decision_path TEXT NOT NULL DEFAULT 'order_issued';
ALTER TABLE uat_decisions ADD COLUMN termination_reason TEXT NOT NULL DEFAULT '';
ALTER TABLE uat_decisions ADD COLUMN proposal_letter_number TEXT NOT NULL DEFAULT '';
ALTER TABLE uat_decisions ADD COLUMN other_outcome TEXT NOT NULL DEFAULT '';
ALTER TABLE uat_decisions ADD COLUMN other_note TEXT NOT NULL DEFAULT '';
CREATE TABLE IF NOT EXISTS uat_kk_reports (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 decision_id INTEGER NOT NULL REFERENCES uat_decisions(id) ON DELETE CASCADE,
 sequence_no INTEGER NOT NULL,
 report_date TEXT NOT NULL DEFAULT '', letter_number TEXT NOT NULL DEFAULT '',
 status TEXT NOT NULL DEFAULT 'waiting_resolution', resolution_date TEXT NOT NULL DEFAULT '',
 resolution_text TEXT NOT NULL DEFAULT '', resolution_action TEXT NOT NULL DEFAULT '', note TEXT NOT NULL DEFAULT '',
 created_at TEXT DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(decision_id,sequence_no)
);

UPDATE uat_subjects SET reference_code='REF-' || case_id || '-' || sequence_no WHERE reference_code='';
UPDATE uat_case_meta SET is_test=0 WHERE is_test IS NULL;
