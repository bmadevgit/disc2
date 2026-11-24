# Symfony cutover rehearsal results

**Local rehearsal passed on 24 November 2026. Production approval is pending.** The clean WSL development app and an extracted production ZIP both handled real browser requests. Windows IIS staging, the approved non-production BMA directory and operator sign-off remain open. [Cutover checklist](cutover-checklist.md)

| Rehearsal input | Value |
| --- | --- |
| Candidate | `e8bfd004a89462d46fe8a75ab47ffec022c00b6f` |
| Synthetic signed tag in isolated clones | `v1.0.0-rc.11` |
| Prior procedural code used for both restores | `c2139381043349ba3e8828daf29b4586e1a61259` |
| Verified ZIP | SHA-256 `b3162af8f5ca813ab48adcc689c854e63e827fbcebdc2564d72447b6c7972dde`, 5,014,383 bytes, 3,415 payload files plus manifest |
| Toolchain | WSL 2, Docker Engine 29.9.3, Compose 5.7.1, PHP 8.5.13, Composer 2.11.2, PowerShell 7.4.6 on Linux |

## Development and packaged runtime

| Verification | Observed result |
| --- | --- |
| Clean clone | Built the Compose image, installed 86 locked packages into a fresh vendor volume, then initialized a new synthetic database. Four migrations applied. The default loopback port was occupied, so the rehearsal used `127.0.0.1:18771`. |
| Bind-mount editing | Added and removed distinct response markers in the login Twig template, UAT stylesheet and retired-route PHP controller. All six changes appeared in HTTP responses within the polling window. Container ID and image ID stayed identical, restart count stayed zero and the clone had no tracked diff. |
| Full regression | PHPUnit passed **185 tests and 2,591 assertions** with warnings, deprecations and skips treated as failures. All three procedural characterization groups and the Node login-input test passed. |
| Static and production checks | PHP lint passed for 133 PHP files and the console entry point, six YAML files and 20 Twig templates. Container checks passed in dev, test and prod. Production cache clear/warmup, 29-route enumeration, and the functional contract covering 33 legacy inventory entries passed. |
| Dependency and package checks | Composer strict validation, platform checks and locked audit passed. The first audit reported seven Twig 3.31.0 advisories. A targeted update to 3.31.1 cleared them. SimpleXML is declared for development. Two clean builds from the same commit and tag name, independently signed in disposable clones, produced identical bytes. Both ZIPs passed the production verifier. |
| Staging equivalent | Served the extracted ZIP with production config, a private external legacy fixture, HTTPS and an application mount of `/exam/disc2`. The packaged preflight, migration, platform check, container lint and cache warmup passed. The initialized prior fixture already had all four migrations. The PowerShell binding/storage policy fixtures also passed. |
| Mounted HTTP | The packaged probe verified all four asset hashes, session-backed CSRF, secure HttpOnly cookie scope, the mounted login redirect and all nine private-path denials. The verified client pinned and validated the local certificate endpoint. A test TLS frontend and PHP development server emulated the mount. IIS, FastCGI and NTFS were outside this run. |

## Browser and authorization evidence

- Chromium exercised local admin, case owner, group head, executive, unit head and unrelated owner in both environments. The dev run also checked an unassigned account. The package run added a configured super-admin and BMA unit fallback through a synthetic HTTPS directory. A previous-release `disc2_uat` cookie was cleared and each packaged session required a fresh login. The real staging BMA endpoint remains an operator gate.
- Checked allowed and denied access on all four case tabs, subject detail, discipline track, results and role management. Verified the masked owner edit fields, group-head mobile viewport, dashboard access, persisted role assignment and case creation/edit. Browser subresource requests stayed on the local app. The PHP suite independently exercised names, search filters, former owners and exact case and subject denial messages.
- In each runtime the owner saved findings, assigned a discipline track, observed the 30/60-day previews, recorded an order and extension, and submitted a per-person finding. A stale appointment produced 409. Selecting the refreshed actual order transferred the subject, retained the previous-round history, and allowed a serious finding and dated decision. The procedural and Symfony fixtures also covered the historical 180-day conversion and posted preview/duration handling.
- Authenticated GET of the retired-form handler `/save_comment2.php` returned 403. The unregistered spelling `/save_comment.php` returned 404. A denied editor on `/save_case.php` and `/save_discipline.php` receives `ไม่มีสิทธิ์แก้ไข`. The role audit test checks the target ID, role and active flag for assignment and deactivation.
- Two independent authenticated package sessions submitted overlapping case creates to a multiworker PHP server. Both received 302 responses for different case IDs. The frozen snapshot included both rows and both create audits.

## Rollback evidence

The rehearsal took an online SQLite backup before applying the packaged migration. After the UI writes, it stopped the HTTPS listener and packaged app workers and made a second online backup. The pre-cutover maximum audit ID was zero. The following counts precede the old UI readback, which adds login history.

| Frozen result | Pre-cutover | At production rollback |
| --- | ---: | ---: |
| Cases | 1 | 4 |
| Role assignments | 5 | 6 |
| Tracks / extensions / committee orders | 0 / 0 / 0 | 3 / 1 / 3 |
| Escalations / decisions | 0 / 0 | 1 / 1 |
| Audit records | 0 | 18 |
| SQLite integrity / FK violations | ok / 0 | ok / 0 |
| Backup SHA-256 | `ffe32decab80c943fe52aa8a9e585989f64df6cafe010469c2ff8d91808a7ec1` | `6f120f2ac7f9eab27af1b9d740ed78bced833396d093992b7b7a4d7ce1801aca` |

**Production keep-DB rehearsal.** Mapped the old code's data directory to the retained file and verified file identity. Its actual `db.php` matched all 24 tables and every row against the rollback-time backup, including all 18 post-cutover audits. The prior application accepted a fresh local-admin login and rendered the edited case, new assignment, prior-round history and final order. The two simultaneous creates were present. Integrity and foreign-key checks remained clean after readback.

**Demo restore.** Used a separate old code tree, restored its configuration snapshot and copied only the pre-cutover database into a clean data directory. All 24 tables and every row matched the pre-cutover backup, with zero new audits. The old UI showed the baseline case and no new case, role or final order. Comparing that restore against the rollback-time backup correctly failed on `cases`.

## Approval boundary

The rehearsal evidence commit is SSH-signed with a disposable rehearsal key. The operator must separately verify the approved release signer, exact production artifact and actual deployed prior version. No production credentials, database, endpoint, release tag or Server-A site participated. Use the private change record to sign the live Windows, directory, restore and traffic-reopening gates. [IIS runbook](../IIS-DEPLOYMENT.md) · [Operator gates](cutover-checklist.md)

Local rehearsal signature fingerprint: `SHA256:b+dXMQFDEXJSkl044q+02TSINAuk7K1WTIKkAcDKI20`.
