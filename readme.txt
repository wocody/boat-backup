=== Boat Backup & Migration ===
Tags: migration, backup, restore, export, import
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export, import and restore single WordPress sites using local .wpboat backups, with resumable processing and no commercial size quota.

== Description ==

Boat Backup & Migration by Wocody moves a single WordPress site using a local backup file. Export the source, install this plugin on the destination, upload the .wpboat file and confirm restoration after validation.

* Native SQL table definitions and records, including spatial data, binary values and plugin tables using the site's prefix.
* Everything in wp-content: plugins, themes, media, must-use plugins, languages and plugin-owned folders.
* Export options: leave out spam comments, post revisions, media, themes, inactive themes, must-use plugins, plugins, inactive plugins, cache or the database; keep email domains unchanged.
* Custom find and replace pairs applied on restore.
* Processing continues on the server after you close the page, with automatic browser fallback when the host blocks loopback requests.
* Optional password protection (AES-256-GCM) and backup labels.
* Drag-and-drop upload.
* WP-CLI commands: `wp boat-backup export`, `wp boat-backup list`, `wp boat-backup label`, `wp boat-backup restore`, `wp boat-backup status` and `wp boat-backup cancel`.
* Chunked uploads and processing with saved progress.
* Checksummed SQL/file segments and temporary-table loading before replacing the destination.
* URL, content-path and table-prefix adaptation, including serialized data.
* Private recovery copies of the previous content and database tables.
* Local backup listing, authenticated downloads and deletion.
* English and Brazilian Portuguese administration.

No account, license key, paid add-on, tracking or external service is required. There is no commercial limit on total backup size. Disk space, filesystem permissions, PHP memory and hosting limits still apply.

= Requirements and supported sites =

WordPress 6.0+, 64-bit PHP 7.4+ with mysqli and ZipArchive. Source tables are exported through SHOW CREATE TABLE and driver-escaped INSERT statements; there is no column-type or engine allowlist. The destination must support the resulting native definitions. Private storage must be writable, outside the public document root and on the same filesystem as wp-content. Standard plugins and themes directories are required; the uploads folder may be customized but must be inside wp-content. Statement size depends on PHP memory and the database packet limit.

Only current version 4 .wpboat packages are supported. Previous package formats cannot be imported or upgraded. Multisite, views, triggers, routines, cloud services and scheduled backups are not supported, and packages created by other backup or migration plugins cannot be imported.

= Exclusions and restore behavior =

WordPress core, wp-config.php, server configuration, WordPress drop-ins (such as object-cache.php and advanced-cache.php), the migration plugin itself, backup folders of migration plugins, WordPress upgrade folders and version-control metadata are always excluded. Everything else in wp-content is included unless an export option leaves it out. Hosting must-use plugins from Endurance and GoDaddy are not installed on restore. Symbolic links are followed and their targets are stored as regular files and folders; broken links and links that would loop are skipped. Development folders such as node_modules are included like any other content. Unreadable folders and files, files removed during export and names that cannot be restored safely on every system (control characters, backslashes, colons, invalid UTF-8) are skipped. Database views are skipped. Collations unknown to the destination, such as MySQL 8 utf8mb4_0900 collations on older servers, are replaced with the closest supported one. Damaged serialized values are migrated with plain replacement instead of stopping the restore.

Restore overlays content: each top-level file and each first-level item of a folder in the backup (a plugin, a theme, an uploads year folder) replaces the item with the same name on the destination. Items that exist only on the destination stay in place. Replaced items remain in private recovery storage.

A backup with a database replaces all destination tables using its prefix, including destination-only tables. A backup exported without the database only changes files. The destination address, database connection, core and hosting configuration stay in place. Source users replace destination users; all imported sessions are removed. Sign in with a source administrator account after restoring. Boat Backup & Migration stays active. Plugins that change or protect the login address (WPS Hide Login, Hide My WP, Rename wp-login, Lockdown WP Admin, Invisible reCAPTCHA) and, without HTTPS, forced-SSL plugins stay inactive after restore; Jetpack's image CDN and SSO modules are turned off. Save the permalinks settings once after restoring.

Database compatibility adjustments are automatic: Aria becomes MyISAM, foreign-key constraints and selected table options are removed, selected utf8mb4 collations are adapted to destination capabilities, and MySQL error 1030 triggers an InnoDB retry for MyISAM/Aria. A crashed source table reporting error 1194 is repaired before its read is retried. Spatial/binary bytes are preserved; BIT values use hexadecimal literals and generated columns are recomputed. There is no manual engine conversion setting.

