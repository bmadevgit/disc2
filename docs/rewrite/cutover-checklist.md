# DISC2 cutover checklist

**Current decision: HOLD for Windows staging and operator approval.** The WSL and packaged-runtime rehearsal passed on synthetic data on 24 November 2026. The operator records the actual release tag, approved signer, package digest, staging host, previous release, database identity, backup references, UTC freeze time and sign-offs in the private change record. [Rehearsal results](parity-results.md) · [IIS deployment and rollback commands](../IIS-DEPLOYMENT.md)

## Before scheduling the production freeze

- [ ] The release owner verifies the reviewed commit and signed version tag using the independently approved signer fingerprint. Build into an empty directory, verify the ZIP against the independently recorded SHA-256, and retain the exact previous deployment package and external configuration. A new tag, commit or dependency lock requires a new package digest.
- [ ] The Windows operator deploys that verified ZIP to an isolated IIS staging application at `/exam/disc2`, physical path ending in `public`. Record the Windows, IIS, PowerShell, PHP CLI/CGI, Composer and AccessChk versions. Verify the PHP version actually used by FastCGI, its extensions, OPcache, environment and loaded configuration.
- [ ] Verify the intended started site and HTTPS binding, PHP handler, URL Rewrite, application-pool identity and anonymous identity. Run the packaged IIS smoke. Require a match on all four live asset hashes and successful AccessChk verification of immutable source/config, every config ancestor, writable cache/log/sessions and SQLite files, directory and sidecars. Record results without credentials.
- [ ] Confirm staging uses the independently approved non-production BMA HTTPS endpoint and staging key. Test fresh local-admin and BMA logins through FastCGI. Check invalidation of the previous session, cookie scope, HTTPS flags, CSRF, route and private-path denials across requests. Verify the real UI calendar assets in the approved staging network.
- [ ] On staging, freeze writers, record the maximum audit ID, snapshot the external configuration and IIS mapping, and take a SQLite online backup. Independently open the backup. Require exactly `ok` from `PRAGMA integrity_check` and zero rows from `PRAGMA foreign_key_check`. Record the digest and protected restore location.
- [ ] Run the staging smoke with `-ApplyMigrations`. Repeat without the switch. Exercise the role matrix, edit a case, transfer one subject between committee rounds, save a dated decision, change an assignment and submit two concurrent sessions. Record the resulting IDs and expected audit count. [Role matrix](role-access-matrix.md) · [Route contract](route-contract.md)
- [ ] Rehearse **both rollback modes below** with the actual approved prior Windows package, physical paths, identities and ACLs. Retain the staging operator's and release owner's dated approvals. Keep the change on hold if the prior release cannot open all retained writes.

## During the approved production window

- [ ] The operator freezes HTTP, background and other writers, drains active transactions, records the audit watermark and verifies a fresh online backup including committed WAL data. Snapshot the current config, bindings, handler settings and release mapping.
- [ ] Verify the production package digest again. Switch to the clean release's `public` path and compatible external config. Run the documented production IIS preflight, explicit migration and follow-up smoke with the independently approved production parameters. Keep normal traffic closed during validation.
- [ ] Confirm fresh sign-in and scoped list, detail, subject, workflow, dashboard and administration access. Verify database path, integrity, foreign keys, cookie/CSRF behavior and denial of private files through the actual binding. Record the operator, time, results and reopen decision.

## If rollback is needed

| Environment | Database and code action under the write freeze | Required evidence before reopening |
| --- | --- | --- |
| Demo | Stop the demo pool. Restore the previous code, mapping and configuration. Restore the verified **pre-cutover** database into a clean directory with no stale sidecars. | Compare the restored rows and audits with that snapshot, verify SQLite integrity/FKs, sign in through the previous UI, and identify the discarded synthetic case, workflow and audit changes. |
| Production | Stop writers. Retain the **current live database and sidecars** and take a separate **rollback-time online backup**. Switch only code, compatible config and IIS mapping. Verify the prior code's fixed `data\disc_uat.db` path resolves to the same authoritative file using Windows volume and file IDs. | Run the verified `tools/rollback-proof.php` against the prior code, rollback-time backup and original audit watermark. Require every schema, table and row to match and reconcile the full expected audit count. Sign in under the old code and read back the affected cases, outcomes and assignments. Verify access and private-file denials. |

**A failed check keeps writers frozen.** Preserve both snapshots and the current database for investigation. The operator records approval to reopen or escalates for forward recovery. No Server-A change or production release approval is recorded by this checklist.
