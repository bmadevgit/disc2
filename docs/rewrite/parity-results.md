# Symfony cutover rehearsal results

**Production remains on HOLD for Windows IIS staging, the approved non-production BMA directory and operator sign-off.** The final-fix candidate passed the WSL development, extracted-package and previous-release rollback rehearsals on synthetic data on 24 November 2026. [Cutover checklist](cutover-checklist.md)

| Rehearsal input | Verified value |
| --- | --- |
| Candidate commit | `0db4d5a7002b75679bd6edde3ea2e30711325378` |
| Local signed candidate tag in two isolated clones | `v1.0.0-rc.12` |
| Prior procedural release for the database fixture and UI readback | `c2139381043349ba3e8828daf29b4586e1a61259` |
| ZIP SHA-256 | `c501c8016ee9b3fd4a5c68a1a9a96f4691069a5bff09bb634c4c1a4db4de16f3` |
| ZIP size and contents | 5,037,529 bytes, 3,420 payload files plus the manifest |
| Environment | WSL 2, Docker Engine 29.9.3, Compose 5.7.1, PHP 8.5.13, Composer 2.11.2, PowerShell 7.4.6 on Linux |

## Development and packaged runtime

| Verification | Observed result |
| --- | --- |
| Clean development clone | Built the Compose image, installed all 86 locked packages in a fresh volume and initialized a new synthetic database. Four migrations applied. Twig, CSS and controller edits appeared and reverted on loopback through the bind mount. |
| Regression suite | **206 tests, 2,903 assertions**, with warnings, deprecations and skips treated as failures. All three procedural characterization groups and the Node login-input regression passed. The four strengthened cutover-order checks contributed 140 assertions. |
| Static and runtime checks | PHP lint passed for 136 files and `bin/console`. Six YAML files, 20 Twig templates, the dev/test/prod containers, prod cache warmup, route enumeration, Composer strict validation, platform requirements and the locked dependency audit passed. |
| Reproducible package | The release script built from independently signed local tags in two clean clones. Both production installs, extracted runtime checks and verifiers passed, and the ZIPs compared byte for byte. The seven public asset hashes and the upstream MIT license were present in the manifest. |
| Mounted HTTPS | The extracted ZIP served `/exam/disc2` with private config and a synthetic HTTPS BMA directory. Preflight verified the selected SQLite file, BMA endpoint and independent optional-AI recovery copy. CLI migration, cache warmup, session-backed CSRF, secure HttpOnly cookie scope, login redirect and all nine private URL probes passed. The packaged PHP probe validated the local TLS peer and all seven live asset hashes. |
| IIS policy fixtures | Portable PowerShell tests exercised binding selection, optional AI enabled/absent, recovery bytes, effective config file/directory/ancestor rights and SQLite sidecar rights. The operator must run the native IIS smoke with actual AccessChk output on Windows. |

## Browser and business parity

Chromium opened the case form with third-party requests blocked on both the development app and the extracted HTTPS package. Each loaded flatpickr 4.6.13, its stylesheet and Thai locale from its own mount. The test advanced and reversed the Buddhist year, selected leap day, displayed **29 กุมภาพันธ์ 2567** and read the submitted ISO value **2024-02-29**. The reviewed pre-fix application had no functioning picker with its CDN blocked. The earlier RC11 role and workflow tests did not verify an offline calendar.

The browser matrix covered seven development and eight packaged roles/accounts, including the packaged BMA unit fallback and configured super-admin. It checked fresh sessions, case/subject access and masking, all four workflow tabs, the mobile group-head view, case creation/edit, role assignment, previews, orders, extensions, escalation history and a dated serious decision. A stale appointment returned 409. Two overlapping creation requests through separate authenticated sessions both returned 302 to distinct cases.

Functional regressions covered two sessions submitting different subject rosters, an existing serious round without a recorded appointment, a UTC day crossing midnight in Bangkok, extension-specific Thai validation, malformed base and latest-extension deadlines, high-water IDs removed before migration 004, and client IP limits through trusted and direct connections. The retained replay path passed. The routing and identity expectations remain in the compatibility contracts. [Role matrix](role-access-matrix.md) · [Route contract](route-contract.md)

## Rollback evidence

Before the candidate migration or any staged sign-in, the rehearsal saved independent matching copies of the main and optional AI configurations, recorded the previous audit watermark, took an online SQLite backup and opened it independently. The staged listener and all packaged PHP workers stopped before the rollback-time online backup.

| Frozen result | Before cutover | At production rollback |
| --- | ---: | ---: |
| Cases | 1 | 4 |
| Role assignments | 5 | 6 |
| Tracks / extensions / committee orders | 0 / 0 / 0 | 3 / 1 / 3 |
| Escalations / decisions | 0 / 0 | 1 / 1 |
| Audit rows | 0 | 18 |
| Login history / BMA unit assignments | 0 / 0 | 12 / 7 |
| Integrity / foreign-key violations | ok / 0 | ok / 0 |
| Backup SHA-256 | `44d0a7ae1b756d04d88d6f79b01a57589caf49e59c34773fbe62d8c0a94890ec` | `8dfc0e5ff531081639501de4acba45e6e2c800d4b1e3332e298e5c787b3f7b78` |

The prior release opened the retained live file for production rollback. Its 45 schema objects (24 tables and 21 indexes) and every stored row matched the rollback-time backup, including all 18 new audits. The prior UI showed the edited case, role assignment, escalation history and serious decision. The frozen DB held both concurrent creates. On the separate demo restore, the prior application matched the pre-cutover backup and saw one case and zero new audits. Both restored configurations matched their private originals. Each old UI login added one login-history entry. Subsequent integrity and foreign-key checks passed.

The rollback verifier rejected the demo restore against the later snapshot and rejected a database differing only by one extra index. Additional automated controls rejected trigger and view drift. The previous Windows mapping, deployed code, authoritative file identity, actual external config and backups still require on-host verification before the switch.

## Operator approval

The synthetic candidate tags, package and signed rehearsal record are local. The staged PHP development server and TLS fixture exercised the mounted HTTP contract. Native IIS binding selection, FastCGI environment and extensions, NTFS/AccessChk rights including the selected optional AI file, sidecars, the real approved BMA endpoint and previous Windows package remain staging gates. Record the independently approved production signer, digest, baseline and both rollback readbacks before opening writes. [IIS deployment and recovery](../IIS-DEPLOYMENT.md) · [Cutover checklist](cutover-checklist.md)

Local rehearsal signature fingerprint: `SHA256:yJcQqx6gsLj/T/uHedWe2TK+ShNQ80kJP5WlzenIePU`.
