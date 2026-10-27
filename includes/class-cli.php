<?php
/**
 * WP-CLI commands running the same durable jobs as the administration screen.
 *
 * @package Wocody\BoatBackup
 */

namespace Wocody\BoatBackup;

use Throwable;
use WP_CLI;

/**
 * Export, list and restore Boat Backup & Migration backups.
 */
final class Cli {

	/**
	 * Export this site to a .wpboat backup in private storage.
	 *
	 * ## OPTIONS
	 *
	 * [--exclude-spam-comments]
	 * : Do not export spam comments.
	 *
	 * [--exclude-post-revisions]
	 * : Do not export post revisions.
	 *
	 * [--exclude-media]
	 * : Do not export the media library files.
	 *
	 * [--exclude-themes]
	 * : Do not export themes.
	 *
	 * [--exclude-inactive-themes]
	 * : Do not export inactive themes.
	 *
	 * [--exclude-muplugins]
	 * : Do not export must-use plugins.
	 *
	 * [--exclude-plugins]
	 * : Do not export plugins.
	 *
	 * [--exclude-inactive-plugins]
	 * : Do not export inactive plugins.
	 *
	 * [--exclude-cache]
	 * : Do not export the cache folder.
	 *
	 * [--exclude-database]
	 * : Do not export the database.
	 *
	 * [--exclude-email-replace]
	 * : Do not replace the email domain on restore.
	 *
	 * [--password=<password>]
	 * : Protect the backup with a password.
	 *
	 * [--replace]
	 * : Treat positional arguments as find/replace pairs applied on restore.
	 *
	 * [<find-replace>...]
	 * : With --replace, pairs of text: find replace [find replace ...].
	 *
	 * ## EXAMPLES
	 *
	 *     wp boat-backup export --exclude-cache --exclude-spam-comments
	 *     wp boat-backup export --replace "Old Name" "New Name"
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function export( $args, $assoc_args ) {
		$options = array( 'replace' => array() );
		foreach ( Options::FLAGS as $flag ) {
			$name             = 'exclude-' . str_replace( array( 'no_', '_' ), array( '', '-' ), $flag );
			$options[ $flag ] = ! empty( $assoc_args[ $name ] );
		}
		if ( ! empty( $assoc_args['replace'] ) ) {
			if ( ! $args || 0 !== count( $args ) % 2 ) {
				WP_CLI::error( __( 'Provide find and replace values in pairs.', 'boat-backup' ) );
			}
			foreach ( array_chunk( $args, 2 ) as $pair ) {
				$options['replace'][] = array(
					'find'    => $pair[0],
					'replace' => $pair[1],
				);
			}
		} elseif ( $args ) {
			WP_CLI::error( __( 'Positional values require --replace.', 'boat-backup' ) );
		}
		if ( isset( $assoc_args['password'] ) ) {
			$options['password'] = (string) $assoc_args['password'];
		}
		$engine = $this->engine();
		try {
			$job = $engine->start( 'export', '', 0, $options );
		} catch ( Throwable $error ) {
			WP_CLI::error( $error->getMessage() );
		}
		$job = $this->run( $engine, $job, 'completed' );
		WP_CLI::success( __( 'Backup complete.', 'boat-backup' ) );
		/* translators: %s: backup file path. */
		WP_CLI::log( sprintf( __( 'Backup location: %s', 'boat-backup' ), $engine->storage->backup_path( $job['backup'] ) ) );
	}

	/**
	 * List backups in private storage.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * @subcommand list
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function list_( $args, $assoc_args ) {
		$rows = array();
		foreach ( $this->engine()->storage->backups() as $backup ) {
			$rows[] = array(
				'name'    => $backup['name'],
				'label'   => $backup['label'],
				'created' => gmdate( 'Y-m-d H:i:s', $backup['created'] ),
				'size'    => size_format( $backup['size'], 2 ),
			);
		}
		WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'name', 'label', 'created', 'size' ) );
	}

	/**
	 * Restore a backup, replacing this site's database and matching content.
	 *
	 * ## OPTIONS
	 *
	 * <backup>
	 * : A backup name from `wp boat-backup list`, or a path to a .wpboat file, which is copied into private storage first.
	 *
	 * [--password=<password>]
	 * : Password of a protected backup.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp boat-backup restore site-20261027-120000-abcdef12.wpboat --yes
	 *     wp boat-backup restore /tmp/site.wpboat
	 *
	 * @param array $args Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function restore( $args, $assoc_args ) {
		$engine = $this->engine();
		$name   = $args[0];
		if ( is_file( $name ) ) {
			$name = $this->copy( $engine, $name );
		}
		try {
			$job = $engine->start( 'restore', $name );
		} catch ( Throwable $error ) {
			WP_CLI::error( $error->getMessage() );
		}
		$job = $this->run( $engine, $job, 'ready', $assoc_args['password'] ?? null );
		WP_CLI::confirm( $job['files_only'] ? __( 'Replace the matching files on this site?', 'boat-backup' ) : __( 'Replace this site\'s database, users and matching content? You will need an administrator login from the source site.', 'boat-backup' ), $assoc_args );
		try {
			$job = $engine->commit( $job['id'] );
		} catch ( Throwable $error ) {
			WP_CLI::error( $error->getMessage() );
		}
		WP_CLI::success( __( 'Restore complete. Save the permalinks settings once to refresh server rewrite rules.', 'boat-backup' ) );
	}

	/**
	 * Set or remove the label of a backup.
	 *
	 * ## OPTIONS
	 *
	 * <backup>
	 * : Backup name from `wp boat-backup list`.
	 *
	 * [<label>]
	 * : New label; omit it to remove the label.
	 *
	 * @param array $args Positional arguments.
	 * @return void
	 */
	public function label( $args ) {
		$storage = $this->engine()->storage;
		$label   = mb_substr( sanitize_text_field( $args[1] ?? '' ), 0, 200 );
		try {
			$lock = $storage->lock( 10 );
			try {
				$storage->label( $args[0], $label );
			} finally {
				fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Releases the private operation lock.
			}
		} catch ( Throwable $error ) {
			WP_CLI::error( $error->getMessage() );
		}
		WP_CLI::success( '' === $label ? __( 'Label removed.', 'boat-backup' ) : __( 'Label saved.', 'boat-backup' ) );
	}

	/**
	 * Show the active operation.
	 *
	 * @return void
	 */
	public function status() {
		$job = $this->engine()->current();
		if ( ! $job ) {
			WP_CLI::log( __( 'No active operation.', 'boat-backup' ) );
			return;
		}
		WP_CLI::log( sprintf( '%s %s %s %d%%', $job['kind'], $job['phase'], $job['status'], $job['progress'] ) );
		if ( ! empty( $job['error'] ) ) {
			WP_CLI::warning( $job['error'] );
		}
	}

	/**
	 * Cancel the active operation without changing the site.
	 *
	 * @return void
	 */
	public function cancel() {
		$engine = $this->engine();
		$job    = $engine->current();
		if ( ! $job ) {
			WP_CLI::log( __( 'No active operation.', 'boat-backup' ) );
			return;
		}
		try {
			$engine->cancel( $job['id'] );
		} catch ( Throwable $error ) {
			WP_CLI::error( $error->getMessage() );
		}
		WP_CLI::success( __( 'Operation cancelled.', 'boat-backup' ) );
	}

	/**
	 * Engine.
	 *
	 * @return Engine Migration engine.
	 */
	private function engine() {
		try {
			return Plugin::engine();
		} catch ( Throwable $error ) {
			WP_CLI::error( $error->getMessage() );
		}
	}

	/**
	 * Advance a job until it reaches the target phase, printing phase changes.
	 *
	 * @param Engine $engine Engine.
	 * @param array  $job Job summary.
	 * @param string $target Phase that ends the loop.
	 * @param mixed  $password Password for a protected backup.
	 * @return array Final summary.
	 */
	private function run( Engine $engine, array $job, $target, $password = null ) {
		$phase = '';
		try {
			while ( $target !== $job['phase'] ) {
				if ( 'password' === $job['phase'] ) {
					if ( null === $password ) {
						WP_CLI::error( __( 'This backup is password protected. Run the command again with --password=<password>.', 'boat-backup' ) );
					}
					$job = $engine->unlock( $job['id'], (string) $password );
					continue;
				}
				if ( in_array( $job['phase'], array( 'ready', 'completed', 'cancelled' ), true ) ) {
					WP_CLI::error( __( 'The operation stopped before finishing.', 'boat-backup' ) );
				}
				if ( $job['phase'] !== $phase ) {
					$phase = $job['phase'];
					WP_CLI::log( sprintf( '%s… %d%%', $phase, $job['progress'] ) );
				}
				$job = $engine->step( $job['id'] );
			}
		} catch ( Throwable $error ) {
			/* translators: %s: error message. */
			WP_CLI::error( sprintf( __( '%s Run `wp boat-backup status`, then retry the command or `wp boat-backup cancel`.', 'boat-backup' ), $error->getMessage() ) );
		}
		return $job;
	}

	/**
	 * Copy an external package into private storage under a fresh name.
	 *
	 * @param Engine $engine Engine.
	 * @param string $path Source path.
	 * @return string Stored backup name.
	 */
	private function copy( Engine $engine, $path ) {
		if ( '.wpboat' !== strtolower( substr( $path, -7 ) ) ) {
			WP_CLI::error( __( 'Select a .wpboat backup file.', 'boat-backup' ) );
		}
		$name = 'imported-' . gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 4 ) ) . '.wpboat';
		$free = disk_free_space( $engine->storage->root() );
		if ( false === $free || $free < filesize( $path ) + 32 * 1024 * 1024 ) {
			WP_CLI::error( __( 'Insufficient private storage space. Free disk space and retry.', 'boat-backup' ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- CLI copy into private storage.
		if ( ! copy( $path, $engine->storage->backup_path( $name ) ) ) {
			WP_CLI::error( __( 'Unable to copy the backup into private storage.', 'boat-backup' ) );
		}
		return $name;
	}
}
