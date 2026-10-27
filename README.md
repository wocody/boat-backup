# Boat Backup & Migration

Independent local WordPress migration plugin by **Wocody**, GPL-2.0-or-later. WordPress 6.0+, 64-bit PHP 7.4+, native mysqli and ZipArchive are required. Open **Tools → Boat Backup & Migration** after activating it.

## Database architecture

`Database` exports native `SHOW CREATE TABLE` definitions and driver-escaped INSERT statements to `database.sql`. `Sql` reads byte-preserving statements, including multiline definitions, spatial data, binary literals, generated columns and native indexes. Column types and engines are interpreted by the destination server rather than an application allowlist.

Database compatibility is adapted automatically: Aria → MyISAM, native option normalization, removal of foreign references, capability-based utf8mb4 adaptation and an InnoDB retry for MySQL error 1030. A crashed source table reporting error 1194 is repaired before retrying its read. SQL and PHP code in backups must come from a trusted source.

Only current version 4 `.wpboat` packages are accepted. The product keeps no legacy readers, upgrade paths or manual engine conversion policies while in development. SQL is checksummed in bounded segments, reconstructed privately, validated and loaded into temporary tables before final confirmation. A write-ahead table marker allows any interrupted batch to rebuild that table from its DROP statement; there are no per-engine replay branches or checkpoint tables. Content moves and the final table switch retain the existing recovery journal.

## Development

```sh
composer install
composer test
composer lint
composer standards
composer build
```

The runtime has no Composer dependency. The build writes `dist/boat-backup-0.3.0.zip` from an explicit runtime allowlist; tests, tools, Git, credentials and backups are excluded.

## Private storage

Use a writable directory outside the public document root and on the same filesystem as `wp-content`. Its parent must exist:

```php
define( 'BOAT_BACKUP_STORAGE_PATH', '/private/path/boat-site-backups' );
```

By default the plugin uses a site-specific `boat-backup-private-<hash>` sibling of the WordPress installation. The host administrator must ensure additional web aliases cannot expose it. Standard content directories and a single-site installation are required. See [format](docs/FORMAT.md), [recovery](docs/RECOVERY.md) and [security](SECURITY.md).

## REST and authorization

Namespace: `boat-backup/v1`. Routes: `POST jobs`, `GET current`, `GET jobs/{id}`, `POST jobs/{id}/{step,upload,pause,password,commit,cancel}`, `GET backups`, `DELETE backups/{name}`, `POST backups/{name}/label`. Jobs take `kind`, `name`, `size` and, for exports, `options` (see [format](docs/FORMAT.md)) with an optional `options.password`; no engine policy parameter remains. Jobs started through REST continue on the server through loopback requests; summaries report `driver: server|browser` and the page only drives steps itself when the server is not running them. Upload takes binary segments and an `offset`; commit requires `{"confirm":true}`. Every route requires an administrator session, `manage_options` and a valid `X-WP-Nonce`. Downloads use a backup-specific nonce through `admin-post.php`.

`GET current` returns `{"job": null}` when idle. Progress mutations return the current job summary, with byte offsets for uploads and engine adaptation information for the database report. Operations belong to the installation and can be resumed by another administrator. Cancel an unfinished operation before upgrading code or creating a new one; obsolete operation formats cannot be resumed.

## WP-CLI

```sh
wp boat-backup export [--exclude-spam-comments] [--exclude-post-revisions] [--exclude-media] [--exclude-themes] [--exclude-inactive-themes] [--exclude-muplugins] [--exclude-plugins] [--exclude-inactive-plugins] [--exclude-cache] [--exclude-database] [--exclude-email-replace] [--password=<password>] [--replace <find> <replace>...]
wp boat-backup list [--format=table|json|csv]
wp boat-backup label <backup> [<label>]
wp boat-backup restore <backup-name-or-path> [--password=<password>] [--yes]
wp boat-backup status
wp boat-backup cancel
```

CLI commands run the same durable jobs as the administration screen; an operation started in one can be inspected or cancelled in the other.

## Integration testing

Use disposable WordPress installations with distinct databases, table prefixes and URLs. Set `BOAT_BACKUP_TEST_SITE=true`, disable automatic updates/cron, and use identical content paths in CLI and HTTP configuration. Never run destructive tests against a real site.

```sh
wp eval-file tests/integration.php seed --path=/disposable/source
wp eval-file tests/sql.php seed --path=/disposable/source
wp eval-file tests/integration.php export --path=/disposable/source
wp eval-file tests/integration.php target --path=/disposable/target
# Copy the printed package into the destination's private backups directory.
wp eval-file tests/sql.php replay BACKUP_NAME.wpboat --path=/disposable/target
wp eval-file tests/sql.php partial-switch --path=/disposable/target
# Protected variant: export ... password=secret, then stage BACKUP_NAME.wpboat secret.
wp eval-file tests/integration.php commit --path=/disposable/target
wp eval-file tests/integration.php verify --path=/disposable/target
wp eval-file tests/sql.php verify --path=/disposable/target
```

The native fixtures cover POINT/LINESTRING/POLYGON/GEOMETRYCOLLECTION with SRIDs, spatial indexes, binary bytes containing source URLs, BIT/decimal/generated columns, duplicate keyless MyISAM rows, MEMORY tables and foreign references. `replay` simulates an inserted row whose cursor was not saved; `partial-switch` checks that an ambiguous switch retains maintenance. `tests/failures.php` covers upload retries, cancellation, file rollback and recovery before/after the final table switch.

`tests/browser.mjs` exercises export, multipart upload, automatic compatibility reporting, confirmation, session exit, downloads and mobile layout through Playwright. It requires `BOAT_BACKUP_ALLOW_DISPOSABLE_RESTORE=yes`, separate `BOAT_BACKUP_SOURCE_URL`/`BOAT_BACKUP_TARGET_URL` and their corresponding `_USER`/`_PASSWORD` variables. Set `BOAT_BACKUP_PLAYWRIGHT_MODULE` when Playwright is installed outside the repository. Screenshots/downloads remain in random system temporary directories. Run the official Plugin Check before directory submission.

## Publication

Keep plugin header, constant, stable tag, changelog and build version synchronized. Public publisher/support details and the full release matrix must be completed before WordPress.org submission. No publication is performed by these development scripts.
