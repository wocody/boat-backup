<?php
/**
 * Durable bounded jobs. Only commit changes the live content or live tables.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
 *
 * @package Wocody\BoatBackup
 */

namespace Wocody\BoatBackup;

// Exception messages are raw JSON data; HTML output is escaped at its boundary.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

use RuntimeException;
use Throwable;

/** Engine service for local migration operations. */
final class Engine {

	/** Phases a server runner may advance without an administrator. */
	const SERVER_PHASES = array( 'preflight', 'scan', 'database', 'database_file', 'files', 'finalize', 'validate', 'sql_validate', 'load' );

	/** Seconds a dispatched continuation may take to arrive before the browser drives. */
	const DISPATCH_GRACE = 15;

	/**
	 * Internal service or storage path.
	 *
	 * @var Storage
	 */
	public $storage;
	/**
	 * Internal service or storage path.
	 *
	 * @var Database
	 */
	private $database;
	/**
	 * Internal service or storage path.
	 *
	 * @var Content
	 */
	private $content;

	/**
	 * Construct.
	 *
	 * @param Storage $storage Storage.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public function __construct( Storage $storage ) {
		global $wpdb;
		$this->storage  = $storage;
		$this->database = new Database( $wpdb );
		$this->content  = new Content( $storage );
	}

	/**
	 * Start.
	 *
	 * @param mixed $kind Kind.
	 * @param mixed $name Name.
	 * @param mixed $size Size.
	 * @param mixed $options Export options; ignored by imports, which use the package's.
	 * @param bool  $server Continue on the server through loopback requests.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public function start( $kind, $name = '', $size = 0, $options = array(), $server = false ) {
		global $wpdb, $wp_version, $wp_db_version;
		$lock = $this->storage->lock();
		try {
			if ( $this->storage->active() ) {
				throw new RuntimeException( __( 'Finish or cancel the current operation before starting another.', 'boat-backup' ), 409 );
			}
			if ( ! in_array( $kind, array( 'export', 'import', 'restore' ), true ) ) {
				throw new RuntimeException( __( 'Unsupported migration operation.', 'boat-backup' ) );
			}
			if ( 'export' !== $kind ) {
				$this->content->preflight();
			}
			$password = 'export' === $kind && is_array( $options ) ? ( $options['password'] ?? '' ) : '';
			$options  = Options::normalize( 'export' === $kind ? $options : array() );
			$sealing  = '' === $password ? null : Crypto::create( $password );
			$id       = bin2hex( random_bytes( 16 ) );
			$base     = $this->storage->job_path( $id );
			$this->storage->mkdir( $base );
			$job = array(
				'id'                 => $id,
				'kind'               => $kind,
				'phase'              => 'preflight',
				'format_version'     => Archive::VERSION,
				'preflight_tables'   => $options['no_database'] ? array() : $this->database->tables(),
				'options'            => $options,
				'runner'             => array(
					'mode'  => $server ? 'server' : 'browser',
					'token' => bin2hex( random_bytes( 32 ) ),
				),
				'preflight_index'    => 0,
				'status'             => 'running',
				'created'            => time(),
				'index'              => 0,
				'bytes'              => 0,
				'table_index'        => 0,
				'inventory_bytes'    => 0,
				'file_records'       => 0,
				'file_cursor'        => 0,
				'file_offset'        => 0,
				'scan'               => array(
					array(
						'path'   => '',
						'offset' => 0,
					),
				),
				'database'           => array(),
				'sql_bytes'          => 0,
				'sql_offset'         => 0,
				'sql_archive_offset' => 0,
				'row_offset'         => 0,
				'moves'              => array(),
				'destination'        => array(
					'home'        => get_option( 'home' ),
					'siteurl'     => get_option( 'siteurl' ),
					'home_url'    => home_url(),
					'site_url'    => site_url(),
					'content'     => wp_normalize_path( WP_CONTENT_DIR ),
					'content_url' => content_url(),
					'login'       => wp_login_url(),
				),
			);
			if ( 'export' === $kind ) {
				$job['tables'] = $job['preflight_tables'];
				$job['skip']   = Content::skipped( $options );
				if ( $sealing ) {
					$job['encryption'] = $sealing[0];
					$job['key']        = bin2hex( $sealing[1] );
				}
				$job['source'] = array(
					'home'        => get_option( 'home' ),
					'siteurl'     => get_option( 'siteurl' ),
					'home_url'    => home_url(),
					'site_url'    => site_url(),
					'content'     => wp_normalize_path( WP_CONTENT_DIR ),
					'content_url' => content_url(),
					'prefix'      => $wpdb->prefix,
					'wp_version'  => $wp_version,
					'db_version'  => $wp_db_version,
				);
				$job['backup'] = 'site-' . gmdate( 'Ymd-His' ) . '-' . substr( $id, 0, 8 ) . '.wpboat';
			} elseif ( 'restore' === $kind ) {
				$path = $this->storage->backup_path( $name );
				if ( ! is_file( $path ) ) {
					throw new RuntimeException( __( 'Backup not found.', 'boat-backup' ) );
				}
				$job['backup'] = $name;
				$job['phase']  = 'preflight';
			} else {
				if ( ! is_int( $size ) || $size < 1 || ! is_string( $name ) || '.wpboat' !== strtolower( substr( $name, -7 ) ) ) {
					throw new RuntimeException( __( 'Select a .wpboat backup file.', 'boat-backup' ) );
				}
				$this->check_space( $size );
				$job['upload_size']   = $size;
				$job['upload_offset'] = 0;
				$job['phase']         = 'upload';
				$job['backup']        = 'uploaded-' . substr( $id, 0, 16 ) . '.wpboat';
			}
			$this->storage->save( $job );
			$this->storage->set_active( $id );
			return $this->summary( $job );
		} finally {
			fclose( $lock );
		}
	}

	/**
	 * Check space.
	 *
	 * @param mixed $bytes Bytes.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	private function check_space( $bytes ) {
		$free = disk_free_space( $this->storage->root() );
		if ( false === $free || $free < $bytes + 32 * 1024 * 1024 ) {
			throw new RuntimeException( __( 'Insufficient private storage space. Free disk space and retry.', 'boat-backup' ) );
		}
	}

	/**
	 * Upload.
	 *
	 * @param mixed $id Id.
	 * @param mixed $offset Offset.
	 * @param mixed $bytes Bytes.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public function upload( $id, $offset, $bytes ) {
		$lock = $this->storage->lock();
		try {
			$job = $this->owned( $id );
			if ( 'upload' !== $job['phase'] || ! is_int( $offset ) || $offset < 0 || '' === $bytes || strlen( $bytes ) > self::upload_limit() || $offset + strlen( $bytes ) > $job['upload_size'] ) {
				throw new RuntimeException( __( 'Invalid upload segment.', 'boat-backup' ) );
			}
			$path   = $this->storage->job_path( $id ) . '/upload.part';
			$handle = fopen( $path, 'c+b' );
			if ( ! $handle ) {
				throw new RuntimeException( __( 'Unable to open the uploaded backup.', 'boat-backup' ) );
			}
			try {
				if ( $offset < $job['upload_offset'] ) {
					if ( $offset + strlen( $bytes ) > $job['upload_offset'] || 0 !== fseek( $handle, $offset ) || fread( $handle, strlen( $bytes ) ) !== $bytes ) {
						throw new RuntimeException( __( 'A repeated upload segment does not match the original.', 'boat-backup' ) );
					}
					return $this->summary( $job );
				}
				if ( $offset !== $job['upload_offset'] || ! ftruncate( $handle, $offset ) || 0 !== fseek( $handle, $offset ) || strlen( $bytes ) !== fwrite( $handle, $bytes ) ) {
					throw new RuntimeException( __( 'Upload interrupted or out of order. Resume from the saved offset.', 'boat-backup' ) );
				}
			} finally {
				fclose( $handle );
			}
			$job['upload_offset'] += strlen( $bytes );
			$this->storage->save( $job );
			return $this->summary( $job );
		} finally {
			fclose( $lock );
		}
	}

	/**
	 * Upload limit.
	 *
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public static function upload_limit() {
		$post   = wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) );
		$upload = wp_convert_hr_to_bytes( ini_get( 'upload_max_filesize' ) );
		$limit  = min( 1024 * 1024, $post > 0 ? $post : PHP_INT_MAX, $upload > 0 ? $upload : PHP_INT_MAX );
		return max( 1, $limit - min( 16384, (int) ( $limit / 4 ) ) );
	}

	/**
	 * Owned.
	 *
	 * @param mixed $id Id.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	private function owned( $id ) {
		if ( $this->storage->active() !== $id ) {
			throw new RuntimeException( __( 'This is not the active migration operation.', 'boat-backup' ), 409 );
		}
		$job = $this->storage->load( $id );
		if ( Archive::VERSION !== ( $job['format_version'] ?? null ) ) {
			throw new RuntimeException( __( 'This operation uses an unsupported format. Cancel it and start a new operation.', 'boat-backup' ) );
		}
		return $job;
	}

	/**
	 * Step.
	 *
	 * @param mixed $id Id.
	 * @param bool  $resume Whether an administrator resumes a paused or failed operation.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \Throwable When an underlying operation cannot be recovered.
	 */
	public function step( $id, $resume = false ) {
		$lock = $this->storage->lock();
		try {
			$job = $this->owned( $id );
			if ( $resume ) {
				unset( $job['runner']['paused'] );
			}
			if ( 'applying' === $job['phase'] ) {
				$this->recover_job( $job );
			}
			if ( in_array( $job['phase'], array( 'ready', 'completed', 'cancelled', 'password' ), true ) ) {
				return $this->summary( $job );
			}
			$job['status'] = 'running';
			unset( $job['error'] );
			try {
				if ( 'preflight' === $job['phase'] ) {
					$this->preflight_step( $job );
				} elseif ( 'export' === $job['kind'] ) {
					$this->export_step( $job );
				} else {
					$this->import_step( $job );
				}
				if ( in_array( $job['phase'], array( 'ready', 'completed' ), true ) ) {
					// The key is only needed to read the package.
					unset( $job['key'] );
				}
				$this->storage->save( $job );
			} catch ( Throwable $error ) {
				// Reload the last durable checkpoint, not the partially advanced cursor.
				$job           = $this->storage->load( $id );
				$job['status'] = 'failed';
				$job['error']  = $error->getMessage();
				$this->storage->save( $job );
				throw $error;
			}
			return $this->summary( $job );
		} finally {
			fclose( $lock );
		}
	}

