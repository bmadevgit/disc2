# DISC2 release and recovery on IIS

Keep the previous code, its external configuration, its IIS application settings and the database online-backup procedure available throughout the cutover. Rehearse on IIS staging using synthetic records and a non-production BMA endpoint. Record the tested versions, package digest, operator, backup location and results in the private change record.

## Build and verify

Use a reviewed checkout and an annotated OpenPGP-signed version tag. Obtain the approved primary or signing-key fingerprint through the release approval channel. The builder needs Git with GPG, Composer 2, PHP 8.2 or later, ext-zip, PDO SQLite, cURL, iconv, intl, mbstring, sessions, XML and OPcache. Use an NTFS or Linux filesystem with hard-link support for the output directory. Keep PHP, Composer, libzip, zlib, Git and timezone fixed across reproducibility comparisons. The installer downloads the distribution files pinned in `composer.lock`.

```powershell
.\tools\release.ps1 -Tag $tag -SignerFingerprint $approvedFingerprint -Output D:\releases\disc2.zip
.\tools\verify-release.ps1 -Archive D:\releases\disc2.zip -ExpectedSha256 $approvedDigest
```

Record the SHA-256 reported by the build with the signed tag and commit. Transfer the ZIP to staging and independently obtain `$approvedDigest` from the build record. Run the verifier from the reviewed checkout. It checks the whole-file digest, entry types, package allowlist, production dependency versions/references and every payload digest in `release-manifest.json`. The builder also writes `disc2.zip.sha256`. Both commands run through PHP CLI, and the build accepts `-ComposerPhar` for a custom Composer installation.

The builder archives the signed commit into a new directory, installs the production class map and checks PHP platform requirements. It reopens the finished ZIP and boots its extracted production runtime for container validation, route checks and cache warmup. Keep the ZIP intact until verification. Extract into an empty release directory. Keep old and new code in separate directories to prevent leftover procedural files.

## Stage the IIS application

1. Configure IIS application `/exam/disc2` on the intended site and HTTPS binding. Use `C:\inetpub\wwwroot\exam\disc2\public` as its physical path. Install IIS URL Rewrite and configure PHP FastCGI with `php-cgi.exe`. Use the `php.exe` from that installation for CLI commands. Configure anonymous authentication to use the application-pool identity. Ensure the site denies direct access to other release trees and private storage from every binding.
2. Set `APP_ENV=prod`, `APP_DEBUG=0`, a private `APP_SECRET` and the same `DISC2_CONFIG_FILE` in FastCGI and the operator CLI. Configure `super_admin_ids`, `bma_secret_key` and an absolute `database_path` in the private PHP config. Keep the BMA and AI settings in the designated separate environment. Store the SQLite database, WAL/SHM and backups outside the release and web-accessible locations.
3. Grant the pool read/execute across source, vendor, tools and the config file. Create `var`, `var\cache`, `var\log` and `var\sessions` under the extracted release. Grant Modify on those three writable subdirectories and on the database and its dedicated parent for SQLite sidecars. Keep the config directory separate from the writable database directory. Install Microsoft's AccessChk in the operator's PATH and accept its license on staging. The smoke script reads the pool identity and checks effective rights. [AccessChk documentation](https://learn.microsoft.com/en-us/sysinternals/downloads/accesschk)
4. Before migration, block public traffic and all application/background writes. Let active transactions finish. Save the external config and IIS settings in private storage. Use the SQLite CLI `.backup` on the live database, including committed WAL data, to a fresh, protected destination. Record the backup SHA-256, `SELECT COALESCE(MAX(id),0) FROM uat_audit_log` for an existing audit ledger, and the frozen time. Use zero for a new ledger. On the backup require `PRAGMA integrity_check` to return only `ok` and `PRAGMA foreign_key_check` to return zero rows. Rehearse opening this backup independently before cutover.

```powershell
# Run on the isolated IIS staging site or during the approved production freeze.
$smoke = @{Site=$site; BaseUri=$httpsUrl; ConfigFile=$configFile; Database=$database; Php=$phpExe}
.\tools\iis-smoke.ps1 @smoke -ApplyMigrations
```

The explicit switch runs `bin/console app:migrate --env=prod --no-debug` after confirming the selected external database. The command checks the migration ledger, SQLite integrity and foreign keys. Subsequent smoke runs can omit the switch. The script checks PHP and FastCGI modules, pool access, the mounted login and protected URL, cookie scope, session-backed CSRF across requests, static file digests and private-path denials. Validate the actual HTTPS response through the intended binding. In IIS Manager confirm the running FastCGI application's environment and loaded php.ini correspond to the checked CLI/CGI configuration. Verify local Admin and staging BMA authentication, case list/detail, workflow tabs and scoped audit reads with approved identities. Exercise two concurrent synthetic sessions on staging. Record their writes and audits before the rollback rehearsal. Keep production writes frozen until the operator signs off. Users sign in again and reopen forms after switching code.

## Production rollback preserves the current database

1. Freeze all writers again. Retain the current database at its authoritative path. Take a **new SQLite online backup of the current database** after the last write. Validate integrity, foreign keys and SHA-256. Protect this rollback-time snapshot separately from the pre-cutover backup. Record the case, workflow, assignment and audit IDs changed since cutover in the private change record.
2. Switch to the retained previous code and compatible machine configuration. Restore its original IIS physical path, rewrite/handler settings and app-pool identity. Keep the service behind the traffic block during validation. The previous procedural release opens `data\disc_uat.db` relative to its own directory. Rehearse mapping that path to the retained authoritative file, for example with a private data-directory junction to dedicated storage. Verify volume and file ID via `fsutil file queryfileid` on both paths and confirm all database/sidecar URLs are denied by IIS. Preserve the live WAL and SHM alongside the database. Recycle the pool to clear stale opcode and session state.
3. Use the verified Symfony package's `tools\rollback-proof.php` outside the restored webroot. Supply the **rollback-time** backup and the recorded pre-cutover maximum audit ID. The tool opens the current database via the previous code's actual `db.php`, compares every table and row including the audit ledger against that frozen backup, and reports the post-cutover audit count. Require a zero exit status and the expected audit count. During rehearsal require at least one new audit row.

   ```powershell
   & $phpExe D:\releases\verified-symfony\tools\rollback-proof.php $priorRelease $rollbackTimeBackup $preCutoverAuditId
   if ($LASTEXITCODE -ne 0) { throw 'Keep writes frozen' }
   ```

4. Through the old IIS application, sign in again and open the affected cases, outcomes, assignments and audits using the appropriate roles. Reconcile all recorded post-cutover IDs and values. Confirm this release's access controls and database-file denials. If any comparison, route or permission fails, keep writes frozen and retain both current database and rollback-time backup for forward recovery. Reopen only after the operator records all results. The pre-cutover snapshot is never restored over production writes.

## Demo rollback restores the pre-cutover database

Stop demo writers and the demo pool. Switch to the prior code, IIS mapping and configuration, then restore the verified **pre-cutover demo backup** into a clean database directory after closing all handles. Keep SQLite sidecars from the prior live directory separate. Point the previous code at the restored demo file, run integrity and foreign-key checks, sign in and verify pre-cutover cases and audit counts. Record which synthetic post-cutover writes were discarded, then reopen the demo site.

For the local synthetic compatibility suite export the prior procedural commit and run the native write/legacy read comparisons with `DISC2_LEGACY_ROOT` as described in the [route contract](rewrite/route-contract.md). Windows ACLs, live FastCGI and the IIS rollback rehearsal require an actual staging machine.
