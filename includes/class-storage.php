<?php
/**
 * Private files, durable state and operation locking.
 *
 * Filesystem calls are intentional: all paths are confined to a verified private root.
 * phpcs:disable WordPress.WP.AlternativeFunctions
 *
 * @package Wocody\BoatBackup
 */

namespace Wocody\BoatBackup;

// Exception messages are raw JSON data; HTML output is escaped at its boundary.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

use RuntimeException;

/** Storage service for local migration operations. */
final class Storage {

	/** Protected fallback folder inside wp-content. */
	const FALLBACK = 'boat-backups';

	/**
	 * Internal service or storage path.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Whether the fallback folder inside wp-content is used.
	 *
	 * @var bool
	 */
	private $fallback = false;

	/**
	 * Construct.
	 *
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public function __construct() {
		$site                          = self::site();
		list( $path, $this->fallback ) = self::locate();
		if ( ! $this->fallback ) {
			// An explicit BOAT_BACKUP_STORAGE_PATH must satisfy the private-location rules.
			if ( ! is_string( $path ) || ! preg_match( '~^(?:/|[A-Za-z]:[/\\\\])~', $path ) ) {
				throw new RuntimeException( __( 'BOAT_BACKUP_STORAGE_PATH must be an absolute private directory.', 'boat-backup' ) );
			}
			$parent = realpath( dirname( $path ) );
			if ( false === $parent || is_link( $path ) ) {
				throw new RuntimeException( __( 'Set BOAT_BACKUP_STORAGE_PATH to a writable directory outside the public document root.', 'boat-backup' ) );
			}
			$path = is_dir( $path ) ? realpath( $path ) : $parent . '/' . basename( $path );
			if ( self::public_path( $path ) ) {
				throw new RuntimeException( __( 'Private storage is inside the public document root. Set BOAT_BACKUP_STORAGE_PATH outside it.', 'boat-backup' ) );
			}
		}
		$this->root = rtrim( $path, '/\\' );
		$this->mkdir( $this->root );
		if ( $this->fallback ) {
			$this->protect();
		}
		$marker = $this->root . '/site.json';
		// The wp-content folder always belongs to this installation, even when
		// wp-content was copied from another site.
		if ( ! $this->fallback && is_file( $marker ) && $this->read( $marker )['site'] !== $site ) {
			throw new RuntimeException( __( 'This storage directory belongs to another WordPress installation.', 'boat-backup' ) );
		}
		if ( ! is_file( $marker ) || ( $this->fallback && $this->read( $marker )['site'] !== $site ) ) {
			$this->write( $marker, array( 'site' => $site ) );
		}
		foreach ( array( 'jobs', 'backups' ) as $directory ) {
			$this->mkdir( $this->root . '/' . $directory );
		}
	}

	/**
	 * Installation identifier recorded in the storage folder.
	 *
	 * @return string Identifier.
	 */
	public static function site() {
		return substr( hash( 'sha256', realpath( ABSPATH ) ), 0, 12 );
	}

	/**
	 * Resolve the storage folder without creating it.
	 *
	 * Order: BOAT_BACKUP_STORAGE_PATH; a private sibling of the installation; when the
	 * host forbids that (open_basedir, read-only parent, installation inside a
	 * public subfolder), the protected wp-content/boat-backups folder.
	 *
	 * @return array{0: string, 1: bool} Path and whether it is the fallback.
	 */
	public static function locate() {
		if ( defined( 'BOAT_BACKUP_STORAGE_PATH' ) ) {
			return array( BOAT_BACKUP_STORAGE_PATH, false );
		}
		$default = dirname( rtrim( ABSPATH, '/\\' ) ) . '/boat-backup-private-' . self::site();
		if ( self::usable( $default ) ) {
			return array( $default, false );
		}
		return array( WP_CONTENT_DIR . '/' . self::FALLBACK, true );
	}

	/**
	 * Whether a private location outside the web root can be used.
	 *
	 * @param string $path Candidate folder.
	 * @return bool Usable.
	 */
	private static function usable( $path ) {
		$parent  = dirname( $path );
		$allowed = array_filter( explode( PATH_SEPARATOR, (string) ini_get( 'open_basedir' ) ) );
		if ( $allowed ) {
			$inside = false;
			foreach ( $allowed as $base ) {
				$inside = $inside || self::within( $parent, rtrim( $base, '/\\' ) );
			}
			if ( ! $inside ) {
				return false;
			}
		}
		$real = realpath( $parent );
		if ( false === $real || is_link( $path ) || self::public_path( $real . '/' . basename( $path ) ) ) {
			return false;
		}
		return is_dir( $path ) ? is_writable( $path ) : is_writable( $real );
	}