	/**
	 * Inspect one live table per request, before inventory or package extraction.
	 *
	 * @param array $job Operation state.
	 * @return void
	 * @throws RuntimeException When a live table is unsupported.
	 */
	private function preflight_step( array &$job ) {
		if ( 'export' === $job['kind'] ) {
			$i = $job['preflight_index'];
			if ( isset( $job['preflight_tables'][ $i ] ) ) {
				$job['database'][ $i ] = $this->database->describe( $job['preflight_tables'][ $i ], $i );
				++$job['preflight_index'];
				return;
			}
		}
		$job['phase'] = 'export' === $job['kind'] ? 'scan' : 'validate';
	}

	/**
	 * Export database.sql and content as bounded archive segments.
	 *
	 * @param array $job Durable operation.
	 * @return void
	 * @throws RuntimeException When storage or database operations fail.
	 */
	private function export_step( array &$job ) {
		$base = $this->storage->job_path( $job['id'] );
		if ( 'finalize' === $job['phase'] && is_file( $this->storage->backup_path( $job['backup'] ) ) ) {
			$job['phase']  = 'completed';
			$job['status'] = 'completed';
			$this->storage->save( $job );
			$this->storage->set_active( null );
			return;
		}
		if ( 'scan' === $job['phase'] ) {
			$this->content->scan( $job );
			if ( ! $job['scan'] ) {
				$job['phase'] = $job['options']['no_database'] ? 'files' : 'database';
			}
			return;
		}
		if ( 'database' === $job['phase'] ) {
			$this->database->export_step( $base . '/database.sql', $job );
			if ( 'files' === $job['phase'] ) {
				$job['phase'] = 'database_file';
			}
			return;
		}
		$archive = new Archive( $base . '/export.part', true );
		$started = microtime( true );
		$count   = 0;
		while ( microtime( true ) - $started < 2 && $count++ < 16 ) {
			if ( 'database_file' === $job['phase'] ) {
				$stream = fopen( $base . '/database.sql', 'rb' );
				if ( ! $stream || 0 !== fseek( $stream, $job['sql_archive_offset'] ) ) {
					throw new RuntimeException( __( 'Unable to read the SQL dump.', 'boat-backup' ) );
				}
				try {
					$bytes = fread( $stream, Archive::BLOCK );
				} finally {
					fclose( $stream );
				}
				if ( false === $bytes ) {
					throw new RuntimeException( __( 'Unable to read the SQL dump.', 'boat-backup' ) );
				}
				if ( '' === $bytes ) {
					$job['phase'] = 'files';
					continue;
				}
				$job['bytes']              += $archive->put(
					$job['index']++,
					array(
						'type'   => 'sql',
						'path'   => 'database.sql',
						'offset' => $job['sql_archive_offset'],
						'total'  => $job['sql_bytes'],
					),
					$bytes,
					$this->key( $job )
				);
				$job['sql_archive_offset'] += strlen( $bytes );
			} elseif ( 'files' === $job['phase'] ) {
				$record = $this->content->export_record( $job );
				if ( null === $record ) {
					$job['phase'] = 'finalize';
					break;
				}
				$job['bytes'] += $archive->put( $job['index']++, $record[0], $record[1], $this->key( $job ) );
			} else {
				break;
			}
		}
		if ( 'finalize' === $job['phase'] ) {
			$tables = array_map(
				static function ( $table ) {
					return array_intersect_key( $table, array_flip( array( 'table', 'engine', 'dump_engine' ) ) );
				},
				$job['database']
			);
			$archive->finish(
				( isset( $job['encryption'] ) ? array( 'encryption' => $job['encryption'] ) : array() ) + array(
					'format'         => 'boat-backup',
					'version'        => Archive::VERSION,
					'plugin_version' => BOAT_BACKUP_VERSION,
					'created'        => gmdate( 'c' ),
					'records'        => $job['index'],
					'bytes'          => $job['bytes'],
					'source'         => $job['source'],
					'options'        => $job['options'],
					'database'       => array(
						'bytes'  => $job['sql_bytes'],
						'tables' => $tables,
					),
				)
			);
		}
		$archive->close();
		if ( 'finalize' === $job['phase'] ) {
			$this->storage->save( $job );
			if ( ! rename( $base . '/export.part', $this->storage->backup_path( $job['backup'] ) ) ) {
				throw new RuntimeException( __( 'Unable to move the finished backup into storage.', 'boat-backup' ) );
			}
			$job['phase']  = 'completed';
			$job['status'] = 'completed';
			$this->storage->save( $job );
			$this->storage->set_active( null );
		}
	}

