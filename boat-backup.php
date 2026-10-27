<?php
/**
 * Plugin Name: Boat Backup & Migration
 * Description: Export, import and restore WordPress sites with local backups and no commercial size limit.
 * Version: 0.3.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Wocody
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: boat-backup
 * Domain Path: /languages
 *
 * @package Wocody\BoatBackup
 */

namespace Wocody\BoatBackup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BOAT_BACKUP_VERSION', '0.3.0' );
define( 'BOAT_BACKUP_FILE', __FILE__ );
define( 'BOAT_BACKUP_PATH', __DIR__ );

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 === strpos( $class_name, $prefix ) ) {
			$name = substr( $class_name, strlen( $prefix ) );
			if ( preg_match( '/^[A-Za-z]+$/D', $name ) ) {
				$path = __DIR__ . '/includes/class-' . strtolower( $name ) . '.php';
				if ( is_file( $path ) ) {
					require_once $path;
				}
			}
		}
	}
);

Plugin::register();
