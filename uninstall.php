<?php
/**
 * Preserve backups and recovery material; remove only safe disposable state.
 *
 * @package Wocody\BoatBackup
 */

namespace Wocody\BoatBackup;

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'BOAT_BACKUP_PATH' ) ) {
	define( 'BOAT_BACKUP_PATH', __DIR__ );
}
if ( ! defined( 'BOAT_BACKUP_FILE' ) ) {
	define( 'BOAT_BACKUP_FILE', __DIR__ . '/boat-backup.php' );
}

require_once __DIR__ . '/includes/class-storage.php';
require_once __DIR__ . '/includes/class-sql.php';
require_once __DIR__ . '/includes/class-database.php';
require_once __DIR__ . '/includes/class-content.php';
require_once __DIR__ . '/includes/class-archive.php';
require_once __DIR__ . '/includes/class-rewriter.php';
require_once __DIR__ . '/includes/class-engine.php';

try {
	$boat_backup_storage = new Storage();
	$boat_backup_engine  = new Engine( $boat_backup_storage );
	$boat_backup_current = $boat_backup_engine->current();
	if ( $boat_backup_current ) {
		$boat_backup_engine->cancel( $boat_backup_current['id'] );
	}
	foreach ( glob( $boat_backup_storage->root() . '/jobs/*/state.json' ) as $boat_backup_state ) {
		$boat_backup_job = $boat_backup_storage->read( $boat_backup_state );
		if ( 'export' === $boat_backup_job['kind'] || in_array( $boat_backup_job['phase'], array( 'cancelled' ), true ) ) {
			$boat_backup_storage->remove_tree( dirname( $boat_backup_state ) );
		}
	}
} catch ( \Throwable $boat_backup_error ) {
	// Fail safely: never delete backups, an unresolved journal or recovery tables.
	return;
}

delete_option( 'boat_backup_settings' );