	/**
	 * Rewriter.
	 *
	 * @param array $job Job.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	private function rewriter( array $job ) {
		$map         = array();
		$options     = $job['manifest']['options'];
		$add         = static function ( $old, $replacement ) use ( &$map ) {
			if ( '' === $old || $old === $replacement ) {
				return;
			}
			$map[ $old ]                            = $replacement;
			$map[ urlencode( $old ) ]               = urlencode( $replacement );
			$map[ rawurlencode( $old ) ]            = rawurlencode( $replacement );
			$map[ str_replace( '/', '\\/', $old ) ] = str_replace( '/', '\\/', $replacement );
		};
		$source      = $job['manifest']['source'];
		$destination = $job['destination'];
		// Stored options and effective addresses (WP_HOME/WP_SITEURL) both map to
		// the destination's effective address, as do content paths and URLs.
		$pairs = array(
			array( $source['siteurl'], $destination['site_url'], false ),
			array( $source['site_url'], $destination['site_url'], false ),
			array( $source['home'], $destination['home_url'], false ),
			array( $source['home_url'], $destination['home_url'], false ),
			array( $source['content'], $destination['content'], true ),
			array( $source['content_url'], $destination['content_url'], false ),
		);
		foreach ( $pairs as list( $old, $new, $path ) ) {
			$add( $old, $new );
			if ( $path ) {
				$add( str_replace( '/', '\\', $old ), str_replace( '/', '\\', $new ) );
			} elseif ( $old !== $new ) {
				$host     = wp_parse_url( $old, PHP_URL_HOST );
				$new_host = wp_parse_url( $new, PHP_URL_HOST );
				if ( $host && $new_host && ! $options['no_email_replace'] ) {
					$map[ '@' . $host ] = '@' . $new_host;
				}
				$inversion = 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : 'www.' . $host;
				foreach ( array( $old, str_replace( '//' . $host, '//' . $inversion, $old ) ) as $variant ) {
					foreach ( array( 'http:', 'https:', '' ) as $scheme ) {
						$search  = preg_replace( '~^https?:~', $scheme, $variant );
						$replace = '' === $scheme ? preg_replace( '~^https?:~', '', $new ) : $new;
						$add( $search, $replace );
					}
				}
			}
		}
		// Administrator pairs from the export screen, applied in the same encodings.
		foreach ( $options['replace'] as $pair ) {
			$add( $pair['find'], $pair['replace'] );
		}
		return new Rewriter( $map );
	}

	/**
	 * Import step.
	 *
	 * @param array $job Job.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	private function import_step( array &$job ) {
		global $wpdb, $wp_db_version;
		$base = $this->storage->job_path( $job['id'] );
		$path = $this->storage->backup_path( $job['backup'] );
		if ( 'upload' === $job['phase'] ) {
			if ( $job['upload_offset'] !== $job['upload_size'] ) {
				throw new RuntimeException( __( 'The upload is incomplete. Select the same file to resume.', 'boat-backup' ) );
			}
			if ( ! is_file( $path ) && ! rename( $base . '/upload.part', $path ) ) {
				throw new RuntimeException( __( 'Unable to retain the uploaded backup.', 'boat-backup' ) );
			}
			$job['phase'] = 'preflight';
			return;
		}
		if ( 'sql_validate' === $job['phase'] || 'load' === $job['phase'] ) {
			$this->sql_step( $job, 'load' === $job['phase'] );
			return;
		}
		$archive = new Archive( $path );
		if ( ! isset( $job['manifest'] ) ) {
			$job['manifest'] = $archive->manifest();
			$tables          = $job['manifest']['database']['tables'];
			$seen            = array();
			if ( $job['manifest']['options']['no_database'] ) {
				$tables = array();
			}
			foreach ( $tables as $table ) {
				Database::identifier( $wpdb->prefix . ( $table['table'] ?? '' ) );
				if ( ! isset( $table['table'], $table['engine'], $table['dump_engine'] ) || ! is_string( $table['engine'] ) || ! is_string( $table['dump_engine'] ) || isset( $seen[ $table['table'] ] ) ) {
					throw new RuntimeException( __( 'The SQL dump table manifest is invalid.', 'boat-backup' ) );
				}
				$seen[ $table['table'] ] = true;
			}
			$required = array( 'options', 'users', 'usermeta', 'posts', 'postmeta', 'comments', 'commentmeta', 'terms', 'termmeta', 'term_taxonomy', 'term_relationships', 'links' );
			if ( $tables && array_diff( $required, array_keys( $seen ) ) ) {
				throw new RuntimeException( __( 'The backup is missing WordPress core tables.', 'boat-backup' ) );
			}
			$job['database']      = array_values( $tables );
			$job['sql_extracted'] = 0;
			$this->check_space( $job['manifest']['bytes'] * 2 );
			$this->storage->mkdir( $base . '/staging' );
		}
		if ( isset( $job['manifest']['encryption'] ) && ! isset( $job['key'] ) ) {
			$job['phase']  = 'password';
			$job['status'] = 'password';
			$archive->close();
			return;
		}
		$started = microtime( true );
		$count   = 0;
		while ( $job['index'] < $job['manifest']['records'] && $count++ < 16 && microtime( true ) - $started < 2 ) {
			list( $record, $bytes ) = $archive->record( $job['index'], $this->key( $job ) );
			if ( 'sql' === $record['type'] ) {
				if ( ! empty( $job['content_started'] ) || 'database.sql' !== ( $record['path'] ?? '' ) || ( $record['offset'] ?? null ) !== $job['sql_extracted'] || ( $record['total'] ?? null ) !== $job['manifest']['database']['bytes'] || '' === $bytes || $job['sql_extracted'] + strlen( $bytes ) > $record['total'] ) {
					throw new RuntimeException( __( 'The SQL dump segments are incomplete or out of order.', 'boat-backup' ) );
				}
				$stream = fopen( $base . '/database.sql', 'c+b' );
				if ( ! $stream ) {
					throw new RuntimeException( __( 'Unable to stage the SQL dump.', 'boat-backup' ) );
				}
				try {
					if ( ! ftruncate( $stream, $job['sql_extracted'] ) || 0 !== fseek( $stream, $job['sql_extracted'] ) || strlen( $bytes ) !== fwrite( $stream, $bytes ) ) {
						throw new RuntimeException( __( 'Unable to stage the SQL dump.', 'boat-backup' ) );
					}
				} finally {
					fclose( $stream );
				}
				$job['sql_extracted'] += strlen( $bytes );
			} else {
				if ( $job['sql_extracted'] !== $job['manifest']['database']['bytes'] ) {
					throw new RuntimeException( __( 'The SQL dump is incomplete.', 'boat-backup' ) );
				}
				if ( ! empty( $job['pending_file'] ) && ( 'file' !== $record['type'] || $record['path'] !== $job['pending_file'] ) ) {
					throw new RuntimeException( __( 'The package contains an incomplete file.', 'boat-backup' ) );
				}
				$job['content_started'] = true;
				$this->content->stage( $job, $job['index'], $record, $bytes );
				$job['pending_file'] = 'file' === $record['type'] && $record['offset'] + strlen( $bytes ) < $record['total'] ? $record['path'] : null;
			}
			$job['bytes'] += strlen( $bytes );
			++$job['index'];
			$this->storage->save( $job );
		}
		if ( $job['index'] === $job['manifest']['records'] ) {
			if ( ! empty( $job['pending_file'] ) || $job['bytes'] !== $job['manifest']['bytes'] || $job['sql_extracted'] !== $job['manifest']['database']['bytes'] ) {
				throw new RuntimeException( __( 'The package is incomplete or its total size is invalid.', 'boat-backup' ) );
			}
			if ( ! $job['database'] ) {
				// A files-only package keeps the destination database untouched.
				$job['phase']  = 'ready';
				$job['status'] = 'ready';
			} else {
				$job['phase']      = 'sql_validate';
				$job['sql_offset'] = 0;
				$job['sql_table']  = -1;
				$job['sql_state']  = 'none';
			}
		}
		$archive->close();
	}

	/**
	 * Validate native statements or load them with a write-ahead table replay cursor.
	 *
	 * @param array $job Durable state.
	 * @param bool  $load Whether statements may affect temporary tables.
	 * @return void
	 * @throws Throwable When SQL or host resources prevent progress.
	 * @throws RuntimeException When the dump stream is unavailable.
	 */
	private function sql_step( array &$job, $load ) {
		$stream = fopen( $this->storage->job_path( $job['id'] ) . '/database.sql', 'rb' );
		if ( ! $stream ) {
			throw new RuntimeException( __( 'Unable to read the SQL dump.', 'boat-backup' ) );
		}
		if ( $load && isset( $job['pending_table'] ) ) {
			$job['sql_offset'] = $job['sql_table_starts'][ $job['pending_table'] ];
		}
		if ( 0 !== fseek( $stream, $job['sql_offset'] ) ) {
			fclose( $stream );
			throw new RuntimeException( __( 'Unable to seek to the saved SQL statement.', 'boat-backup' ) );
		}
		$rewriter = $load ? $this->rewriter( $job ) : null;
		$mode     = $this->database->begin_session();
		$started  = microtime( true );
		$count    = 0;
		$finished = false;
		try {
			if ( $load ) {
				$this->database->query( 'START TRANSACTION' );
			}
			while ( $count++ < 200 && microtime( true ) - $started < 2 ) {
				$offset = ftell( $stream );
				$tokens = Sql::read( $stream, $this->database->statement_limit() );
				if ( null === $tokens ) {
					$finished = true;
					break;
				}
				$plan = Database::plan( $tokens, $job['database'] );
				if ( $load ) {
					if ( isset( $plan['table'] ) && ( ! isset( $job['pending_table'] ) || $job['pending_table'] !== $plan['table'] ) ) {
						$this->database->query( 'COMMIT' );
						$job['sql_offset']    = $offset;
						$job['pending_table'] = $plan['table'];
						$this->storage->save( $job );
					}
					$this->database->apply( $plan, $job, $rewriter );
				} else {
					$this->validate_order( $job, $plan, $offset );
				}
				$job['sql_offset'] = ftell( $stream );
			}
			if ( $load ) {
				$this->database->query( 'COMMIT' );
				unset( $job['pending_table'] );
			}
			if ( $finished ) {
				if ( $load ) {
					$options = $this->options_stage( $job );
					if ( ! isset( $job['source_plugins'] ) ) {
						$job['source_plugins'] = $this->database->active_plugins( $options );
						$this->storage->save( $job );
					}
					$this->database->prepare_options( $options, $job['destination'] );
					$job['phase']  = 'ready';
					$job['status'] = 'ready';
				} else {
					if ( 'committed' !== $job['sql_state'] || count( $job['database'] ) - 1 !== $job['sql_table'] ) {
						throw new RuntimeException( __( 'The SQL dump is missing a complete table definition or final commit.', 'boat-backup' ) );
					}
					$job['phase']      = 'load';
					$job['sql_offset'] = 0;
				}
			}
		} catch ( Throwable $error ) {
			if ( $load ) {
				$this->database->query( 'ROLLBACK' );
			}
			throw $error;
		} finally {
			fclose( $stream );
			$this->database->end_session( $mode );
		}
	}

