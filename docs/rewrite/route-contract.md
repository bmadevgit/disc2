# Procedural route and form contract

Paths are relative to the application mount (including `/exam/disc2` on IIS). Redirects use HTTP 302 and preserve the mount. Methods below are the methods used by links and forms. Most legacy read pages have no explicit method guard. Authenticated means a session with a user ID. A protected request without a session redirects to `login.php`. A guarded POST with an invalid or absent session CSRF token returns 419. Route names identify the intended Symfony equivalents.

| Path | Class | Method | Parameters | Response | Access | Replacement |
| --- | --- | --- | --- | --- | --- | --- |
| `/` | page | GET | Same filters as index.php | Same as index.php | Authenticated | case_index |
| `/admin.php` | page | GET | None | 200 dashboard or 302 index.php | Admin, executive or unit head | dashboard |
| `/ai_dashboard.php` | JSON endpoint | POST (GET also reaches handler) | csrf on POST | 200 JSON ok/summary, 503 JSON ok/message in dev or on gateway failure, 403 for non-admins | Admin | dashboard_ai |
| `/auth.php` | internal include | Internal | Session | Session, login, CSRF, identity and scope helpers | Internal | No public route |
| `/case.php` | page | GET | id, page (clamped 1–4), saved | 200 HTML, 403 invalid ID/scope, 404 absent authorized case | Case reader | case_detail |
| `/case_comment2.php` | internal include | Internal | id, page, host view context | Older alternate tab template, no active caller | Internal | No public route |
| `/case_discipline.php` | internal include | Internal | Case context | Case tab 3 | Internal | No public route |
| `/case_listing.php` | internal include | Internal | Index filters and access context | Scoped list and filter options | Internal | No public route |
| `/case_page4.php` | internal include | Internal | Case context | Case tab 4 | Internal | No public route |
| `/db.php` | internal include | Internal | App configuration | Opens PDO connection | Internal | No public route |
| `/discipline_results.php` | page | GET | case_id, type, track_id (defaults to set 1), saved | 200 results, 403 invalid type/scope, 404 missing track | Case reader | discipline_results |
| `/edit.php` | redirect alias | GET | id cast to integer | 302 case.php?id={id} | Authenticated | case_edit_alias |
| `/i.php` | redirect alias | GET | None | 302 index.php | Public | index_alias |
| `/index.php` | page | GET | q, unit, type, stage, finding | 200 scoped list or unassigned notice, 302 admin.php for executive/unit head | Authenticated | case_index |
| `/login.php` | page | GET, POST | username, password, login_type (live bma/admin), csrf (legacy demo and every Symfony login POST) | 200 login/error form, 302 after login or with existing session | Public | login |
| `/logout.php` | page | GET, POST | csrf on POST | Legacy GET destroys session. Symfony intentionally protects logout from CSRF. Authenticated GET shows a confirmation form, anonymous GET redirects to login.php, and POST with a valid csrf destroys the session and redirects to login.php. | Public | logout |
| `/migrate.php` | CLI-only | CLI | Configured database | Applies pending SQL versions once, HTTP 404 | CLI operator | Console migration command |
| `/profile.php` | redirect alias | GET | None | 302 index.php | Authenticated | profile_alias |
| `/roles.php` | page | GET, POST | csrf, personal_id, display_name, access_role, unit_level1, unit_level2, active | 200 assignments, 302 roles.php?saved=1 after POST, 403 non-admin | Admin | access_roles |
| `/save.php` | retired-410 | Any | Ignored | 410 plain message | Public | retired_save |
| `/save_case.php` | write endpoint | POST | csrf, action, case_id except create, see form table | 302 saved case tab or selected track, 400 unknown action, 403/404/405/419/422, 409 stale state, 503 on workflow lock timeout | Admin or active case owner | case_save |
| `/save_comment2.php` | write endpoint | POST | csrf, case_id, action, older form fields below | Legacy dispatch, 302 saved case tab on successful action, 403 absent case/edit, 422 unknown action. Some branches reference obsolete schema | Case editor | comment2_save |
| `/save_discipline.php` | write endpoint | POST | csrf, case_id, track_id, subject_id, finding, opinion fields, recommend_serious | 302 discipline_results.php with track query and saved=1, 403/405/409/419/422 | Case editor | discipline_save |
| `/save_workflow.php` | internal include | Internal | Authenticated save_case action and scope | Handles discipline assignments, escalation, deadlines and decisions | Internal | No public route |
| `/settings.php` | internal include | Internal | Environment | App configuration helpers | Internal | No public route |
| `/subject.php` | page | GET | case_id, optional subject_id | 200 scoped subject list/detail, 403 outside scope, 404 missing case/subject | Case reader | subject_detail |
| `/track.php` | page | GET | case_id, type, optional track_id (defaults to set 1), saved | 200 track, 403 invalid type/scope, 404 missing track | Case reader | discipline_track |
| `/ui.php` | internal include | Internal | Page context | Layout functions | Internal | No public route |
| `/update_case.php` | retired-410 | Any | Ignored | 410 plain message | Public | retired_update_case |
| `/update_unit_target.php` | retired-410 | Any | Ignored | 410 plain message | Public | retired_update_unit_target |
| `/users.php` | redirect alias | GET | None | 302 roles.php, 403 non-admin | Admin | users_alias |
| `/view_case.php` | redirect alias | GET | id cast to integer | 302 case.php?id={id} | Authenticated | case_view_alias |
| `/workflow.php` | internal include | Internal | Case, track and date inputs | Calculation, query and view helpers | Internal | No public route |

