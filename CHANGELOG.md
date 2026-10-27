# Changelog

## Unreleased

- Rename the product to Boat Backup & Migration: slug and text domain `boat-backup`, namespace `Wocody\BoatBackup`, constants `BOAT_BACKUP_*` (storage: `BOAT_BACKUP_STORAGE_PATH`), REST `boat-backup/v1`, WP-CLI `wp boat-backup`, packages `.wpboat`, fallback folder `wp-content/boat-backups`.
- Package format 4 with export options; every earlier format is refused.
- Export options: spam comments, post revisions, media, themes, inactive themes, must-use plugins, plugins, inactive plugins, cache, database and email-domain replacement.
- Custom find/replace pairs applied on restore in plain, URL-encoded and JSON-escaped forms.
- Export the whole wp-content (must-use plugins, plugin-owned folders and top-level files), excluding drop-ins, backup folders and VCS metadata.
- Restore overlays first-level items, keeping destination-only plugins, media and folders.
- Files-only restore for packages exported without the database.
- Leave login-protection and, without HTTPS, forced-SSL plugins inactive; turn off Jetpack Photon and SSO; link to Permalinks after restore.
- WP-CLI: `wp boat-backup export|list|restore|status|cancel`.
- Server-side continuation through per-job authenticated loopback requests, with browser fallback, pause and reattach.
- Map effective (WP_HOME/WP_SITEURL) and stored source addresses; support a custom uploads folder inside wp-content.
- Rewrite base64 payloads of Visual Composer raw blocks and BeTheme builder items.
- Optional AES-256-GCM password protection of backups; `--password` in WP-CLI.
- Backup labels in the list, REST and `wp boat-backup label`.
- Drag-and-drop upload.
- Follow symbolic links during export instead of failing (for example npm `node_modules/.bin`), skipping broken and looping links.
- Skip unreadable folders/files, vanished files, unsupported names and database views instead of failing.
- Export files as they are when copied; a changed cache or log file no longer stops the export.
- Adapt collations and character sets unknown to the destination (MySQL 8 `utf8mb4_0900_*`, `utf8mb3`, MariaDB `uca1400`).
- Migrate damaged serialized values with plain replacement and keep custom `C:` payloads intact instead of failing.
- Allow newer-WordPress backups and plugin/theme/uploads folders outside wp-content; require the same filesystem only to restore.
- Protected fallback storage in wp-content/boat-backups when no private location is writable.
- Restore over destination items that are symbolic links by moving the link itself.

## 0.3.0 — 2026-10-27

- Replace declarative schemas/base64 row records with native database.sql dumps.
- Use SHOW CREATE TABLE, native driver escaping and automatic compatibility normalization.
- Preserve spatial/binary data and native indexes; export generated columns through their definitions and BIT values as hexadecimal literals.
- Apply Aria/MyISAM/InnoDB fallback rules, selected collation changes and foreign-reference removal.
- Replace per-engine/checkpoint replay with a universal table replay cursor.
- Remove previous package readers and the manual engine policy; accept only current format 3.

## 0.2.0 — 2026-10-27

- Preserve InnoDB and MyISAM tables, including mixed databases.
- Add optional destination-only InnoDB conversion and a database compatibility report.
- Add bounded database preflight and table-specific engine diagnostics.
- Write engine-aware version 2 packages; continue importing version 1.
- Replay interrupted MyISAM staging without losing or deduplicating rows.
- Check all table mappings during interrupted-switch recovery.

## 0.1.0 — 2026-10-24

- New independent Wocody plugin with native WordPress administration.
- Version 1 ZIP-based `.wpboat` format with bounded records and SHA-256 checksums.
- Resumable local export, chunked import, backup downloads and deletion.
- Validated database staging, serialized-data adaptation and source-user restoration.
- Reversible file moves, final table switching and interrupted-operation recovery.
- Private storage, administrator authorization, English and Brazilian Portuguese.