	/**
	 * Validate the canonical DROP/CREATE/INSERT order of the native dump.
	 *
	 * @param array $job SQL validation state.
	 * @param array $plan Statement.
	 * @param int   $offset Byte position before the statement.
	 * @return void
	 * @throws RuntimeException When tables or statements are reordered.
	 */
	private function validate_order( array &$job, array $plan, $offset ) {
		$valid = false;
		if ( 'drop' === $plan['kind'] ) {
			$valid                                     = in_array( $job['sql_state'], array( 'none', 'committed' ), true ) && $job['sql_table'] + 1 === $plan['table'];
			$job['sql_table']                          = $plan['table'];
			$job['sql_state']                          = 'dropped';
			$job['sql_table_starts'][ $plan['table'] ] = $offset;
		} elseif ( 'create' === $plan['kind'] ) {
			$valid            = 'dropped' === $job['sql_state'] && $job['sql_table'] === $plan['table'];
			$job['sql_state'] = 'created';
		} elseif ( 'insert' === $plan['kind'] ) {
			$valid = 'rows' === $job['sql_state'] && $job['sql_table'] === $plan['table'];
		} elseif ( 'START TRANSACTION' === $plan['sql'] ) {
			$valid            = in_array( $job['sql_state'], array( 'created', 'committed' ), true );
			$job['sql_state'] = 'rows';
		} elseif ( 'COMMIT' === $plan['sql'] ) {
			$valid            = 'rows' === $job['sql_state'];
			$job['sql_state'] = 'committed';
		}
		if ( ! $valid ) {
			throw new RuntimeException( __( 'The SQL dump contains out-of-order table statements.', 'boat-backup' ) );
		}
	}

