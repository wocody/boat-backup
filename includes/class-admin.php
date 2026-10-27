<?php
/**
 * Native Tools screen and localized administration assets.
 *
 * @package Wocody\BoatBackup
 */

namespace Wocody\BoatBackup;

use Throwable;

/** Admin service for local migration operations. */
final class Admin {

	/**
	 * Menu.
	 *
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public static function menu() {
		add_management_page( 'Boat Backup &amp; Migration', 'Boat Backup &amp; Migration', 'manage_options', 'boat-backup', array( self::class, 'render' ) );
	}

	/**
	 * Assets.
	 *
	 * @param mixed $hook Hook.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public static function assets( $hook ) {
		if ( 'tools_page_boat-backup' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'boat-admin', plugins_url( 'assets/admin.css', BOAT_BACKUP_FILE ), array(), BOAT_BACKUP_VERSION );
		wp_enqueue_script( 'boat-admin', plugins_url( 'assets/admin.js', BOAT_BACKUP_FILE ), array(), BOAT_BACKUP_VERSION, true );
		wp_localize_script(
			'boat-admin',
			'boatBackupAdmin',
			array(
				'api'     => rest_url( Rest::API . '/' ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'chunk'   => Engine::upload_limit(),
				'strings' => array(
					'preflight'     => __( 'Checking database compatibility…', 'boat-backup' ),
					'scan'          => __( 'Checking content files…', 'boat-backup' ),
					'database'      => __( 'Exporting database…', 'boat-backup' ),
					'files'         => __( 'Exporting content files…', 'boat-backup' ),
					'finalize'      => __( 'Finishing the backup…', 'boat-backup' ),
					'upload'        => __( 'Uploading backup…', 'boat-backup' ),
					'validate'      => __( 'Verifying backup and staging files…', 'boat-backup' ),
					'sql_validate'  => __( 'Checking the native SQL dump…', 'boat-backup' ),
					'database_file' => __( 'Adding database.sql to the backup…', 'boat-backup' ),
					'load'          => __( 'Loading and adapting the staged database…', 'boat-backup' ),
					'ready'         => __( 'Backup verified. Review and confirm the restore below.', 'boat-backup' ),
					'applying'      => __( 'Restoring site. Keep this page open…', 'boat-backup' ),
					'completed'     => __( 'Operation completed.', 'boat-backup' ),
					'exportDone'    => __( 'Your backup is ready to download.', 'boat-backup' ),
					'restoreDone'   => __( 'Site restored. Sign in with an administrator account from the source site.', 'boat-backup' ),
					'cancelled'     => __( 'Operation cancelled. The destination was preserved.', 'boat-backup' ),
					'paused'        => __( 'Paused. You can resume this operation.', 'boat-backup' ),
					'failed'        => __( 'The operation stopped. Review the error, then resume or cancel.', 'boat-backup' ),
					'network'       => __( 'The request failed. Check the connection, then resume. For a restore, reload this page to check whether it completed.', 'boat-backup' ),
					'fileRequired'  => __( 'Select the same .wpboat file to resume the upload.', 'boat-backup' ),
					'fileMismatch'  => __( 'The selected file has a different size. Select the original backup or cancel the upload.', 'boat-backup' ),
					'download'      => __( 'Download backup', 'boat-backup' ),
					'created'       => __( 'Created', 'boat-backup' ),
					'size'          => __( 'Size', 'boat-backup' ),
					'restore'       => __( 'Restore', 'boat-backup' ),
					'delete'        => __( 'Delete', 'boat-backup' ),
					'empty'         => __( 'No backups yet. Export your site or upload a .wpboat file to get started.', 'boat-backup' ),
					'deleteConfirm' => __( 'Permanently delete this backup file? This does not change the site.', 'boat-backup' ),
					'login'         => __( 'Sign in to the restored site', 'boat-backup' ),
					'password'      => __( 'Waiting for the backup password…', 'boat-backup' ),
					'mismatch'      => __( 'The passwords do not match.', 'boat-backup' ),
					'labelPrompt'   => __( 'Backup label (leave empty to remove):', 'boat-backup' ),
					'editLabel'     => __( 'Label', 'boat-backup' ),
					'ackFiles'      => __( 'I want to replace the matching files on this site.', 'boat-backup' ),
					'ackDatabase'   => __( 'I have the source administrator credentials and want to replace this site.', 'boat-backup' ),
					/* translators: %d: find and replace row number. */
					'removeRow'     => __( 'Remove find and replace row %d', 'boat-backup' ),
					'compatibility' => __( 'Native SQL definitions are adapted for compatibility. Aria becomes MyISAM; foreign-key constraints and selected table options are removed. Engine fallback is automatic when applicable.', 'boat-backup' ),
				),
			)
		);
	}

	/**
	 * Render.
	 *
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$error = '';
		$path  = '';
		try {
			$path = Plugin::engine()->storage->root();
		} catch ( Throwable $exception ) {
			$error = $exception->getMessage();
		}
		?>
		<div class="wrap boat-backup">
			<h1>Boat Backup &amp; Migration</h1>
			<p class="boat-intro"><?php esc_html_e( 'Move your site with a local backup. Export here, then restore on the destination.', 'boat-backup' ); ?></p>
			<?php if ( $error ) : ?>
				<div class="notice notice-error inline"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>
			<noscript><div class="notice notice-warning inline"><p><?php esc_html_e( 'Enable JavaScript to run and monitor migrations.', 'boat-backup' ); ?></p></div></noscript>
			<div id="boat-error" class="notice notice-error inline" role="alert" hidden><p></p></div>
			<section id="boat-operation" class="boat-operation" aria-labelledby="boat-operation-title" hidden>
				<h2 id="boat-operation-title"><?php esc_html_e( 'Current operation', 'boat-backup' ); ?></h2>
				<p id="boat-status" role="status" aria-live="polite"></p>
				<div class="boat-progress"><progress id="boat-progress" max="100" value="0" aria-describedby="boat-status"></progress><span id="boat-percent">0%</span></div>
				<div class="boat-actions">
					<button type="button" class="button" id="boat-resume"><?php esc_html_e( 'Resume operation', 'boat-backup' ); ?></button>
					<button type="button" class="button" id="boat-pause"><?php esc_html_e( 'Pause', 'boat-backup' ); ?></button>
					<button type="button" class="button" id="boat-cancel"><?php esc_html_e( 'Cancel operation', 'boat-backup' ); ?></button>
					<a class="button button-primary" id="boat-result" hidden></a>
				</div>
				<div id="boat-done" hidden>
					<p><?php esc_html_e( 'Open the Permalinks settings and save them once so the server rewrite rules match the restored site.', 'boat-backup' ); ?> <a id="boat-permalinks" href="#"><?php esc_html_e( 'Permalinks settings', 'boat-backup' ); ?></a></p>
				</div>
				<details id="boat-database-report" hidden>
					<summary><?php esc_html_e( 'Database compatibility', 'boat-backup' ); ?></summary>
					<p id="boat-engine-summary"></p>
					<ul id="boat-database-tables" class="ul-disc"></ul>
				</details>
				<form id="boat-unlock" hidden>
					<p><label for="boat-password"><?php esc_html_e( 'This backup is password protected. Enter its password to continue.', 'boat-backup' ); ?></label></p>
					<p><input type="password" id="boat-password" class="regular-text" autocomplete="current-password" maxlength="1024" required> <button class="button button-primary" type="submit"><?php esc_html_e( 'Unlock backup', 'boat-backup' ); ?></button></p>
				</form>
				<form id="boat-confirm" hidden>
					<p data-boat-database><?php esc_html_e( 'Database compatibility adjustments are automatic. Source foreign-key constraints are omitted and Aria tables are restored as MyISAM.', 'boat-backup' ); ?></p>
					<p data-boat-database><strong><?php esc_html_e( 'Restoring replaces the destination database and users.', 'boat-backup' ); ?></strong></p>
					<p data-boat-files hidden><strong><?php esc_html_e( 'This backup contains no database. Only files are restored; this site keeps its database and users.', 'boat-backup' ); ?></strong></p>
					<p><?php esc_html_e( 'Each plugin, theme, media folder and other item in the backup replaces the item with the same name on this site. Items that exist only on this site are kept.', 'boat-backup' ); ?></p>
					<p><?php esc_html_e( 'The destination address and server configuration stay the same. Previous files and tables are retained privately for recovery.', 'boat-backup' ); ?> <span data-boat-database><?php esc_html_e( 'You will need an administrator login from the source site.', 'boat-backup' ); ?></span></p>
					<p><label><input type="checkbox" id="boat-acknowledge" required> <span id="boat-acknowledge-text"><?php esc_html_e( 'I have the source administrator credentials and want to replace this site.', 'boat-backup' ); ?></span></label></p>
					<button class="button button-primary" type="submit"><?php esc_html_e( 'Restore this site', 'boat-backup' ); ?></button>
				</form>
			</section>
			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Migration tools', 'boat-backup' ); ?>">
				<?php
				foreach ( array(
					'export'  => __( 'Export', 'boat-backup' ),
					'import'  => __( 'Import', 'boat-backup' ),
					'backups' => __( 'Backups', 'boat-backup' ),
				) as $key => $label ) :
					?>
					<a href="#boat-<?php echo esc_attr( $key ); ?>" class="nav-tab<?php echo 'export' === $key ? ' nav-tab-active' : ''; ?>" data-boat-tab="<?php echo esc_attr( $key ); ?>" <?php echo 'export' === $key ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<section id="boat-export" class="boat-panel" aria-labelledby="boat-export-title">
				<h2 id="boat-export-title"><?php esc_html_e( 'Create a site backup', 'boat-backup' ); ?></h2>
				<p><?php esc_html_e( 'Includes your database and everything in wp-content: plugins, themes, media, must-use plugins, languages and other folders.', 'boat-backup' ); ?></p>
				<p><?php esc_html_e( 'Pause site edits, orders and other writes during export for a consistent backup. Processing continues on the server if you close this page; if your host blocks that, this page continues the work and must stay open.', 'boat-backup' ); ?></p>
				<form id="boat-export-form">
					<fieldset class="boat-replace">
						<legend><?php esc_html_e( 'Find and replace in the database', 'boat-backup' ); ?></legend>
						<p class="description"><?php esc_html_e( 'Applied when this backup is restored, after the automatic address changes. Rows with an empty field are ignored.', 'boat-backup' ); ?></p>
						<ol id="boat-replace-rows"></ol>
						<p><button type="button" class="button" id="boat-replace-add"><?php esc_html_e( 'Add find and replace', 'boat-backup' ); ?></button></p>
						<template id="boat-replace-row">
							<li class="boat-replace-row">
								<label><span><?php esc_html_e( 'Find', 'boat-backup' ); ?></span> <input type="text" class="regular-text" data-boat-find maxlength="4096"></label>
								<label><span><?php esc_html_e( 'Replace with', 'boat-backup' ); ?></span> <input type="text" class="regular-text" data-boat-replace maxlength="4096"></label>
								<button type="button" class="button-link button-link-delete" data-boat-replace-remove><?php esc_html_e( 'Remove', 'boat-backup' ); ?></button>
							</li>
						</template>
					</fieldset>
					<details class="boat-advanced">
						<summary><?php esc_html_e( 'Advanced options', 'boat-backup' ); ?></summary>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Advanced options', 'boat-backup' ); ?></legend>
							<?php
							foreach ( array(
								'no_spam_comments'    => __( 'Do not export spam comments', 'boat-backup' ),
								'no_post_revisions'   => __( 'Do not export post revisions', 'boat-backup' ),
								'no_media'            => __( 'Do not export media library (files)', 'boat-backup' ),
								'no_themes'           => __( 'Do not export themes (files)', 'boat-backup' ),
								'no_inactive_themes'  => __( 'Do not export inactive themes (files)', 'boat-backup' ),
								'no_muplugins'        => __( 'Do not export must-use plugins (files)', 'boat-backup' ),
								'no_plugins'          => __( 'Do not export plugins (files)', 'boat-backup' ),
								'no_inactive_plugins' => __( 'Do not export inactive plugins (files)', 'boat-backup' ),
								'no_cache'            => __( 'Do not export cache (files)', 'boat-backup' ),
								'no_database'         => __( 'Do not export database (SQL)', 'boat-backup' ),
								'no_email_replace'    => __( 'Do not replace email domain (SQL)', 'boat-backup' ),
							) as $flag => $label ) :
								?>
								<label class="boat-option"><input type="checkbox" name="<?php echo esc_attr( $flag ); ?>" data-boat-option> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
							<label class="boat-option"><input type="checkbox" id="boat-protect" aria-controls="boat-protect-fields" aria-expanded="false" <?php disabled( ! Crypto::available() ); ?>> <?php esc_html_e( 'Protect this backup with a password', 'boat-backup' ); ?></label>
							<?php if ( ! Crypto::available() ) : ?>
								<p class="description"><?php esc_html_e( 'Password protection requires the PHP OpenSSL extension with AES-256-GCM.', 'boat-backup' ); ?></p>
							<?php endif; ?>
							<div id="boat-protect-fields" class="boat-protect" hidden>
								<p><label for="boat-password-new"><?php esc_html_e( 'Password', 'boat-backup' ); ?></label><br><input type="password" id="boat-password-new" class="regular-text" autocomplete="new-password" maxlength="1024"></p>
								<p><label for="boat-password-repeat"><?php esc_html_e( 'Repeat password', 'boat-backup' ); ?></label><br><input type="password" id="boat-password-repeat" class="regular-text" autocomplete="new-password" maxlength="1024"></p>
								<p class="description"><?php esc_html_e( 'The database and file contents are encrypted. File names and site addresses stay readable. A lost password cannot be recovered.', 'boat-backup' ); ?></p>
							</div>
						</fieldset>
					</details>
					<details><summary><?php esc_html_e( 'Always excluded', 'boat-backup' ); ?></summary><ul class="ul-disc">
						<li><?php esc_html_e( 'WordPress core, wp-config.php and server configuration.', 'boat-backup' ); ?></li>
						<li><?php esc_html_e( 'Backup folders of migration plugins, WordPress upgrade folders and version-control metadata.', 'boat-backup' ); ?></li>
						<li><?php esc_html_e( 'Boat Backup & Migration itself and host drop-ins such as object-cache.php and advanced-cache.php.', 'boat-backup' ); ?></li>
					</ul></details>
					<p><button class="button button-primary" type="submit" id="boat-export-start" data-boat-start <?php disabled( (bool) $error ); ?>><?php esc_html_e( 'Export site', 'boat-backup' ); ?></button></p>
				</form>
			</section>
			<section id="boat-import" class="boat-panel" aria-labelledby="boat-import-title" hidden>
				<h2 id="boat-import-title"><?php esc_html_e( 'Import a backup', 'boat-backup' ); ?></h2>
				<p><?php esc_html_e( 'Choose a .wpboat backup created by this version of Boat Backup & Migration. The package is verified before anything on this site changes.', 'boat-backup' ); ?></p>
				<form id="boat-upload-form">
					<div id="boat-drop" class="boat-drop">
						<p><label for="boat-file"><?php esc_html_e( 'Drag a .wpboat backup here, or choose a file', 'boat-backup' ); ?></label></p>
						<input type="file" id="boat-file" accept=".wpboat" required aria-describedby="boat-upload-help" <?php disabled( (bool) $error ); ?>>
					</div>
					<p id="boat-upload-help" class="description"><?php esc_html_e( 'Uploads use small parts. If interrupted, select the same file and resume. Only .wpboat packages are accepted.', 'boat-backup' ); ?></p>
					<p><button class="button button-primary" type="submit" id="boat-upload-start" <?php disabled( (bool) $error ); ?>><?php esc_html_e( 'Upload and verify', 'boat-backup' ); ?></button></p>
				</form>
			</section>
			<section id="boat-backups" class="boat-panel" aria-labelledby="boat-backups-title" hidden>
				<h2 id="boat-backups-title"><?php esc_html_e( 'Local backups', 'boat-backup' ); ?></h2>
				<p><?php esc_html_e( 'Backups can contain passwords, personal data and executable plugin files. Only restore packages from a source you trust.', 'boat-backup' ); ?></p>
				<table class="widefat striped boat-backups"><thead><tr>
					<th id="boat-backup-name" scope="col"><?php esc_html_e( 'Backup', 'boat-backup' ); ?></th>
					<th id="boat-backup-created" scope="col"><?php esc_html_e( 'Created', 'boat-backup' ); ?></th>
					<th id="boat-backup-size" scope="col"><?php esc_html_e( 'Size', 'boat-backup' ); ?></th>
					<th id="boat-backup-actions" scope="col"><?php esc_html_e( 'Actions', 'boat-backup' ); ?></th>
				</tr></thead><tbody id="boat-backup-rows"><tr><td colspan="4"><?php esc_html_e( 'Loading backups…', 'boat-backup' ); ?></td></tr></tbody></table>
			</section>
			<?php if ( $path ) : ?>
				<p class="description boat-storage"><?php esc_html_e( 'Private storage:', 'boat-backup' ); ?> <code><?php echo esc_html( $path ); ?></code><br><?php esc_html_e( 'No commercial size quota. Available disk space and hosting resources still apply. Backups are preserved when the plugin is removed.', 'boat-backup' ); ?></p>
			<?php endif; ?>
			<p class="description">Wocody · <?php echo esc_html( BOAT_BACKUP_VERSION ); ?></p>
		</div>
		<?php
	}
}
