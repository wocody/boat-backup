<?php
/**
 * Native SQL dumps: server DDL, driver-escaped row bytes and compatibility transforms.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.RestrictedFunctions, Generic.CodeAnalysis.AssignmentInCondition, WordPress.DB.PreparedSQL, WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions
 * Native mysqli is intentional: wpdb's text filtering must not modify geometry or BLOB literals.
 *
 * @package Wocody\BoatBackup
 */

namespace Wocody\BoatBackup;

use RuntimeException;

/** Independent implementation of SQL dump export/import through WordPress's connection. */
final class Database {

	const DUMP_PREFIX = 'boat_backup_dump_';
	const KEY_PREFIX  = 'boat_backup_source_';

	/**
	 * WordPress connection.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Column metadata cached only for the current request.
	 *
	 * @var array
	 */
	private $columns = array();

	/**
	 * Current statement size allowed by this host.
	 *
	 * @var int|null
	 */
	private $limit;

	/**
	 * Destination collations cached for the current request.
	 *
	 * @var array|null
	 */
	private $collations;

	/**
	 * Bind to the existing native MySQL connection.
	 *
	 * @param \wpdb $db WordPress database object.
	 */
	public function __construct( $db ) {
		$this->db = $db;
	}

	/**
	 * Quote a literal identifier; embedded separators never qualify another database.
	 *
	 * @param string $name Identifier without a database qualifier.
	 * @return string Quoted identifier.
	 * @throws RuntimeException When empty, oversized or containing control bytes.
	 */
	public static function identifier( $name ) {
		if ( ! is_string( $name ) || ! preg_match( '/^[^\x00-\x1f\x7f]{1,64}$/uD', $name ) ) {
			throw new RuntimeException( __( 'Unsupported database identifier.', 'boat-backup' ) );
		}
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	/**
	 * Execute one statement without character-set filtering of binary literals.
	 *
	 * @param string $sql Native SQL.
	 * @return mixed Driver result.
	 * @throws RuntimeException When the server rejects the statement.
	 */
	public function query( $sql ) {
		try {
			$result = mysqli_query( $this->db->dbh, $sql, MYSQLI_STORE_RESULT );
			if ( false !== $result ) {
				return $result;
			}
			$code = mysqli_errno( $this->db->dbh );
		} catch ( \mysqli_sql_exception $error ) {
			$code = $error->getCode();
		}
		// SQL and row data must never appear in errors or logs.
		/* translators: %d: MySQL error number. */
		throw new RuntimeException( sprintf( __( 'The database rejected a migration statement (MySQL error %d). Check permissions and destination compatibility.', 'boat-backup' ), $code ), (int) $code );
	}

	/**
	 * Escape bytes through the same connection that executes the dump.
	 *
	 * @param string $value SQL value bytes.
	 * @return string Quoted SQL literal.
	 */
	public function literal( $value ) {
		return "'" . mysqli_real_escape_string( $this->db->dbh, $value ) . "'";
	}

	/**
	 * Set the dump's permissive session mode; never change global server settings.
	 *
	 * @return string Previous session mode, restored by the caller.
	 */
	public function begin_session() {
		$row = $this->query( 'SELECT @@SESSION.sql_mode AS mode' )->fetch_assoc();
		$this->query( "SET SESSION sql_mode = ''" );
		return $row['mode'];
	}

	/**
	 * Restore connection settings after a bounded migration request.
	 *
	 * @param string $mode Original mode.
	 * @return void
	 */
	public function end_session( $mode ) {
		$this->query( 'SET SESSION sql_mode = ' . $this->literal( $mode ) );
	}

	/**
	 * Resolve statement bounds from MySQL and available PHP memory.
	 *
	 * @return int Host limit in bytes.
	 */
	public function statement_limit() {
		if ( null === $this->limit ) {
			$row         = $this->query( 'SELECT @@max_allowed_packet AS packet' )->fetch_assoc();
			$this->limit = max( 1024, (int) $row['packet'] - 1024 );
			$memory      = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
			if ( $memory > 0 ) {
				$this->limit = min( $this->limit, max( 1024, (int) ( ( $memory - memory_get_usage( true ) - 8 * 1024 * 1024 ) / 6 ) ) );
			}
		}
		return $this->limit;
	}

	/**
	 * List the installation's tables, excluding retained migration material.
	 *
	 * @return array Prefix-free table names.
	 * @throws RuntimeException When the dump or database operation cannot complete.
	 */
	public function tables() {
		$result = $this->query( 'SHOW FULL TABLES LIKE ' . $this->literal( $this->db->esc_like( $this->db->prefix ) . '%' ) );
		$tables = array();
		while ( $row = $result->fetch_row() ) {
			if ( preg_match( '/^boat_backup_[a-f0-9]{16}_(?:old_\d+|new_\d+)$/D', $row[0] ) ) {
				continue;
			}
			if ( 'BASE TABLE' !== $row[1] ) {
				// Views are left out of the table dump.
				continue;
			}
			self::identifier( $row[0] );
			$tables[] = substr( $row[0], strlen( $this->db->prefix ) );
		}
		$result->free();
		sort( $tables );
		return $tables;
	}

	/**
	 * Read the native definition without a column-type or engine allowlist.
	 *
	 * @param string $suffix Prefix-free table name.
	 * @param int    $index Dump table index.
	 * @return array Native DDL and export cursors' metadata.
	 * @throws RuntimeException When the dump or database operation cannot complete.
	 */
	public function describe( $suffix, $index ) {
		$mode = $this->begin_session();
		try {
			$name = $this->db->prefix . $suffix;
			$row  = $this->query( 'SHOW CREATE TABLE ' . self::identifier( $name ) )->fetch_assoc();
			if ( empty( $row['Create Table'] ) ) {
				/* translators: %s: table name. */
				throw new RuntimeException( sprintf( __( 'Unable to read the native definition of table %s.', 'boat-backup' ), $name ) );
			}
			$tokens           = Sql::tokens( $row['Create Table'] );
			$tokens[2]['raw'] = self::identifier( self::DUMP_PREFIX . $index );
			$ddl              = self::compatible_ddl( Sql::render( $tokens ) );
			$keys             = $this->query( 'SHOW KEYS FROM ' . self::identifier( $name ) . " WHERE Key_name = 'PRIMARY'" );
			$primary          = array();
			while ( $key = $keys->fetch_assoc() ) {
				$primary[] = $key['Column_name'];
			}
			$keys->free();
			return array(
				'table'       => $suffix,
				'ddl'         => $ddl,
				'engine'      => self::ddl_engine( $row['Create Table'] ),
				'dump_engine' => self::ddl_engine( $ddl ),
				'columns'     => $this->table_columns( $name ),
				'primary'     => $primary,
			);
		} finally {
			$this->end_session( $mode );
		}
	}

	/**
	 * Read column metadata to preserve opaque values and omit generated values.
	 *
	 * @param string $table Table name.
	 * @return array Column metadata in table order.
	 */
	private function table_columns( $table ) {
		if ( ! isset( $this->columns[ $table ] ) ) {
			$columns = array();
			$result  = $this->query( 'SHOW FULL COLUMNS FROM ' . self::identifier( $table ) );
			while ( $column = $result->fetch_assoc() ) {
				$columns[] = array(
					'name'      => $column['Field'],
					'text'      => null !== $column['Collation'],
					'generated' => (bool) preg_match( '/\b(?:VIRTUAL|STORED) GENERATED\b/i', $column['Extra'] ),
					'bit'       => (bool) preg_match( '/^bit\b/i', $column['Type'] ),
				);
			}
			$result->free();
			$this->columns[ $table ] = $columns;
		}
		return $this->columns[ $table ];
	}

	/**
	 * Normalize table options and foreign references for portable dumps.
	 *
	 * @param string $ddl Native CREATE TABLE.
	 * @return string Adapted native DDL.
	 */
	public static function compatible_ddl( $ddl ) {
		$tokens  = Sql::tokens( $ddl );
		$offset  = 3;
		$fields  = Sql::group( $tokens, $offset );
		$fields  = array_filter(
			$fields,
			static function ( $field ) {
				$words = array_map(
					'strtoupper',
					array_column(
						array_filter(
							$field,
							static function ( $token ) {
								return 'word' === $token['kind'];
							}
						),
						'raw'
					)
				);
				return ! ( in_array( 'REFERENCES', $words, true ) && ( in_array( 'CONSTRAINT', $words, true ) || in_array( 'FOREIGN', $words, true ) ) );
			}
		);
		$ddl     = Sql::render( array_slice( $tokens, 0, 3 ) ) . ' (' . implode( ', ', array_map( array( Sql::class, 'render' ), $fields ) ) . ')';
		$options = array();
		while ( isset( $tokens[ $offset ] ) ) {
			$token = $tokens[ $offset++ ];
			$key   = strtoupper( $token['raw'] );
			if ( 'word' === $token['kind'] && '=' === ( $tokens[ $offset ]['raw'] ?? '' ) ) {
				$value = $tokens[ $offset + 1 ]['raw'] ?? '';
				if ( ( in_array( $key, array( 'TRANSACTIONAL', 'PAGE_CHECKSUM', 'TABLE_CHECKSUM' ), true ) && in_array( $value, array( '0', '1' ), true ) ) || ( 'ROW_FORMAT' === $key && in_array( strtoupper( $value ), array( 'PAGE', 'FIXED', 'DYNAMIC' ), true ) ) ) {
					$offset += 2;
					continue;
				}
				if ( 'TYPE' === $key && in_array( strtoupper( $value ), array( 'INNODB', 'MYISAM' ), true ) ) {
					$token['raw'] = 'ENGINE';
				}
				if ( 'ENGINE' === $key && 'ARIA' === strtoupper( $value ) ) {
					$tokens[ $offset + 1 ]['raw'] = 'MyISAM';
				}
			}
			$options[] = $token;
		}
		return $ddl . ' ' . Sql::render( $options );
	}

	/**
	 * Extract engine metadata without limiting server-supported engines.
	 *
	 * @param string $ddl Native DDL.
	 * @return string Engine name, when declared.
	 */
	public static function ddl_engine( $ddl ) {
		$tokens = Sql::tokens( $ddl );
		foreach ( $tokens as $i => $token ) {
			if ( 'word' === $token['kind'] && in_array( strtoupper( $token['raw'] ), array( 'ENGINE', 'TYPE' ), true ) && '=' === ( $tokens[ $i + 1 ]['raw'] ?? '' ) ) {
				return $tokens[ $i + 2 ]['raw'];
			}
		}
		return '';
	}

	/**
	 * Append a bounded batch to database.sql; retries truncate to the durable cursor.
	 *
	 * @param string $path Dump path.
	 * @param array  $job Durable export state.
	 * @return void
	 * @throws RuntimeException When the dump or database operation cannot complete.
	 */
	public function export_step( $path, array &$job ) {
		$stream = fopen( $path, 'c+b' );
		if ( ! $stream || ! ftruncate( $stream, $job['sql_bytes'] ) || 0 !== fseek( $stream, $job['sql_bytes'] ) ) {
			throw new RuntimeException( __( 'Unable to write the SQL dump. Check private storage and free disk space.', 'boat-backup' ) );
		}
		$mode    = $this->begin_session();
		$started = microtime( true );
		try {
			if ( 0 === $job['sql_bytes'] ) {
				$this->write( $stream, "-- Boat Backup & Migration native SQL dump\n" );
			}
			$i = $job['table_index'];
			if ( ! isset( $job['database'][ $i ] ) ) {
				$job['phase'] = 'files';
				return;
			}
			$table     = $job['database'][ $i ];
			$dump_name = self::identifier( self::DUMP_PREFIX . $i );
			if ( empty( $job['table_started'] ) ) {
				$this->write( $stream, "DROP TABLE IF EXISTS $dump_name;\n" . $table['ddl'] . ";\nSTART TRANSACTION;\n" );
				$job['table_started'] = true;
			}
			$source = self::identifier( $this->db->prefix . $table['table'] );
			$order  = $table['primary'] ? implode( ', ', array_map( array( self::class, 'identifier' ), $table['primary'] ) ) : '1';
			$select = array_map(
				static function ( $column ) {
					$name = self::identifier( $column['name'] );
					return empty( $column['bit'] ) ? $name : 'HEX(' . $name . ') AS ' . $name;
				},
				$table['columns']
			);
			$sql    = 'SELECT ' . implode( ',', $select ) . ' FROM ' . $source . $this->where( $table['table'], $job['options'] ) . ' ORDER BY ' . $order . ' LIMIT ' . (int) $job['row_offset'] . ',1000';
			try {
				$result = $this->query( $sql );
			} catch ( RuntimeException $error ) {
				if ( 1194 !== $error->getCode() ) {
					throw $error;
				}
				$this->query( 'REPAIR TABLE ' . $source );
				$result = $this->query( $sql );
			}
				$finished = false;
			try {
				while ( microtime( true ) - $started < 2 ) {
					$row = $result->fetch_assoc();
					if ( null === $row ) {
						$finished = true;
						break;
					}
					$names  = array();
					$values = array();
					foreach ( $table['columns'] as $column ) {
						if ( $column['generated'] ) {
							continue;
						}
						$value = $row[ $column['name'] ];
						if ( in_array( $table['table'], array( 'options', 'usermeta' ), true ) && in_array( $column['name'], array( 'option_name', 'meta_key' ), true ) && is_string( $value ) && 0 === strpos( $value, $this->db->prefix ) ) {
							$value = self::KEY_PREFIX . substr( $value, strlen( $this->db->prefix ) );
						}
						$names[]  = self::identifier( $column['name'] );
						$values[] = null === $value ? 'NULL' : ( empty( $column['bit'] ) ? $this->literal( $value ) : '0x' . $value );
					}
					$this->write( $stream, 'INSERT INTO ' . $dump_name . ' (' . implode( ',', $names ) . ') VALUES (' . implode( ',', $values ) . ");\n" );
					++$job['row_offset'];
					if ( 0 === $job['row_offset'] % 1000 ) {
						$this->write( $stream, "COMMIT;\nSTART TRANSACTION;\n" );
					}
				}
			} finally {
				$result->free();
			}
			if ( $finished ) {
				$this->write( $stream, "COMMIT;\n" );
				++$job['table_index'];
				$job['row_offset']    = 0;
				$job['table_started'] = false;
			}
		} finally {
			$job['sql_bytes'] = ftell( $stream );
			fclose( $stream );
			$this->end_session( $mode );
		}
	}

	/**
	 * Character sets and collations supported by this server.
	 *
	 * @return array<string,string> Collation name => character set.
	 */
	private function collations() {
		if ( null === $this->collations ) {
			$this->collations = array();
			$result           = $this->query( 'SHOW COLLATION' );
			if ( is_object( $result ) ) {
				while ( $row = $result->fetch_assoc() ) {
					$this->collations[ strtolower( $row['Collation'] ) ] = strtolower( $row['Charset'] );
				}
				$result->free();
			}
		}
		return $this->collations;
	}

	/**
	 * Replace character sets and collations the destination does not know,
	 * such as MySQL 8 utf8mb4_0900_* or utf8mb3 names on older servers and
	 * MariaDB uca1400 names on MySQL, with the closest supported ones.
	 *
	 * @param string $sql Native CREATE TABLE.
	 * @return string Adapted statement.
	 */
	public function adapt_charsets( $sql ) {
		$known = $this->collations();
		if ( ! $known ) {
			return $sql;
		}
		$tokens  = Sql::tokens( $sql );
		$changed = false;
		foreach ( $tokens as $i => $token ) {
			$key = 'word' === $token['kind'] ? strtoupper( $token['raw'] ) : '';
			$j   = $i + 1;
			if ( 'CHARACTER' === $key && 'SET' === strtoupper( $tokens[ $j ]['raw'] ?? '' ) ) {
				$key = 'CHARSET';
				++$j;
			}
			if ( ! in_array( $key, array( 'COLLATE', 'CHARSET' ), true ) ) {
				continue;
			}
			if ( '=' === ( $tokens[ $j ]['raw'] ?? '' ) ) {
				++$j;
			}
			if ( 'word' !== ( $tokens[ $j ]['kind'] ?? '' ) ) {
				continue;
			}
			$name    = strtolower( $tokens[ $j ]['raw'] );
			$adapted = 'COLLATE' === $key ? self::collation( $name, $known ) : self::charset( $name, $known );
			if ( $adapted !== $name ) {
				$tokens[ $j ]['raw'] = $adapted;
				$changed             = true;
			}
		}
		return $changed ? Sql::render( $tokens ) : $sql;
	}

	/**
	 * Closest supported collation.
	 *
	 * @param string $name Collation.
	 * @param array  $known Supported collations.
	 * @return string Supported collation, or the original when nothing fits.
	 */
	public static function collation( $name, array $known ) {
		if ( isset( $known[ $name ] ) ) {
			return $name;
		}
		$charset = strtok( $name, '_' );
		$suffix  = substr( $name, strlen( $charset ) );
		foreach ( self::charsets( $charset, $known ) as $candidate ) {
			$options = array( $candidate . $suffix );
			if ( '_bin' === substr( $suffix, -4 ) ) {
				$options[] = $candidate . '_bin';
			}
			array_push( $options, $candidate . '_unicode_520_ci', $candidate . '_unicode_ci', $candidate . '_general_ci' );
			foreach ( $options as $option ) {
				if ( isset( $known[ $option ] ) ) {
					return $option;
				}
			}
		}
		return $name;
	}

	/**
	 * Closest supported character set.
	 *
	 * @param string $name Character set.
	 * @param array  $known Supported collations.
	 * @return string Supported character set, or the original when nothing fits.
	 */
	public static function charset( $name, array $known ) {
		$charsets = self::charsets( $name, $known );
		return $charsets ? $charsets[0] : $name;
	}

	/**
	 * Supported character sets to try, most faithful first.
	 *
	 * @param string $name Character set.
	 * @param array  $known Supported collations.
	 * @return array Character sets.
	 */
	private static function charsets( $name, array $known ) {
		$available = array_unique( array_values( $known ) );
		$tries     = array( $name );
		if ( 'utf8mb3' === $name || 'utf8' === $name ) {
			$tries = array( $name, 'utf8mb3' === $name ? 'utf8' : 'utf8mb3' );
		} elseif ( 'utf8mb4' === $name ) {
			$tries = array( 'utf8mb4', 'utf8', 'utf8mb3' );
		}
		return array_values( array_intersect( $tries, $available ) );
	}

	/**
	 * Row filters for the spam-comment and post-revision export options.
	 *
	 * @param string $suffix Prefix-free table name.
	 * @param array  $options Normalized export options.
	 * @return string SQL WHERE clause, or an empty string.
	 */
	public function where( $suffix, array $options ) {
		$comments = self::identifier( $this->db->prefix . 'comments' );
		$posts    = self::identifier( $this->db->prefix . 'posts' );
		$clauses  = array();
		if ( $options['no_spam_comments'] ) {
			$clauses['comments']    = "`comment_approved` <> 'spam'";
			$clauses['commentmeta'] = "`comment_id` IN (SELECT `comment_ID` FROM $comments WHERE `comment_approved` <> 'spam')";
		}
		if ( $options['no_post_revisions'] ) {
			$clauses['posts']    = "`post_type` <> 'revision'";
			$clauses['postmeta'] = "`post_id` NOT IN (SELECT `ID` FROM $posts WHERE `post_type` = 'revision')";
		}
		return isset( $clauses[ $suffix ] ) ? ' WHERE ' . $clauses[ $suffix ] : '';
	}

	/**
	 * Write a complete statement, failing rather than silently skipping oversized rows.
	 *
	 * @param resource $stream Dump stream.
	 * @param string   $bytes SQL bytes.
	 * @return void
	 * @throws RuntimeException When the dump or database operation cannot complete.
	 */
	private function write( $stream, $bytes ) {
		if ( strlen( $bytes ) > $this->statement_limit() || strlen( $bytes ) !== fwrite( $stream, $bytes ) ) {
			throw new RuntimeException( __( 'Unable to write a SQL statement within the available memory, packet or storage limits.', 'boat-backup' ) );
		}
	}

	/**
	 * Parse a dump statement's operation and target, keeping native DDL intact.
	 *
	 * @param array $tokens SQL tokens.
	 * @param array $tables Declared table descriptors.
	 * @return array Statement plan.
	 * @throws RuntimeException When a statement targets objects outside the dump.
	 */
	public static function plan( array $tokens, array $tables ) {
		$raw  = array_column( $tokens, 'raw' );
		$head = strtoupper( implode( ' ', array_slice( $raw, 0, 2 ) ) );
		if ( array( 'START', 'TRANSACTION' ) === $raw || array( 'COMMIT' ) === $raw ) {
			return array(
				'kind' => 'transaction',
				'sql'  => implode( ' ', $raw ),
			);
		}
		$position = 'DROP TABLE' === $head ? 4 : 2;
		if ( ! in_array( $head, array( 'DROP TABLE', 'CREATE TABLE', 'INSERT INTO' ), true ) || 'identifier' !== ( $tokens[ $position ]['kind'] ?? '' ) || ! preg_match( '/^`boat_backup_dump_(0|[1-9][0-9]*)`$/D', $raw[ $position ], $match ) || ! isset( $tables[ (int) $match[1] ] ) ) {
			throw new RuntimeException( __( 'The SQL dump contains an undeclared table or unsupported statement.', 'boat-backup' ) );
		}
		$plan = array(
			'kind'   => 'DROP TABLE' === $head ? 'drop' : ( 'CREATE TABLE' === $head ? 'create' : 'insert' ),
			'table'  => (int) $match[1],
			'tokens' => $tokens,
		);
		if ( 'drop' === $plan['kind'] ) {
			if ( array( 'DROP', 'TABLE', 'IF', 'EXISTS' ) !== array_slice( $raw, 0, 4 ) || 5 !== count( $raw ) ) {
				throw new RuntimeException( __( 'Invalid table drop in the SQL dump.', 'boat-backup' ) );
			}
		} elseif ( 'create' === $plan['kind'] ) {
			$offset = 3;
			Sql::group( $tokens, $offset );
			$syntax = '';
			foreach ( $tokens as $token ) {
				if ( ! in_array( $token['kind'], array( 'string', 'identifier' ), true ) ) {
					$syntax .= ' ' . $token['raw'];
				}
			}
			if ( preg_match( '/\b(?:SELECT|CONNECTION|TABLESPACE)\b|\b(?:DATA|INDEX)\s+DIRECTORY\b|\bUNION\s*=|;/i', $syntax ) ) {
				throw new RuntimeException( __( 'The native table definition requires external resources or contains an unexpected SQL operation.', 'boat-backup' ) );
			}
		} else {
			$offset  = 3;
			$columns = Sql::group( $tokens, $offset );
			if ( 'VALUES' !== ( $raw[ $offset++ ] ?? '' ) ) {
				throw new RuntimeException( __( 'Invalid row insert in the SQL dump.', 'boat-backup' ) );
			}
			$values = Sql::group( $tokens, $offset );
			if ( count( $tokens ) !== $offset || count( $columns ) !== count( $values ) || ! $columns ) {
				throw new RuntimeException( __( 'Invalid row arity in the SQL dump.', 'boat-backup' ) );
			}
			foreach ( $columns as $i => $column ) {
				if ( 1 !== count( $column ) || 'identifier' !== $column[0]['kind'] || 1 !== count( $values[ $i ] ) || ( 'string' !== $values[ $i ][0]['kind'] && 'NULL' !== $values[ $i ][0]['raw'] && ! preg_match( '/^0x[0-9a-f]+$/iD', $values[ $i ][0]['raw'] ) ) ) {
					throw new RuntimeException( __( 'Invalid column or literal in the SQL dump.', 'boat-backup' ) );
				}
			}
			$plan['columns'] = array_map(
				static function ( $column ) {
					return str_replace( '``', '`', substr( $column[0]['raw'], 1, -1 ) );
				},
				$columns
			);
			if ( count( array_unique( $plan['columns'] ) ) !== count( $columns ) ) {
				throw new RuntimeException( __( 'The SQL dump repeats a column in an insert.', 'boat-backup' ) );
			}
			$plan['values'] = array_map(
				static function ( $value ) {
					if ( preg_match( '/^0x([0-9a-f]+)$/iD', $value[0]['raw'], $hex ) ) {
						return hex2bin( strlen( $hex[1] ) % 2 ? '0' . $hex[1] : $hex[1] );
					}
					return 'NULL' === $value[0]['raw'] ? null : Sql::value( $value[0]['raw'] );
				},
				$values
			);
			$plan['hex']    = array_map(
				static function ( $value ) {
					return (bool) preg_match( '/^0x[0-9a-f]+$/iD', $value[0]['raw'] );
				},
				$values
			);
		}
		return $plan;
	}

	/**
	 * Apply one planned statement to this operation's private staging tables.
	 *
	 * @param array    $plan Parsed statement.
	 * @param array    $job Operation metadata.
	 * @param Rewriter $rewriter URL/path transformations.
	 * @return void
	 * @throws RuntimeException When the dump or database operation cannot complete.
	 */
	public function apply( array $plan, array &$job, Rewriter $rewriter ) {
		if ( 'transaction' === $plan['kind'] ) {
			$this->query( $plan['sql'] );
			return;
		}
		try {
			$i     = $plan['table'];
			$table = $this->stage_name( $job['id'], $i );
			if ( 'drop' === $plan['kind'] ) {
				$this->query( 'DROP TABLE IF EXISTS ' . self::identifier( $table ) );
				unset( $this->columns[ $table ] );
				return;
			}
			if ( 'create' === $plan['kind'] ) {
				$tokens           = $plan['tokens'];
				$tokens[2]['raw'] = self::identifier( $table );
				$sql              = self::compatible_ddl( Sql::render( $tokens ) );
				if ( ! $this->db->has_cap( 'utf8mb4_520' ) ) {
					$search  = $this->db->has_cap( 'utf8mb4' ) ? array( 'utf8mb4_unicode_520_ci' ) : array( 'utf8mb4_unicode_520_ci', 'utf8mb4' );
					$replace = $this->db->has_cap( 'utf8mb4' ) ? array( 'utf8mb4_unicode_ci' ) : array( 'utf8_unicode_ci', 'utf8' );
					$sql     = str_replace( $search, $replace, $sql );
				}
				$sql = $this->adapt_charsets( $sql );
				try {
					$this->query( $sql );
				} catch ( RuntimeException $error ) {
					if ( 1030 !== $error->getCode() ) {
						throw $error;
					}
					$this->query( preg_replace( '/\bENGINE\s*=\s*(?:MyISAM|Aria)\b/i', 'ENGINE=InnoDB', $sql ) );
				}
				$row                                    = $this->query( 'SHOW TABLE STATUS WHERE Name = ' . $this->literal( $table ) )->fetch_assoc();
				$job['database'][ $i ]['target_engine'] = $row['Engine'];
				return;
			}
			$metadata = array_column( $this->table_columns( $table ), null, 'name' );
			$values   = $plan['values'];
			$keyed    = array_combine( $plan['columns'], $values );
			foreach ( $plan['columns'] as $index => $name ) {
				if ( ! isset( $metadata[ $name ] ) ) {
					throw new RuntimeException( __( 'An SQL insert refers to an unknown destination column.', 'boat-backup' ) );
				}
				if ( null !== $values[ $index ] && $metadata[ $name ]['text'] ) {
					$values[ $index ] = $rewriter->replace( $values[ $index ] );
					// BeTheme stores its builder items as base64-encoded serialized data.
					if ( 'meta_value' === $name && 'postmeta' === $job['database'][ $i ]['table'] && 'mfn-page-items' === ( $keyed['meta_key'] ?? null ) ) {
						$values[ $index ] = $rewriter->base64( $values[ $index ] );
					}
				}
				if ( in_array( $job['database'][ $i ]['table'], array( 'options', 'usermeta' ), true ) && in_array( $name, array( 'option_name', 'meta_key' ), true ) && is_string( $values[ $index ] ) && 0 === strpos( $values[ $index ], self::KEY_PREFIX ) ) {
					$values[ $index ] = $this->db->prefix . substr( $values[ $index ], strlen( self::KEY_PREFIX ) );
				}
			}
			$row = array_combine( $plan['columns'], $values );
			if ( 'usermeta' === $job['database'][ $i ]['table'] && 'session_tokens' === ( $row['meta_key'] ?? '' ) ) {
				return;
			}
			$literals = array();
			foreach ( $values as $index => $value ) {
				$literals[] = null === $value ? 'NULL' : ( $plan['hex'][ $index ] ? '0x' . bin2hex( $value ) : $this->literal( $value ) );
			}
			$this->query(
				'INSERT INTO ' . self::identifier( $table ) . ' (' . implode( ',', array_map( array( self::class, 'identifier' ), $plan['columns'] ) ) . ') VALUES (' . implode( ',', $literals ) . ')'
			);
		} catch ( RuntimeException $error ) {
			/* translators: 1: table name, 2: database error without SQL or row data. */
			throw new RuntimeException( sprintf( __( 'Table %1$s: %2$s', 'boat-backup' ), $job['database'][ $plan['table'] ]['table'], $error->getMessage() ), $error->getCode() );
		}
	}

	/**
	 * Stable private table names.
	 *
	 * @param string $id Job ID.
	 * @param int    $index Table index.
	 * @param bool   $old Whether to name the retained table.
	 * @return string Table name.
	 */
	public function stage_name( $id, $index, $old = false ) {
		return 'boat_backup_' . substr( $id, 0, 16 ) . ( $old ? '_old_' : '_new_' ) . (int) $index;
	}

	/**
	 * Test table existence.
	 *
	 * @param string $table Table name.
	 * @return bool Whether present.
	 */
	public function exists( $table ) {
		$result = $this->query( 'SHOW TABLES LIKE ' . $this->literal( $this->db->esc_like( $table ) ) );
		$found  = (bool) $result->num_rows;
		$result->free();
		return $found;
	}

	/**
	 * Retain destination addresses and clear derived caches in staged options.
	 *
	 * @param string $table Options table.
	 * @param array  $destination Destination addresses.
	 * @return void
	 */
	public function prepare_options( $table, array $destination ) {
		foreach ( array(
			'home'           => $destination['home'],
			'siteurl'        => $destination['siteurl'],
			'active_plugins' => serialize( array( plugin_basename( BOAT_BACKUP_FILE ) ) ),
		) as $key => $value ) {
			$this->query( 'UPDATE ' . self::identifier( $table ) . ' SET option_value=' . $this->literal( $value ) . ' WHERE option_name=' . $this->literal( $key ) );
		}
		$this->query( 'DELETE FROM ' . self::identifier( $table ) . " WHERE option_name='rewrite_rules' OR option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%'" );
		// Jetpack's image CDN and single sign-on are bound to the source's WordPress.com connection.
		$row = $this->query( 'SELECT option_value FROM ' . self::identifier( $table ) . " WHERE option_name='jetpack_active_modules'" )->fetch_assoc();
		if ( $row ) {
			$modules = unserialize( $row['option_value'], array( 'allowed_classes' => false ) );
			if ( is_array( $modules ) ) {
				$kept = array_values( array_diff( $modules, array( 'photon', 'sso' ) ) );
				if ( count( $kept ) !== count( $modules ) ) {
					$this->query( 'UPDATE ' . self::identifier( $table ) . ' SET option_value=' . $this->literal( serialize( $kept ) ) . " WHERE option_name='jetpack_active_modules'" );
				}
			}
		}
	}

	/**
	 * Set active plugin paths without running activation hooks.
	 *
	 * @param string $table Options table.
	 * @param array  $plugins Paths.
	 * @return void
	 */
	public function set_active_plugins( $table, array $plugins ) {
		$this->query( 'UPDATE ' . self::identifier( $table ) . ' SET option_value=' . $this->literal( serialize( $plugins ) ) . " WHERE option_name='active_plugins'" );
	}

	/**
	 * Read only plugin path strings; package objects are never instantiated.
	 *
	 * @param string $table Options table.
	 * @return array Plugin paths.
	 */
	public function active_plugins( $table ) {
		$row    = $this->query( 'SELECT option_value FROM ' . self::identifier( $table ) . " WHERE option_name='active_plugins'" )->fetch_assoc();
		$result = unserialize( $row['option_value'], array( 'allowed_classes' => false ) );
		return is_array( $result ) ? array_values( array_filter( $result, 'is_string' ) ) : array();
	}

	/**
	 * Switch all table names with one RENAME TABLE statement.
	 *
	 * @param array $job Operation state.
	 * @return void
	 */
	public function swap( array $job ) {
		$renames = array();
		foreach ( $job['old_tables'] as $i => $suffix ) {
			$renames[] = self::identifier( $this->db->prefix . $suffix ) . ' TO ' . self::identifier( $this->stage_name( $job['id'], $i, true ) );
		}
		foreach ( $job['database'] as $i => $table ) {
			$renames[] = self::identifier( $this->stage_name( $job['id'], $i ) ) . ' TO ' . self::identifier( $this->db->prefix . $table['table'] );
		}
		$this->query( 'RENAME TABLE ' . implode( ', ', $renames ) );
	}

	/**
	 * Remove only private staging names owned by an operation, independent of format.
	 *
	 * @param array $job Operation identifier.
	 * @return void
	 */
	public function drop_stages( array $job ) {
		$prefix = 'boat_backup_' . substr( $job['id'], 0, 16 ) . '_new_';
		$result = $this->query( 'SHOW TABLES LIKE ' . $this->literal( $this->db->esc_like( $prefix ) . '%' ) );
		while ( $row = $result->fetch_row() ) {
			if ( preg_match( '/^' . preg_quote( $prefix, '/' ) . '[0-9]+$/D', $row[0] ) ) {
				$this->query( 'DROP TABLE ' . self::identifier( $row[0] ) );
			}
		}
		$result->free();
	}
}