	/**
	 * Options stage.
	 *
	 * @param array $job Job.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	private function options_stage( array $job ) {
		foreach ( $job['database'] as $i => $table ) {
			if ( 'options' === $table['table'] ) {
				return $this->database->stage_name( $job['id'], $i );
			}
		}
		throw new RuntimeException( __( 'The WordPress options table is missing.', 'boat-backup' ) );
	}

	/**
	 * Final request: no further request needs the old session or old nonces.
	 *
	 * @param mixed $id Id.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 * @throws \Throwable When an underlying operation cannot be recovered.
	 */
	public function commit( $id ) {
		global $wpdb;
		$lock = $this->storage->lock( 10 );
		try {
			$job = $this->owned( $id );
			if ( 'ready' !== $job['phase'] ) {
				throw new RuntimeException( __( 'Validate and stage the entire backup before restoring.', 'boat-backup' ) );
			}
			$this->content->preflight();
			$job['old_tables']  = $this->database->tables();
			$job['old_plugins'] = $this->database->active_plugins( $wpdb->options );
			$job['phase']       = 'applying';
			$job['status']      = 'running';
			$job['moves']       = array();
			$this->storage->save( $job );
			$this->storage->write( $this->storage->root() . '/maintenance.json', array( 'id' => $id ) );
			try {
				$this->database->set_active_plugins( $wpdb->options, array( plugin_basename( BOAT_BACKUP_FILE ) ) );
				wp_cache_flush();
				$this->content->apply( $job );
				if ( ! $job['database'] ) {
					// Files-only packages keep the destination database and its plugin set.
					$job['applied'] = true;
					$this->storage->save( $job );
					$this->database->set_active_plugins( $wpdb->options, $this->installed( array_merge( array( plugin_basename( BOAT_BACKUP_FILE ) ), $job['old_plugins'] ) ) );
					$this->complete_restore( $job );
				} else {
					$restored = self::deactivations( $job['source_plugins'], is_ssl() || 'https' === wp_parse_url( $job['destination']['site_url'], PHP_URL_SCHEME ) );
					$this->database->set_active_plugins( $this->options_stage( $job ), $this->installed( array_merge( array( plugin_basename( BOAT_BACKUP_FILE ) ), $restored['active'] ) ) );
					// The complete table set is switched with one database statement.
					$this->database->swap( $job );
					$this->complete_restore( $job );
				}
			} catch ( Throwable $error ) {
				$this->recover_job( $job );
				if ( 'completed' !== $job['phase'] ) {
					$job['error']  = $error->getMessage();
					$job['status'] = 'failed';
					$this->storage->save( $job );
					throw $error;
				}
			}
			if ( $job['database'] && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
				// Source users replaced the destination's: the current session is gone.
				wp_clear_auth_cookie();
			}
			return $this->summary( $job );
		} finally {
			fclose( $lock );
		}
	}