Only restore trusted packages: a site backup contains executable native SQL and can contain PHP plugins and themes. Checksums detect corruption; they do not authenticate the package author.

= Consistency, interruptions and recovery =

Pause editing, orders, queues and other writes on the source during export. This release does not provide a transactionally consistent live snapshot across multiple HTTP requests.

Export and staging continue on the server when the page is closed; reopen it to follow progress. If the host blocks the plugin's loopback requests to itself, the page drives the steps and must stay open. Saved steps can be resumed; interrupted uploads require selecting the same file. Validation and staging do not replace the live site. Confirm restoration only after reviewing the warning and ensuring that you have source administrator credentials.

The final step temporarily blocks new WordPress requests, disables other plugins while moving content, and switches tables using one RENAME TABLE statement. It is not an atomic transaction spanning the filesystem and database. Existing in-flight requests, direct static-file access and hosting processes are not locked by the plugin; use your hosting maintenance controls if those must also be stopped.

An interrupted final step is recovered on the next WordPress request before loading theme functions. Before the table switch, file moves are reversed. After a successful switch with a lost response, the operation is marked complete. If recovery cannot complete, requests return 503 and the recovery journal is preserved for an administrator. Do not remove the private storage directory while restoring.

= Storage, privacy and uninstall =

Backups and journals stay on your server; the plugin does not transmit site data or load remote assets. Backups may include personal data, password hashes and third-party credentials stored by other plugins. Protect downloaded copies accordingly.

The administration page displays the private storage path. By default, the plugin uses a site-specific directory beside the WordPress installation, provided it is outside the document root. Define BOAT_BACKUP_STORAGE_PATH in wp-config.php to select another existing-parent location outside the public document root, on the same filesystem as wp-content. When the host allows no writable folder outside the public root, backups are kept in wp-content/boat-backups, protected by index.php, .htaccess and web.config.

Deactivation cancels a staged operation safely. Uninstall removes disposable job state and plugin settings but preserves backup files and unresolved or completed recovery material. Old recovery tables and directories require deliberate administrator cleanup after verification.

== Installation ==

1. Upload the boat-backup folder to wp-content/plugins, or install the plugin ZIP through Plugins > Add New.
2. Activate Boat Backup & Migration.
3. Open Tools > Boat Backup & Migration. Resolve any private-storage or compatibility notice.
4. On the source, select Export site and download the finished .wpboat file.
5. On the destination, open Import, upload the file and wait for verification.
6. Review the replacement notice, confirm restoration and sign in with a source administrator account.

== Frequently Asked Questions ==

= Is there a maximum total backup size? =

There is no commercial total-size quota. Upload and content processing use small parts. Your hosting resources, available disk space, database packet limits and the documented per-row safety bound still apply.

= Can I import backups made by other plugins? =

No. Boat Backup & Migration imports only current version 4 .wpboat files. Earlier package formats are refused.

= Will my destination login still work? =

Source users replace destination users. Use an administrator account from the source site after restoration.

= What if the destination runs a newer WordPress version? =

The destination core stays in place. WordPress may show its standard database-upgrade screen after importing an older database. Complete that upgrade before checking the restored administration. Importing a newer database into an older core is refused.

= Does passing Plugin Check guarantee WordPress.org approval? =

No. Automated checks complement the directory's manual review. This initial release is prepared for local evaluation; the publisher account and public project URLs must be finalized before submission.

== Changelog ==

= 0.3.0 =
* Replace declarative schemas and encoded row records with native SQL dumps.
* Apply automatic table compatibility adjustments and preserve spatial/binary values.
* Remove previous-format readers and manual engine policies.

= 0.2.0 =
* Preserve InnoDB and MyISAM engines, support mixed databases, offer explicit InnoDB conversion and recover interrupted MyISAM staging.
* Write version 2 packages while retaining version 1 import support; report database compatibility before replacement.

= 0.1.0 =
* Initial independent implementation by Wocody: local migration, versioned packages, staged restoration and private recovery material.

== Upgrade Notice ==

= 0.3.0 =
Breaking package-format change. Create a fresh export with this release; previous packages and unfinished operations cannot be resumed.

= 0.2.0 =
New exports require version 0.2.0 or later to restore. Existing version 1 backups remain supported. Validate a migration on a disposable copy.

= 0.1.0 =
Initial release. Validate a complete migration on a disposable copy before relying on a backup for recovery.