	/**
	 * Whether a path is inside the installation or the public document root.
	 *
	 * @param string $path Resolved path.
	 * @return bool Public.
	 */
	private static function public_path( $path ) {
		$public = ! empty( $_SERVER['DOCUMENT_ROOT'] ) ? realpath( sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ) ) : false;
		foreach ( array_filter( array( realpath( ABSPATH ), $public ) ) as $web_root ) {
			if ( self::within( $path, $web_root ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Deny web access to the fallback folder on Apache, LiteSpeed and IIS, and
	 * prevent directory listings elsewhere.
	 *
	 * @return void
	 * @throws RuntimeException When the protection files cannot be written.
	 */
	private function protect() {
		$files = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\nOptions -Indexes\n",
			'web.config' => "<configuration><system.webServer><authorization><deny users=\"*\" /></authorization><directoryBrowse enabled=\"false\" /></system.webServer></configuration>\n",
		);
		foreach ( $files as $name => $content ) {
			$path = $this->root . '/' . $name;
			if ( ! is_file( $path ) && strlen( $content ) !== file_put_contents( $path, $content ) ) {
				throw new RuntimeException( __( 'Unable to create private storage. Check directory permissions.', 'boat-backup' ) );
			}
		}
	}

	/**
	 * Within.
	 *
	 * @param mixed $path Path.
	 * @param mixed $root Root.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public static function within( $path, $root ) {
		$path = str_replace( '\\', '/', $path );
		$root = rtrim( str_replace( '\\', '/', $root ), '/' );
		return $path === $root || 0 === strpos( $path, $root . '/' );
	}

	/**
	 * Root.
	 *
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public function root() {
		return $this->root;
	}

	/**
	 * Mkdir.
	 *
	 * @param mixed $path Path.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public function mkdir( $path ) {
		if ( ! is_dir( $path ) && ! mkdir( $path, 0700, true ) && ! is_dir( $path ) ) {
			throw new RuntimeException( __( 'Unable to create private storage. Check directory permissions.', 'boat-backup' ) );
		}
		if ( ! is_writable( $path ) ) {
			throw new RuntimeException( __( 'Private storage is not writable.', 'boat-backup' ) );
		}
	}

	/**
	 * Write.
	 *
	 * @param mixed $path Path.
	 * @param array $value Value.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public function write( $path, array $value ) {
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
		$temp = $path . '.' . bin2hex( random_bytes( 6 ) ) . '.tmp';
		if ( false === $json || strlen( $json ) !== file_put_contents( $temp, $json, LOCK_EX ) || ! rename( $temp, $path ) ) {
			if ( is_file( $temp ) ) {
				unlink( $temp );
			}
			throw new RuntimeException( __( 'Unable to save migration state. Check free disk space.', 'boat-backup' ) );
		}
		chmod( $path, 0600 );
	}

	/**
	 * Read.
	 *
	 * @param mixed $path Path.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public function read( $path ) {
		if ( ! is_file( $path ) || filesize( $path ) > 16 * 1024 * 1024 ) {
			throw new RuntimeException( __( 'Migration state is missing or too large.', 'boat-backup' ) );
		}
		$value = json_decode( file_get_contents( $path ), true );
		if ( ! is_array( $value ) || JSON_ERROR_NONE !== json_last_error() ) {
			throw new RuntimeException( __( 'Migration state is invalid. Preserve the private directory for recovery.', 'boat-backup' ) );
		}
		return $value;
	}

	/**
	 * Job path.
	 *
	 * @param mixed $id Id.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public function job_path( $id ) {
		if ( ! is_string( $id ) || ! preg_match( '/^[a-f0-9]{32}$/D', $id ) ) {
			throw new RuntimeException( __( 'Invalid operation identifier.', 'boat-backup' ) );
		}
		return $this->root . '/jobs/' . $id;
	}

	/**
	 * Load.
	 *
	 * @param mixed $id Id.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public function load( $id ) {
		return $this->read( $this->job_path( $id ) . '/state.json' );
	}

	/**
	 * Save.
	 *
	 * @param array $job Job.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public function save( array $job ) {
		$job['updated'] = time();
		$this->write( $this->job_path( $job['id'] ) . '/state.json', $job );
	}

	/**
	 * Active.
	 *
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public function active() {
		$path = $this->root . '/active.json';
		return is_file( $path ) ? $this->read( $path )['id'] : null;
	}

	/**
	 * Set active.
	 *
	 * @param mixed $id Id.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public function set_active( $id ) {
		if ( null === $id ) {
			if ( is_file( $this->root . '/active.json' ) ) {
				unlink( $this->root . '/active.json' );
			}
		} else {
			$this->write( $this->root . '/active.json', array( 'id' => $id ) );
		}
	}

	/**
	 * The caller must keep the returned handle open until its mutation finishes.
	 *
	 * @param int $wait Seconds to wait for a running step to release the lock.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public function lock( $wait = 0 ) {
		$handle   = fopen( $this->root . '/operation.lock', 'c' );
		$deadline = microtime( true ) + $wait;
		while ( false !== $handle && ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			if ( microtime( true ) >= $deadline ) {
				fclose( $handle );
				$handle = false;
				break;
			}
			usleep( 100000 );
		}
		if ( false === $handle ) {
			throw new RuntimeException( __( 'Another migration request is running. Wait and retry.', 'boat-backup' ), 409 );
		}
		return $handle;
	}

	/**
	 * Backup path.
	 *
	 * @param mixed $name Name.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public function backup_path( $name ) {
		if ( ! is_string( $name ) || ! preg_match( '/^[a-zA-Z0-9_-]+\.wpboat$/D', $name ) ) {
			throw new RuntimeException( __( 'Invalid backup name.', 'boat-backup' ) );
		}
		$path = $this->root . '/backups/' . $name;
		if ( is_link( $path ) ) {
			throw new RuntimeException( __( 'Symbolic links are not supported.', 'boat-backup' ) );
		}
		return $path;
	}

	/**
	 * Backups.
	 *
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public function backups() {
		$result = array();
		$labels = $this->labels();
		foreach ( glob( $this->root . '/backups/*.wpboat' ) as $path ) {
			if ( ! is_link( $path ) ) {
				$result[] = array(
					'name'    => basename( $path ),
					'size'    => filesize( $path ),
					'created' => filemtime( $path ),
					'label'   => $labels[ basename( $path ) ] ?? '',
				);
			}
		}
		usort(
			$result,
			static function ( $a, $b ) {
				return $b['created'] <=> $a['created'];
			}
		);
		return $result;
	}

	/**
	 * Backup labels by file name. Callers that change them hold the lock.
	 *
	 * @return array<string,string> Labels.
	 */
	public function labels() {
		$path = $this->root . '/labels.json';
		return is_file( $path ) ? array_filter( $this->read( $path ), 'is_string' ) : array();
	}

	/**
	 * Set or clear the label of a backup.
	 *
	 * @param string $name Backup file name.
	 * @param string $label Label; empty removes it.
	 * @return void
	 * @throws RuntimeException When the backup does not exist.
	 */
	public function label( $name, $label ) {
		if ( '' !== $label && ! is_file( $this->backup_path( $name ) ) ) {
			throw new RuntimeException( __( 'Backup not found.', 'boat-backup' ) );
		}
		$labels = $this->labels();
		if ( '' === $label ) {
			unset( $labels[ $name ] );
		} else {
			$labels[ $name ] = $label;
		}
		$this->write( $this->root . '/labels.json', $labels );
	}

	/**
	 * Remove tree.
	 *
	 * @param mixed $path Path.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public function remove_tree( $path ) {
		if ( ! self::within( $path, $this->root . '/jobs' ) || $path === $this->root . '/jobs' ) {
			throw new RuntimeException( __( 'Refusing to delete an unowned directory.', 'boat-backup' ) );
		}
		if ( is_link( $path ) || is_file( $path ) ) {
			if ( ! unlink( $path ) ) {
				throw new RuntimeException( __( 'Unable to remove a temporary file.', 'boat-backup' ) );
			}
		} elseif ( is_dir( $path ) ) {
			foreach ( new \FilesystemIterator( $path, \FilesystemIterator::SKIP_DOTS ) as $entry ) {
				$this->remove_tree( $entry->getPathname() );
			}
			if ( ! rmdir( $path ) ) {
				throw new RuntimeException( __( 'Unable to remove a temporary directory.', 'boat-backup' ) );
			}
		}
	}
}
