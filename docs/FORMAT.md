# SQL-dump .wpboat format

This release reads and writes only package version 4. Every earlier version is rejected; the product keeps no legacy readers or upgrade paths while it is in development. The container is a ZIP-based `.wpboat` file.

The ZIP contains `manifest.json`, plus `index/N.json` and `data/N` for every sequential record. SQL dump segments come first, followed by content directories and file segments. Content paths are relative to wp-content: any top-level folder (plugins, themes, uploads, mu-plugins, languages, plugin-owned folders) and top-level files except WordPress drop-ins. Every descriptor declares its exact uncompressed byte size and SHA-256. Segments are bounded at 4 MiB. The manifest is bounded at 4 MiB; individual descriptors at 64 KiB. Unknown, missing, encrypted or symlink entries invalidate the package.

The manifest includes `format: "boat-backup"`, `version: 4`, plugin version, creation time, record count, total payload bytes, source URLs (stored `home`/`siteurl` options and effective `home_url`/`site_url`, which differ when WP_HOME or WP_SITEURL are defined), content path/URL, prefix, core database revision, `options` and `database: {bytes, tables}`. Restore maps both the stored and effective source addresses to the destination's effective addresses.

`options` holds the export selections as booleans — `no_spam_comments`, `no_post_revisions`, `no_media`, `no_themes`, `no_inactive_themes`, `no_muplugins`, `no_plugins`, `no_inactive_plugins`, `no_cache`, `no_database`, `no_email_replace` — and `replace`, a list of at most 50 `{find, replace}` pairs applied on restore after the automatic address changes, in plain, URL-encoded, raw URL-encoded and JSON-escaped forms. Base64 payloads of Visual Composer `[vc_raw_html]`/`[vc_raw_js]` blocks and BeTheme `mfn-page-items` post meta are decoded, rewritten and re-encoded; invalid base64 is kept unchanged.

A password-protected package adds `encryption: {cipher: "aes-256-gcm", kdf: "pbkdf2-sha256", iterations, salt, check}` (base64 salt and check value). Every `data/N` entry then holds a 12-byte nonce, a 16-byte tag and the ciphertext, authenticated with `data/N` as additional data; descriptor `size` and `sha256` describe the stored encrypted bytes. Import asks for the password before reading any segment. With `no_database`, `database` must be `{bytes: 0, tables: []}` and no `sql` record may appear; restoring such a package changes only files. Each table descriptor contains its prefix-free name, original engine and normalized dump engine. There is no engine allowlist. Source core revision and content URL are mandatory.

Record types:

- `sql`: `path: "database.sql"`, total dump byte length and contiguous segment offset. Import reconstructs the single SQL file privately and verifies every segment before parsing it.
- `directory`: a relative content path and empty data.
- `file`: a relative content path, total file size, contiguous segment offset and modification time.

The SQL file contains native `DROP TABLE IF EXISTS`, `CREATE TABLE`, `START TRANSACTION`, `INSERT INTO (...) VALUES (...)` and `COMMIT` statements. Native definitions come from `SHOW CREATE TABLE`; columns are not reconstructed from a type/default/index grammar. Canonical table names are `boat_backup_dump_N`. Values use the native mysqli connection's escaping, preserving binary and spatial bytes. BIT values use hexadecimal literals so the driver's numeric representation cannot change their value. Generated columns are retained in DDL and omitted from INSERT lists.

Compatibility normalization: Aria becomes MyISAM; TYPE syntax is normalized; selected transactional/checksum/row-format options and foreign-reference constraints are removed. Import adapts selected utf8mb4 collations using destination capabilities and retries MySQL error 1030 with MyISAM/Aria changed to InnoDB. This is not a guarantee of compatibility with all database server versions. Source triggers, views and routines are not migrated. An unreadable source table reporting error 1194 is repaired and its SELECT retried.

The parser recognizes operation boundaries and canonical table targets, not a column-type allowlist. Each table's DROP/CREATE/rows/COMMIT sequence must be complete and contiguous. Inserts contain only named columns, NULL, quoted byte strings or hexadecimal literals. Arbitrary commands, undeclared targets, additional statements and external-resource table definitions are refused. SQL is replayed only into job-owned temporary names before the administrator confirms replacement.

URL/path rewriting applies to text columns using destination metadata; opaque geometry/BLOB/binary/BIT values are not searched or replaced. Serialized lengths, encoded URLs and option/usermeta prefixes are adapted. Imported session tokens are omitted. File payloads are never searched or replaced.