	/**
	 * Keep only safe plugin paths whose main file exists after content apply.
	 *
	 * @param array $plugins Plugin paths relative to the plugins folder.
	 * @return array Unique installed paths.
	 */
	private function installed( array $plugins ) {
		$result = array();
		foreach ( $plugins as $plugin ) {
			if ( is_string( $plugin ) && preg_match( '~^[A-Za-z0-9_./-]+\.php$~D', $plugin ) && false === strpos( $plugin, '..' ) && is_file( WP_PLUGIN_DIR . '/' . $plugin ) ) {
				$result[] = $plugin;
			}
		}
		return array_values( array_unique( $result ) );
	}

	/**
	 * Plugins left inactive after a restore because they lock administrators
	 * out on a new address: forced-HTTPS plugins without HTTPS, login URL
	 * changers and CAPTCHA plugins bound to the source domain.
	 *
	 * A plugin matches when its main file has the same name and its folder
	 * contains the known folder name, so renamed copies are recognized.
	 *
	 * @param array $plugins Source active plugin paths.
	 * @param bool  $ssl Whether the destination is served over HTTPS.
	 * @return array{active: array, deactivated: array} Partitioned plugin paths.
	 */
	public static function deactivations( array $plugins, $ssl ) {
		$known = array( 'invisible-recaptcha/invisible-recaptcha.php', 'wps-hide-login/wps-hide-login.php', 'hide-my-wp/index.php', 'hide-my-wordpress/index.php', 'mycustomwidget/my_custom_widget.php', 'lockdown-wp-admin/lockdown-wp-admin.php', 'rename-wp-login/rename-wp-login.php' );
		if ( ! $ssl ) {
			$known = array_merge( $known, array( 'really-simple-ssl/rlrsssl-really-simple-ssl.php', 'wordpress-https/wordpress-https.php', 'wp-force-ssl/wp-force-ssl.php' ) );
		}
		$result = array(
			'active'      => array(),
			'deactivated' => array(),
		);
		foreach ( $plugins as $plugin ) {
			$match = false;
			foreach ( $known as $candidate ) {
				$match = $match || ( basename( $plugin ) === basename( $candidate ) && false !== strpos( dirname( $plugin ), dirname( $candidate ) ) );
			}
			$result[ $match ? 'deactivated' : 'active' ][] = $plugin;
		}
		return $result;
	}

