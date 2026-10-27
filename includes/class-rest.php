<?php
/**
 * Authenticated REST operations and streamed administrative downloads.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions
 *
 * @package Wocody\BoatBackup
 */

namespace Wocody\BoatBackup;

// Exception messages are raw JSON data; HTML output is escaped at its boundary.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

use RuntimeException;
use Throwable;
use WP_Error;
use WP_REST_Response;
use WP_REST_Server;

/** Rest service for local migration operations. */
final class Rest {

	const API = 'boat-backup/v1';

	/**
	 * Register.
	 *
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public static function register() {
		$routes = array(
			'/jobs'                                     => array( WP_REST_Server::CREATABLE, 'start' ),
			'/current'                                  => array( WP_REST_Server::READABLE, 'current' ),
			'/jobs/(?P<id>[a-f0-9]{32})'                => array( WP_REST_Server::READABLE, 'job' ),
			'/jobs/(?P<id>[a-f0-9]{32})/pause'          => array( WP_REST_Server::CREATABLE, 'pause' ),
			'/jobs/(?P<id>[a-f0-9]{32})/password'       => array( WP_REST_Server::CREATABLE, 'password' ),
			'/jobs/(?P<id>[a-f0-9]{32})/step'           => array( WP_REST_Server::CREATABLE, 'step' ),
			'/jobs/(?P<id>[a-f0-9]{32})/upload'         => array( WP_REST_Server::CREATABLE, 'upload' ),
			'/jobs/(?P<id>[a-f0-9]{32})/commit'         => array( WP_REST_Server::CREATABLE, 'commit' ),
			'/jobs/(?P<id>[a-f0-9]{32})/cancel'         => array( WP_REST_Server::CREATABLE, 'cancel' ),
			'/backups'                                  => array( WP_REST_Server::READABLE, 'backups' ),
			'/backups/(?P<name>[A-Za-z0-9_-]+\.wpboat)' => array( WP_REST_Server::DELETABLE, 'delete' ),
			'/backups/(?P<name>[A-Za-z0-9_-]+\.wpboat)/label' => array( WP_REST_Server::CREATABLE, 'label' ),
		);
		foreach ( $routes as $route => $handler ) {
			register_rest_route(
				self::API,
				$route,
				array(
					'methods'             => $handler[0],
					'permission_callback' => array( self::class, 'permission' ),
					'callback'            => static function ( $request ) use ( $handler ) {
						try {
							$result = self::dispatch( $handler[1], $request );
							$response = new WP_REST_Response( $result );
							$response->header( 'Cache-Control', 'no-store, private' );
							return $response;
						} catch ( Throwable $error ) {
							$status = 409 === $error->getCode() ? 409 : 400;
							return new WP_Error( 'boat_backup_operation_failed', $error->getMessage(), array( 'status' => $status ) );
						}
					},
				)
			);
		}
	}

	/**
	 * Permission.
	 *
	 * @param mixed $request Request.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public static function permission( $request ) {
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
			return new WP_Error( 'boat_backup_forbidden', __( 'Sign in as an administrator and reload this page before continuing.', 'boat-backup' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Dispatch.
	 *
	 * @param mixed $action Action.
	 * @param mixed $request Request.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	private static function dispatch( $action, $request ) {
		$engine = Plugin::engine();
		$id     = $request->get_param( 'id' );
		switch ( $action ) {
			case 'start':
				$result = $engine->start( $request->get_param( 'kind' ), $request->get_param( 'name' ) ?? '', $request->get_param( 'size' ) ?? 0, $request->get_param( 'options' ) ?? array(), true );
				$engine->kick( $result['id'] );
				$result = $engine->get( $result['id'] );
				break;
			case 'job':
				$result = $engine->get( $id );
				break;
			case 'pause':
				$result = $engine->pause( $id );
				break;
			case 'password':
				$engine->unlock( $id, $request->get_param( 'password' ) );
				$engine->kick( $id );
				$result = $engine->get( $id );
				break;
			case 'label':
				$label = $request->get_param( 'label' );
				$label = is_string( $label ) ? mb_substr( sanitize_text_field( $label ), 0, 200 ) : '';
				$lock  = $engine->storage->lock( 10 );
				try {
					$engine->storage->label( $request->get_param( 'name' ), $label );
				} finally {
					fclose( $lock );
				}
				return array( 'label' => $label );
			case 'current':
				// WordPress may emit an empty HTTP body for a bare null response.
				return array( 'job' => $engine->current() );
			case 'upload':
				$offset = $request->get_param( 'offset' );
				if ( ! is_string( $offset ) || ! preg_match( '/^\d{1,16}$/D', $offset ) ) {
					throw new RuntimeException( __( 'Invalid upload offset.', 'boat-backup' ) );
				}
				$result = $engine->upload( $id, (int) $offset, $request->get_body() );
				break;
			case 'step':
				$engine->step( $id, true );
				$engine->kick( $id );
				$result = $engine->get( $id );
				break;
			case 'commit':
				if ( true !== $request->get_param( 'confirm' ) ) {
					throw new RuntimeException( __( 'Confirm replacement of the destination site before restoring.', 'boat-backup' ) );
				}
				$result = $engine->commit( $id );
				break;
			case 'cancel':
				$result = $engine->cancel( $id );
				break;
			case 'backups':
				return array_map(
					static function ( $backup ) {
						$backup['download'] = self::download_url( $backup['name'] );
						return $backup;
					},
					$engine->storage->backups()
				);
			case 'delete':
				$lock = $engine->storage->lock( 10 );
				try {
					$current = $engine->current();
					$name    = $request->get_param( 'name' );
					if ( $current && $current['backup'] === $name ) {
						throw new RuntimeException( __( 'This backup is being used by the active operation.', 'boat-backup' ), 409 );
					}
					$path = $engine->storage->backup_path( $name );
					if ( ! is_file( $path ) || ! unlink( $path ) ) {
						throw new RuntimeException( __( 'Unable to delete this backup.', 'boat-backup' ) );
					}
					$engine->storage->label( $name, '' );
					return array( 'deleted' => true );
				} finally {
					fclose( $lock );
				}
			default:
				throw new RuntimeException( __( 'Unknown migration action.', 'boat-backup' ) );
		}
		if ( $result && 'completed' === $result['phase'] && 'export' === $result['kind'] ) {
			$result['download'] = self::download_url( $result['backup'] );
		}
		return $result;
	}

	/**
	 * Download url.
	 *
	 * @param mixed $name Name.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public static function download_url( $name ) {
		return add_query_arg(
			array(
				'action'   => 'boat_backup_download',
				'backup'   => $name,
				'_wpnonce' => wp_create_nonce( 'boat_backup_download_' . $name ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Download.
	 *
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public static function download() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Administrator access is required.', 'boat-backup' ), '', array( 'response' => 403 ) );
		}
		$name = isset( $_GET['backup'] ) && is_string( $_GET['backup'] ) ? sanitize_file_name( wp_unslash( $_GET['backup'] ) ) : '';
		check_admin_referer( 'boat_backup_download_' . $name );
		try {
			$path = Plugin::engine()->storage->backup_path( $name );
			if ( ! is_file( $path ) || ! is_readable( $path ) ) {
				throw new RuntimeException( __( 'Backup not found.', 'boat-backup' ) );
			}
			$handle = fopen( $path, 'rb' );
			if ( ! $handle ) {
				throw new RuntimeException( __( 'Unable to open the backup.', 'boat-backup' ) );
			}
			while ( ob_get_level() ) {
				ob_end_clean();
			}
			nocache_headers();
			header( 'Content-Type: application/octet-stream' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'Content-Disposition: attachment; filename="' . $name . '"' );
			header( 'Content-Length: ' . filesize( $path ) );
			while ( ! feof( $handle ) && ! connection_aborted() ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Authenticated binary attachment, never rendered as HTML.
				echo fread( $handle, 1024 * 1024 );
				flush();
			}
			fclose( $handle );
			exit;
		} catch ( Throwable $error ) {
			wp_die( esc_html( $error->getMessage() ), '', array( 'response' => 404 ) );
		}
	}
}