### Active case forms

Tab redirects have the form `case.php?id={case_id}&page={tab}&saved=1`. Track redirects use `track.php?case_id={case_id}&type={track_type}&track_id={track_id}&saved=1`. For discipline results the same query goes to `discipline_results.php`. Discipline types are `discipline_nonserious` and `discipline_serious`. Each active write form posts a session token named `csrf`.

| save_case action | Additional fields | Outcome |
| --- | --- | --- |
| create_case | case_number, responsible_unit, unit_level2, case_type, case_type_other, received_date, preliminary_allegation | Tab 1, creates owner and audit. Empty number gives a 200 validation message. |
| save_page1 | Create fields, source_type, source_other, subjects[index] with id, title, first, last, position, employment, national_id, status, status_date, status_reason | Tab 1, metadata, subjects and status history |
| save_fact | acknowledged_date, first_meeting_date, duration_days, start_mode, start_date, start_override_reason, preview_due, finding/severity/finding_detail keyed by subject ID | Tab 2, fact deadlines, per-person findings and stage |
| assign_discipline | track_for[subject_id] | Tab 3, assign eligible active subjects |
| save_track | track_type, track_id, acknowledged_date, first_meeting_date, start_mode, start_date, start_override_reason, duration_days, preview_due | Track, duration set by stored track type and legacy duration |
| convert_serious_deadline | track_type, track_id, revision, reason | Track, 409 stale revision, 422 blank reason |
| add_order | track_id, event_type, order_date, order_number, detail | Fact tab 2 or discipline track |
| add_extension | track_id, requested_days, completed_work, reason | Fact tab 2 or discipline track |
| escalate_discipline | track_id, subject_id, effective_date, reference, target_track (existing serious ID or new) | Selected serious track, preserves previous round |
| save_decision | track_id, subject_id, report_kk_date, kk_resolution_date, kk_resolution, commander_decision, order_number, order_date, finished_date | Tab 4, 409 stale round, 422 serious order without resolution and date |

The server ignores the posted preview and discipline duration. Automatic timing uses the acknowledgement and first meeting. A manual start requires an override reason. Confirmation checks the stored 180-day track and extension revision under the writer lock. An extension-free draft can convert with its dates blank. A schedule that cannot produce a valid confirmation preview returns 422. Workflow lock contention returns 503 with a one-second Retry-After header.

The separate `save_discipline.php` form accepts `committee_opinion`, `appointing_authority_opinion`, `offense_basis`, and `allegation`. Outcomes are blank, `guilty`, or `not_guilty`. A serious recommendation on a non-serious track needs a committee opinion.

Page 1 saves the case, subjects and status changes with one `save_case_info` audit. Existing subject IDs must belong to this case and be active. A submitted foreign or missing ID returns 404 and rolls back the page. Only admins may edit the five existing identity fields. A partial subject edit preserves each omitted field and may update employment or status alone. Owners may enter a new subject and update an existing subject’s employment and status. A nonempty national ID after stripping separators must have 13 digits. Appending subjects from a stale form returns 409 when its new sequence has already been used.

A successful role assignment records one `assign_role` audit with a null case ID and the acting administrator. The detail contains the target personal ID, role and active flag. An empty personal ID or unsupported role retains the saved redirect and leaves both tables untouched.

### Older comment2 forms

All actions accept `csrf` and `case_id`. Their forms live in the unreferenced alternate tab template. Current migrations use different columns for several actions. For example, page1 references `uat_cases` while the active case table is `cases`. The workflow suite uses the forms emitted by `case.php`, `track.php` and `discipline_results.php`. The older endpoint's unknown-action response is also exercised.

| Action | Additional submitted fields |
| --- | --- |
| page1 | case_no, case_group, received_date, source_detail, case_type, case_type_other, subjects[] with id, reference_code, is_unknown, employment_type, position_name, level_name, current_status, status_effective_date, note |
| fact | process_mode, process_other, acknowledged_date, first_meeting_date, start_date, duration_days, note, result[subject_id] with result_code, next_action, detail |
| track_time | track_type, acknowledged_date, first_meeting_date, start_date, duration_days, note |
| extension | track_id, requested_days, completed_date, reason, page |
| referral | track_id, subject_id, ordered_date, received_date, status, note. The 45-day referral deadline includes the order date. |
| committee | track_type, set_name, order_no, appointed_date, members[]. Symfony records the named set, its appointment order and the selected active people in the current committee and membership tables. |
| discipline_result | track_type, result[subject_id] with result_code, next_action, detail, action_type, offense_basis |
| decision | decision[subject_id] with decision_path, report_kk_date, disciplinary_resolution_date, termination_reason, proposal_letter_number, commander_outcome, other_outcome, other_note, order_no, order_date, completed_date |

The legacy demo login redirects all successful POSTs to `index.php`. Live local admin login redirects to `admin.php`, and live BMA login redirects to `index.php` or `admin.php` for the configured super-admin. The live form checks a five-failure, 15-minute per-IP limit. Symfony applies that limit to both live and demo logins, and records the outcome in login_log. The synthetic suite uses local demo credentials and a simulated BMA directory.