	/**
	 * Complete restore.
	 *
	 * @param array $job Job.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	private function complete_restore( array &$job ) {
		$job['phase']  = 'completed';
		$job['status'] = 'completed';
		$this->storage->save( $job );
		$this->storage->set_active( null );
		$marker = $this->storage->root() . '/maintenance.json';
		if ( is_file( $marker ) ) {
			unlink( $marker );
		}
		wp_cache_flush();
	}

	/**
	 * Inspect database state to distinguish a lost COMMIT response from a failure.
	 *
	 * @param array $job Job.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	private function recover_job( array &$job ) {
		global $wpdb;
		if ( 'applying' !== $job['phase'] ) {
			return;
		}
		if ( ! $job['database'] ) {
			if ( ! empty( $job['applied'] ) ) {
				$this->database->set_active_plugins( $wpdb->options, $this->installed( array_merge( array( plugin_basename( BOAT_BACKUP_FILE ) ), $job['old_plugins'] ) ) );
				$this->complete_restore( $job );
				return;
			}
			$this->reverse( $job );
			return;
		}
		$old_count   = 0;
		$prior_count = 0;
		$stage_count = 0;
		$live_count  = 0;
		foreach ( $job['old_tables'] as $i => $suffix ) {
			$old_count   += (int) $this->database->exists( $this->database->stage_name( $job['id'], $i, true ) );
			$prior_count += (int) $this->database->exists( $wpdb->prefix . $suffix );
		}
		foreach ( $job['database'] as $i => $table ) {
			$stage_count += (int) $this->database->exists( $this->database->stage_name( $job['id'], $i ) );
			$live_count  += (int) $this->database->exists( $wpdb->prefix . $table['table'] );
		}
		if ( count( $job['old_tables'] ) === $old_count && 0 === $stage_count && count( $job['database'] ) === $live_count ) {
			$this->complete_restore( $job );
			return;
		}
		if ( 0 !== $old_count || count( $job['database'] ) !== $stage_count || count( $job['old_tables'] ) !== $prior_count ) {
			throw new RuntimeException( __( 'The table switch is incomplete. Preserve the private recovery directory and database; maintenance remains enabled for administrator recovery.', 'boat-backup' ) );
		}
		$this->reverse( $job );
	}

	/**
	 * Return the destination's previous files and plugin set, keeping the stage ready.
	 *
	 * @param array $job Operation state.
	 * @return void
	 */
	private function reverse( array &$job ) {
		global $wpdb;
		$this->content->rollback( $job );
		$this->database->set_active_plugins( $wpdb->options, $job['old_plugins'] );
		$job['moves']  = array();
		$job['phase']  = 'ready';
		$job['status'] = 'ready';
		$job['error']  = __( 'The interrupted restore was reversed. Review the site, then retry or cancel.', 'boat-backup' );
		$this->storage->save( $job );
		$marker = $this->storage->root() . '/maintenance.json';
		if ( is_file( $marker ) ) {
			unlink( $marker );
		}
		wp_cache_flush();
	}

	/**
	 * Called before WordPress loads theme functions on requests after a killed commit.
	 *
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public function recover_interrupted() {
		$marker = $this->storage->root() . '/maintenance.json';
		if ( ! is_file( $marker ) ) {
			return;
		}
		$lock = $this->storage->lock();
		try {
			$id  = $this->storage->read( $marker )['id'];
			$job = $this->storage->load( $id );
			$this->recover_job( $job );
			if ( 'completed' === $job['phase'] && is_file( $marker ) ) {
				unlink( $marker );
			}
		} finally {
			fclose( $lock );
		}
	}

	/**
	 * Cancel.
	 *
	 * @param mixed $id Id.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public function cancel( $id ) {
		$lock = $this->storage->lock( 10 );
		try {
			$job = $this->owned( $id );
			if ( 'applying' === $job['phase'] ) {
				$this->recover_job( $job );
			}
			if ( 'completed' === $job['phase'] ) {
				return $this->summary( $job );
			}
			$this->database->drop_stages( $job );
			$base = $this->storage->job_path( $id );
			foreach ( new \FilesystemIterator( $base, \FilesystemIterator::SKIP_DOTS ) as $entry ) {
				if ( 'state.json' !== $entry->getFilename() && 'recovery' !== $entry->getFilename() ) {
					$this->storage->remove_tree( $entry->getPathname() );
				}
			}
			unset( $job['key'] );
			$job['phase']  = 'cancelled';
			$job['status'] = 'cancelled';
			$this->storage->save( $job );
			$this->storage->set_active( null );
			return $this->summary( $job );
		} finally {
			fclose( $lock );
		}
	}

	/**
	 * Raw key of a password-protected job, if any.
	 *
	 * @param array $job Operation state.
	 * @return string|null Raw key.
	 */
	private function key( array $job ) {
		return isset( $job['key'] ) ? hex2bin( $job['key'] ) : null;
	}

	/**
	 * Verify the password of a protected package and continue its import.
	 *
	 * @param string $id Job identifier.
	 * @param mixed  $password Password.
	 * @return array Job summary.
	 * @throws RuntimeException When the job does not wait for a password or it is wrong.
	 */
	public function unlock( $id, $password ) {
		$lock = $this->storage->lock( 10 );
		try {
			$job = $this->owned( $id );
			if ( 'password' !== $job['phase'] ) {
				throw new RuntimeException( __( 'This operation is not waiting for a password.', 'boat-backup' ) );
			}
			$job['key']    = bin2hex( Crypto::unlock( $job['manifest']['encryption'], $password ) );
			$job['phase']  = 'validate';
			$job['status'] = 'running';
			$this->storage->save( $job );
			return $this->summary( $job );
		} finally {
			fclose( $lock );
		}
	}

