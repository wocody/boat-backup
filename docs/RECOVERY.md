# Recovery and retention

The Tools screen displays the private directory. Keep a record of that location before removing the plugin. Each job has `jobs/<id>/state.json`; uploaded/exported packages live in `backups/`. The journal contains paths, phases and table metadata, not row values. The job directory also contains the sensitive native database.sql dump. Staged content and recovery copies still contain the site's sensitive data.

## Before confirmation

An upload, validation or database staging error leaves live tables and content in place. Fix the reported issue and select Resume, or cancel to drop temporary tables and remove disposable staging files. For an upload, select the original `.wpboat` file again. Changing to another backup requires canceling the current upload.

SQL loading uses a write-ahead pending-table marker and a byte offset into database.sql. If a request dies before publishing its cursor, Resume recreates only that temporary table and replays from its DROP statement. This applies to every engine, preserving genuine duplicate rows without INSERT IGNORE or value-based deduplication. Each successful batch commits before its cursor is published. Table normalization and engine fallback are automatic; there is no manual conversion policy.

## During final application

The job enters `applying`, writes `maintenance.json`, temporarily leaves only Boat Backup & Migration active, then journals each content move before executing it. Previous files are moved to `jobs/<id>/recovery/files/`. Staged files replace the included content. All old tables move to `boat_backup_<16-character-job-prefix>_old_N`, and new tables replace the live prefixed names, in one RENAME TABLE statement.

On an ordinary exception the plugin reverses moves immediately. If PHP is killed, the next WordPress request obtains the operation lock and recovers before theme functions load. It determines whether the table switch already succeeded using the complete old/staged/live table mapping. A partial or ambiguous switch keeps maintenance enabled for manual recovery; a single sentinel table is not sufficient. A completed switch is finalized; an uncommitted switch is reversed and returns to the confirmation state. Recovery itself is idempotent.

If requests keep returning 503, stop other traffic and preserve both the private directory and database. Check permissions, free disk, the state file and database connectivity. Do not delete `maintenance.json` to bypass an unresolved recovery: that can expose a partially applied site. A host administrator can run WordPress through WP-CLI to trigger the same recovery; the configured private directory must match the web process.

## After completion

Sign in with a source administrator account. Check the destination address, media, theme, plugins, forms, permalinks and application-specific data. Imported sessions were removed, and source salts did not replace the destination configuration. Third-party caches/drop-ins, must-use plugins and hosting services need their own host-specific review.

Previous tables and files remain private after success. Their mapping is in the completed job's `old_tables` and `moves` journal. This version does not offer a one-click rollback of a completed migration. Restoring that previous state is an administrator operation: stop traffic, back up the current state, map retained old tables back to the destination prefix, and restore the matching previous files, keeping Boat Backup & Migration installed. Do not combine files from one recovery point with tables from another.

Once the new site is verified and the retention period is satisfied, the administrator may deliberately remove the mapped old tables, the completed job directory. Never remove another site's similarly named tables or active job material. The plugin does not automatically purge recovery copies.

## Deactivation and uninstall

Deactivation cancels a staged job safely; backups are retained. Uninstall removes disposable export/cancelled job directories and plugin options, while preserving backups, completed recovery material and unresolved journals. If cleanup fails, it favors preservation. Remove retained files and database recovery tables separately after checking the mappings.
