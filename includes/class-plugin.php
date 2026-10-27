<?php
/**
 * WordPress integration and runtime requirements.
 *
 * @package Wocody\BoatBackup
 */

namespace Wocody\BoatBackup;

// Exception messages are raw JSON data; HTML output is escaped at its boundary.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

use RuntimeException;
use Throwable;

/** Plugin service for local migration operations. */
final class Plugin {

	/**
	 * Register.
	 *
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public static function register() {
		add_action( 'init', array( self::class, 'translations' ) );
		add_action( 'plugins_loaded', array( self::class, 'recover' ), -1000 );
		add_action( 'admin_menu', array( Admin::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( Admin::class, 'assets' ) );
		add_action( 'admin_post_boat_backup_download', array( Rest::class, 'download' ) );
		// Server continuation arrives without cookies; a per-job secret authorizes it.
		add_action( 'wp_ajax_nopriv_boat_backup_continue', array( self::class, 'continue_job' ) );
		add_action( 'wp_ajax_boat_backup_continue', array( self::class, 'continue_job' ) );
		add_action( 'rest_api_init', array( Rest::class, 'register' ) );
		register_activation_hook( BOAT_BACKUP_FILE, array( self::class, 'activate' ) );
		register_deactivation_hook( BOAT_BACKUP_FILE, array( self::class, 'deactivate' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'boat-backup', Cli::class );
		}
	}

	/**
	 * Translations.
	 *
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public static function translations() {
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Bundled translations must also work before directory publication, including WordPress 6.0.
		load_plugin_textdomain( 'boat-backup', false, dirname( plugin_basename( BOAT_BACKUP_FILE ) ) . '/languages' );
	}

	/**
	 * Engine.
	 *
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public static function engine() {
		global $wpdb;
		if ( is_multisite() || PHP_INT_SIZE < 8 || ! extension_loaded( 'mysqli' ) || ! ( $wpdb->dbh instanceof \mysqli ) || ! class_exists( '\ZipArchive' ) ) {
			throw new RuntimeException( __( 'Boat Backup & Migration requires a single-site installation, 64-bit PHP, mysqli and the ZipArchive extension.', 'boat-backup' ) );
		}
		return new Engine( new Storage() );
	}

	/**
	 * Advance the active job for one bounded loopback request.
	 *
	 * The request only continues an operation an administrator started; it
	 * cannot start, confirm or cancel anything.
	 *
	 * @return void
	 */
	public static function continue_job() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Authorized by the per-job secret checked in Engine::serve().
		$id    = isset( $_POST['id'] ) && is_string( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		$token = isset( $_POST['token'] ) && is_string( $_POST['token'] ) ? sanitize_key( wp_unslash( $_POST['token'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		ignore_user_abort( true );
		$limit = (int) ini_get( 'max_execution_time' );
		if ( function_exists( 'set_time_limit' ) && $limit > 0 && $limit < 60 ) {
			set_time_limit( 60 );
			$limit = (int) ini_get( 'max_execution_time' );
		}
		$budget = 0 === $limit || $limit >= 40 ? 20 : max( 5, $limit - 15 );
		try {
			self::engine()->serve( $id, $token, $budget );
		} catch ( Throwable $error ) {
			status_header( 403 === $error->getCode() ? 403 : 409 );
		}
		exit;
	}

	/**
	 * Recover.
	 *
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \Throwable When command-line recovery cannot finish.
	 */
	public static function recover() {
		try {
			self::engine()->recover_interrupted();
		} catch ( Throwable $error ) {
			// Missing storage is reported on our admin screen. An existing maintenance
			// marker must fail closed, including when recovery cannot acquire its lock.
			$path = Storage::locate()[0];
			if ( is_string( $path ) && is_file( $path . '/maintenance.json' ) ) {
				if ( defined( 'WP_CLI' ) && WP_CLI ) {
					throw $error;
				}
				wp_die( esc_html__( 'A site restore is running or needs recovery. Retry shortly. If it persists, inspect the private Boat Backup & Migration recovery directory.', 'boat-backup' ), esc_html__( 'Restore in progress', 'boat-backup' ), array( 'response' => 503 ) );
			}
		}
	}

	/**
	 * Activate.
	 *
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public static function activate() {
		// Activation does not change the site's database or create a backup.
		try {
			self::engine();
		} catch ( Throwable $error ) {
			// The admin screen supplies the actionable requirement and remains usable.
			return;
		}
	}

	/**
	 * Deactivate.
	 *
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public static function deactivate() {
		try {
			$storage = new Storage();
			if ( ! $storage->active() ) {
				return;
			}
			$engine = new Engine( $storage );
			$job    = $engine->current();
			if ( $job ) {
				$engine->cancel( $job['id'] );
			}
		} catch ( Throwable $error ) {
			if ( ! isset( $storage ) ) {
				// A configuration error with no stored active operation must not trap
				// an otherwise inactive plugin in the dashboard.
				$path = Storage::locate()[0];
				if ( ! is_string( $path ) || ( ! is_file( $path . '/active.json' ) && ! is_file( $path . '/maintenance.json' ) ) ) {
					return;
				}
			}
			wp_die( esc_html__( 'The migration could not be stopped safely. Finish or recover the operation before deactivating the plugin.', 'boat-backup' ) );
		}
	}
}