	/**
	 * Dispatch a loopback request that continues the job on the server.
	 *
	 * A continuation that never arrives (blocked loopback, HTTP authentication)
	 * switches the job to browser driving, so progress never depends on it.
	 *
	 * @param string $id Job identifier.
	 * @return void
	 */
	public function kick( $id ) {
		$lock = $this->storage->lock( 5 );
		try {
			if ( $this->storage->active() !== $id ) {
				return;
			}
			$job    = $this->owned( $id );
			$runner = $job['runner'];
			if ( 'server' !== $runner['mode'] || ! empty( $runner['paused'] ) || 'running' !== $job['status'] || ! in_array( $job['phase'], self::SERVER_PHASES, true ) || ( $runner['until'] ?? 0 ) > time() ) {
				return;
			}
			if ( ( $runner['kicked'] ?? 0 ) > ( $runner['started'] ?? 0 ) ) {
				if ( time() - $runner['kicked'] <= self::DISPATCH_GRACE ) {
					return;
				}
				$job['runner']['mode'] = 'browser';
				$this->storage->save( $job );
				return;
			}
			$job['runner']['kicked'] = time();
			$this->storage->save( $job );
		} finally {
			fclose( $lock );
		}
		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'blocking'  => false,
				'timeout'   => 5,
				'cookies'   => array(),
				// Loopback to this same site; local certificates are often self-signed.
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'body'      => array(
					'action' => 'boat_backup_continue',
					'id'     => $id,
					'token'  => $runner['token'],
				),
			)
		);
	}

	/**
	 * Advance the job for one bounded request, then dispatch the next one.
	 *
	 * @param string $id Job identifier.
	 * @param string $token Per-job continuation secret.
	 * @param int    $budget Seconds of work in this request.
	 * @return void
	 * @throws RuntimeException When the request does not belong to the active job.
	 */
	public function serve( $id, $token, $budget ) {
		$lock = $this->storage->lock( 5 );
		try {
			$job = $this->owned( $id );
			if ( ! is_string( $token ) || ! hash_equals( $job['runner']['token'], $token ) ) {
				throw new RuntimeException( __( 'Invalid continuation request.', 'boat-backup' ), 403 );
			}
			if ( ( $job['runner']['until'] ?? 0 ) > time() ) {
				return;
			}
			$job['runner']['started'] = time();
			$job['runner']['until']   = time() + $budget + self::DISPATCH_GRACE;
			$this->storage->save( $job );
		} finally {
			fclose( $lock );
		}
		$started = microtime( true );
		try {
			while ( microtime( true ) - $started < $budget ) {
				$job = $this->storage->load( $id );
				if ( ! empty( $job['runner']['paused'] ) || 'running' !== $job['status'] || ! in_array( $job['phase'], self::SERVER_PHASES, true ) ) {
					break;
				}
				$this->step( $id );
			}
		} catch ( Throwable $error ) {
			// step() records failures in the job; contention simply ends this request.
			unset( $error );
		}
		try {
			$lock = $this->storage->lock( 10 );
			try {
				$job                    = $this->storage->load( $id );
				$job['runner']['until'] = 0;
				$this->storage->save( $job );
			} finally {
				fclose( $lock );
			}
			if ( $this->storage->active() === $id ) {
				$this->kick( $id );
			}
		} catch ( Throwable $error ) {
			// The lease expires by itself and the browser takes over.
			unset( $error );
		}
	}

	/**
	 * Stop server continuation after the current step.
	 *
	 * @param string $id Job identifier.
	 * @return array Job summary.
	 */
	public function pause( $id ) {
		$lock = $this->storage->lock( 10 );
		try {
			$job                     = $this->owned( $id );
			$job['runner']['paused'] = true;
			$this->storage->save( $job );
			return $this->summary( $job );
		} finally {
			fclose( $lock );
		}
	}

	/**
	 * Summary of any job, including one that is no longer active.
	 *
	 * @param string $id Job identifier.
	 * @return array Job summary.
	 */
	public function get( $id ) {
		return $this->summary( $this->storage->load( $id ) );
	}

	/**
	 * Current.
	 *
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public function current() {
		$id = $this->storage->active();
		return $id ? $this->summary( $this->storage->load( $id ) ) : null;
	}

	/**
	 * Summary.
	 *
	 * @param array $job Job.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public function summary( array $job ) {
		$progress = 0;
		if ( 'export' === $job['kind'] ) {
			if ( 'database' === $job['phase'] ) {
				$progress = 10 + 40 * $job['table_index'] / max( 1, count( $job['tables'] ) ); } elseif ( 'database_file' === $job['phase'] ) {
				$progress = 50 + 10 * $job['sql_archive_offset'] / max( 1, $job['sql_bytes'] ); } elseif ( in_array( $job['phase'], array( 'files', 'finalize' ), true ) ) {
					$progress = 60 + 39 * $job['file_cursor'] / max( 1, $job['inventory_bytes'] );
				}
		} elseif ( 'upload' === $job['phase'] ) {
			$progress = 20 * $job['upload_offset'] / $job['upload_size']; } elseif ( isset( $job['manifest'] ) ) {
			if ( in_array( $job['phase'], array( 'validate', 'password' ), true ) ) {
				$progress = 20 + 40 * $job['index'] / max( 1, $job['manifest']['records'] ); } elseif ( 'sql_validate' === $job['phase'] ) {
				$progress = 60 + 10 * $job['sql_offset'] / max( 1, $job['manifest']['database']['bytes'] ); } elseif ( 'load' === $job['phase'] ) {
					$progress = 70 + 25 * $job['sql_offset'] / max( 1, $job['manifest']['database']['bytes'] );
				}
			}
			if ( in_array( $job['phase'], array( 'ready', 'applying' ), true ) ) {
				$progress = 95; } elseif ( 'completed' === $job['phase'] ) {
						$progress = 100;
				}
				$result               = array_intersect_key( $job, array_flip( array( 'id', 'kind', 'phase', 'status', 'backup', 'error', 'upload_offset', 'upload_size' ) ) );
				$result['files_only'] = isset( $job['manifest'] ) && ! $job['database'];
				$runner               = $job['runner'];
				$dispatched           = ( $runner['kicked'] ?? 0 ) > ( $runner['started'] ?? 0 ) && time() - $runner['kicked'] <= self::DISPATCH_GRACE;
				$result['driver']     = 'server' === $runner['mode'] && empty( $runner['paused'] ) && ( ( $runner['until'] ?? 0 ) > time() || $dispatched ) ? 'server' : 'browser';
				$result['paused']     = ! empty( $runner['paused'] );
				$result['protected']  = isset( $job['encryption'] ) || isset( $job['manifest']['encryption'] );
				$result['database']   = array();
				foreach ( $job['database'] ?? array() as $table ) {
					$result['database'][] = array(
						'table'  => $table['table'],
						'source' => $table['engine'],
						'target' => $table['target_engine'] ?? $table['dump_engine'],
					);
				}
				$result['progress'] = (int) $progress;
				if ( 'completed' === $job['phase'] && 'export' !== $job['kind'] ) {
					$result['login']      = $job['destination']['login'];
					$result['permalinks'] = admin_url( 'options-permalink.php' );
				}
				return $result;
	}
}
