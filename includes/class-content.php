<?php
/**
 * Incremental content inventory, staged extraction and reversible directory moves.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions
 *
 * @package Wocody\BoatBackup
 */

namespace Wocody\BoatBackup;

// Exception messages are raw JSON data; HTML output is escaped at its boundary.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

use RuntimeException;

/** Content service for local migration operations. */
final class Content {

	/** Top-level folders owned by backup tools or WordPress upgrades; never exported. */
	const SKIPPED_ROOTS = array( 'ai1wm-backups', 'updraft', 'wpvividbackups', 'backups-dup-lite', 'backups-dup-pro', 'upgrade', 'upgrade-temp-backup' );

	/** WordPress drop-ins are bound to the host's services; the destination keeps its own. */
	const DROPINS = array( 'advanced-cache.php', 'db.php', 'db-error.php', 'install.php', 'maintenance.php', 'object-cache.php', 'php-error.php', 'fatal-error-handler.php', 'sunrise.php', 'blog-deleted.php', 'blog-inactive.php', 'blog-suspended.php' );

	/** Hosting must-use plugins installed by the provider; the destination keeps its own. */
	const HOSTING_MU_PLUGINS = array( 'mu-plugins/endurance-page-cache.php', 'mu-plugins/endurance-php-edge.php', 'mu-plugins/endurance-browser-cache.php', 'mu-plugins/gd-system-plugin.php' );

	/**
	 * Internal service or storage path.
	 *
	 * @var Storage
	 */
	private $storage;

	/**
	 * Construct.
	 *
	 * @param Storage $storage Storage.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public function __construct( Storage $storage ) {
		$this->storage = $storage;
	}

	/**
	 * Check the environment. Only wp-content is
	 * migrated; restoration additionally needs reversible moves into it.
	 *
	 * @return void
	 * @throws RuntimeException When a restore cannot be applied reversibly.
	 */
	public function preflight() {
		$private = stat( $this->storage->root() );
		$content = stat( WP_CONTENT_DIR );
		if ( $private['dev'] !== $content['dev'] || ! is_writable( WP_CONTENT_DIR ) ) {
			throw new RuntimeException( __( 'Private storage and wp-content must be writable and on the same filesystem for reversible restoration.', 'boat-backup' ) );
		}
	}

	/**
	 * Whether a wp-content relative path is left out of the export.
	 *
	 * @param string $relative Path relative to wp-content.
	 * @param array  $job Export state with normalized options and skipped paths.
	 * @return bool Whether excluded.
	 */
	public static function excluded( $relative, array $job ) {
		$parts = explode( '/', $relative );
		if ( preg_match( '~(?:^|/)(?:\.git|\.svn|\.hg)(?:/|$)~', $relative ) || ( 'plugins' === $parts[0] && 0 === strcasecmp( basename( BOAT_BACKUP_PATH ), $parts[1] ?? '' ) ) ) {
			return true;
		}
		if ( 1 === count( $parts ) && ( in_array( strtolower( $relative ), self::SKIPPED_ROOTS, true ) || Storage::FALLBACK === $relative || in_array( $relative, self::DROPINS, true ) || in_array( $relative, Options::excluded_roots( $job['options'] ), true ) ) ) {
			return true;
		}
		return in_array( $relative, $job['skip'] ?? array(), true );
	}

	/**
	 * The uploads folder relative to wp-content, honoring upload_path and UPLOADS.
	 *
	 * @return string|null Relative path, or null when it is outside wp-content.
	 */
	public static function uploads() {
		$base    = wp_normalize_path( wp_upload_dir( null, false )['basedir'] );
		$content = wp_normalize_path( WP_CONTENT_DIR );
		if ( 0 !== strpos( $base, $content . '/' ) ) {
			return null;
		}
		return trim( substr( $base, strlen( $content ) ), '/' );
	}

	/**
	 * Paths left out by the media and inactive-component options.
	 *
	 * A plugin folder is kept while any plugin inside it is active. A custom
	 * uploads folder is left out with the media library.
	 *
	 * @param array $options Normalized options.
	 * @return array Paths relative to wp-content.
	 */
	public static function skipped( array $options ) {
		$skip = array();
		if ( $options['no_media'] && null !== self::uploads() ) {
			$skip[] = self::uploads();
		}
		if ( $options['no_inactive_plugins'] && ! $options['no_plugins'] ) {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$active = array();
			foreach ( array_keys( get_plugins() ) as $plugin ) {
				$path            = 'plugins/' . ( '.' === dirname( $plugin ) ? $plugin : dirname( $plugin ) );
				$active[ $path ] = ( $active[ $path ] ?? false ) || is_plugin_active( $plugin );
			}
			$skip = array_keys( array_diff( $active, array( true ) ) );
		}
		if ( $options['no_inactive_themes'] && ! $options['no_themes'] ) {
			foreach ( array_keys( wp_get_themes() ) as $theme ) {
				if ( ! in_array( $theme, array( get_template(), get_stylesheet() ), true ) ) {
					$skip[] = 'themes/' . $theme;
				}
			}
		}
		return $skip;
	}

	/**
	 * Scan at most 250 entries, with a durable inventory offset for retries.
	 *
	 * @param array $job Job.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public function scan( array &$job ) {
		$path   = $this->storage->job_path( $job['id'] ) . '/inventory.ndjson';
		$handle = fopen( $path, 'c+b' );
		if ( ! $handle || ! ftruncate( $handle, $job['inventory_bytes'] ) || 0 !== fseek( $handle, $job['inventory_bytes'] ) ) {
			throw new RuntimeException( __( 'Unable to write the content inventory.', 'boat-backup' ) );
		}
		$processed = 0;
		try {
			while ( $job['scan'] && $processed < 250 ) {
				$current = $job['scan'][0];
				$root    = WP_CONTENT_DIR . ( '' === $current['path'] ? '' : '/' . Archive::content_path( $current['path'] ) );
				if ( ! is_dir( $root ) ) {
					array_shift( $job['scan'] );
					continue;
				}
				if ( 0 === $current['offset'] && '' !== $current['path'] ) {
					$this->line(
						$handle,
						array(
							'path' => $current['path'],
							'type' => 'directory',
						)
					);
					++$job['file_records'];
				}
				try {
					$iterator = new \DirectoryIterator( $root );
				} catch ( \UnexpectedValueException $error ) {
					array_shift( $job['scan'] );
					continue;
				}
				$iterator->seek( $current['offset'] );
				while ( $iterator->valid() && $processed < 250 ) {
					$entry = $iterator->current();
					++$job['scan'][0]['offset'];
					++$processed;
					if ( ! $entry->isDot() ) {
						$relative = ltrim( $current['path'] . '/' . $entry->getFilename(), '/' );
						if ( ! self::excluded( $relative, $job ) ) {
							try {
								Archive::content_path( $relative );
							} catch ( RuntimeException $error ) {
								// Entries that cannot be stored are skipped:
								// names with control characters, backslashes, colons, invalid UTF-8
								// or trailing dots cannot be restored on every system.
								$iterator->next();
								continue;
							}
							// Symbolic links are followed: their targets are stored as
							// regular files and folders. Broken links are skipped.
							$links = $current['links'] ?? array();
							if ( $entry->isLink() && ! $this->followable( $entry->getPathname(), $links ) ) {
								$iterator->next();
								continue;
							}
							if ( $entry->isDir() ) {
								$next = array(
									'path'   => $relative,
									'offset' => 0,
								);
								if ( $entry->isLink() ) {
									$links[] = realpath( $entry->getPathname() );
								}
								if ( $links ) {
									$next['links'] = $links;
								}
								$job['scan'][] = $next;
							} elseif ( $entry->isFile() && $entry->isReadable() ) {
								$this->line(
									$handle,
									array(
										'path'  => $relative,
										'type'  => 'file',
										'total' => $entry->getSize(),
										'mtime' => $entry->getMTime(),
									)
								);
								++$job['file_records'];
							}
						}
					}
					$iterator->next();
				}
				if ( ! $iterator->valid() ) {
					array_shift( $job['scan'] );
				}
			}
			$job['inventory_bytes'] = ftell( $handle );
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * Whether a symbolic link can be exported without a cycle.
	 *
	 * A folder link is refused when its target contains the link itself or was
	 * already entered through another link on the same path.
	 *
	 * @param string $path Link path.
	 * @param array  $links Real targets of folder links already followed.
	 * @return bool Whether to export the target.
	 */
	private function followable( $path, array $links ) {
		$target = realpath( $path );
		if ( false === $target ) {
			return false;
		}
		if ( ! is_dir( $target ) ) {
			return true;
		}
		$parent = realpath( dirname( $path ) );
		return ! in_array( $target, $links, true ) && false !== $parent && $parent !== $target && 0 !== strpos( $parent . '/', $target . '/' );
	}

	/**
	 * Line.
	 *
	 * @param mixed $handle Handle.
	 * @param array $record Record.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	private function line( $handle, array $record ) {
		$line = wp_json_encode( $record, JSON_UNESCAPED_SLASHES );
		if ( false === $line || strlen( $line ) + 1 !== fwrite( $handle, $line . "\n" ) ) {
			throw new RuntimeException( __( 'Unable to save the content inventory.', 'boat-backup' ) );
		}
	}

	/**
	 * Export record.
	 *
	 * @param array $job Job.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public function export_record( array &$job ) {
		$handle = fopen( $this->storage->job_path( $job['id'] ) . '/inventory.ndjson', 'rb' );
		if ( ! $handle || 0 !== fseek( $handle, $job['file_cursor'] ) ) {
			throw new RuntimeException( __( 'Unable to read the content inventory.', 'boat-backup' ) );
		}
		try {
			while ( true ) {
				$line = fgets( $handle, 16384 );
				if ( false === $line ) {
					return null;
				}
				$record = json_decode( $line, true );
				Archive::content_path( $record['path'] );
				if ( 'directory' === $record['type'] ) {
					$job['file_cursor'] = ftell( $handle );
					return array( $record, '' );
				}
				$path = WP_CONTENT_DIR . '/' . $record['path'];
				clearstatcache( true, $path );
				if ( 0 === $job['file_offset'] ) {
					// Files are read as they are when their first segment is written,
					// not as they were when listed: caches and logs change constantly.
					if ( ! is_file( $path ) || ! is_readable( $path ) ) {
						$job['file_cursor'] = ftell( $handle );
						continue;
					}
					$job['file_total'] = filesize( $path );
					$job['file_mtime'] = filemtime( $path );
				} elseif ( ! is_file( $path ) || filesize( $path ) < $job['file_total'] ) {
					// Appended bytes are ignored; a truncated or replaced file cannot be completed.
					/* translators: %s: path relative to wp-content. */
					throw new RuntimeException( sprintf( __( 'A large file was truncated or replaced during export: %s. Start a new export.', 'boat-backup' ), $record['path'] ) );
				}
				$record['total']  = $job['file_total'];
				$record['mtime']  = $job['file_mtime'];
				$record['offset'] = $job['file_offset'];
				$source           = fopen( $path, 'rb' );
				if ( ! $source || 0 !== fseek( $source, $job['file_offset'] ) ) {
					throw new RuntimeException( __( 'Unable to read a source content file.', 'boat-backup' ) );
				}
				$bytes = $record['total'] > $job['file_offset'] ? fread( $source, min( Archive::BLOCK, $record['total'] - $job['file_offset'] ) ) : '';
				fclose( $source );
				if ( false === $bytes || ( '' === $bytes && $job['file_offset'] < $record['total'] ) ) {
					throw new RuntimeException( __( 'Unable to read a source file segment.', 'boat-backup' ) );
				}
				$job['file_offset'] += strlen( $bytes );
				if ( $job['file_offset'] >= $record['total'] ) {
					$job['file_offset'] = 0;
					$job['file_cursor'] = ftell( $handle );
				}
				return array( $record, $bytes );
			}
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * Stage.
	 *
	 * @param array $job Job.
	 * @param mixed $index Index.
	 * @param array $record Record.
	 * @param mixed $bytes Bytes.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public function stage( array $job, $index, array $record, $bytes ) {
		$relative = Archive::content_path( $record['path'] ?? '' );
		$base     = $this->storage->job_path( $job['id'] );
		$path     = $base . '/staging/' . $relative;
		$meta     = $base . '/file-state/' . hash( 'sha256', $relative ) . '.json';
		$this->storage->mkdir( dirname( $meta ) );
		$prior = is_file( $meta ) ? $this->storage->read( $meta ) : null;
		if ( 'directory' === $record['type'] ) {
			if ( '' !== $bytes || ( $prior && $prior['index'] !== $index ) ) {
				throw new RuntimeException( __( 'Duplicate or invalid content directory.', 'boat-backup' ) );
			}
			$this->public_directory( $path, $base . '/staging' );
			$this->storage->write( $meta, array( 'index' => $index ) );
			return;
		}
		foreach ( array( 'total', 'offset', 'mtime' ) as $key ) {
			if ( ! isset( $record[ $key ] ) || ! is_int( $record[ $key ] ) || $record[ $key ] < 0 ) {
				throw new RuntimeException( __( 'Invalid content file metadata.', 'boat-backup' ) );
			}
		}
		if ( strlen( $bytes ) > Archive::BLOCK || $record['offset'] + strlen( $bytes ) > $record['total'] || ( '' === $bytes && 0 !== $record['total'] ) || ( $prior && ( ( $prior['index'] !== $index && ( $prior['next'] ?? -1 ) !== $record['offset'] ) || ( $prior['total'] ?? -1 ) !== $record['total'] ) ) || ( ! $prior && 0 !== $record['offset'] ) ) {
			throw new RuntimeException( __( 'Duplicate, incomplete or out-of-order file segments.', 'boat-backup' ) );
		}
		if ( $prior && $prior['index'] === $index ) {
			return;
		}
		if ( $prior && ( $prior['next'] ?? -1 ) === ( $prior['total'] ?? -2 ) ) {
			throw new RuntimeException( __( 'Duplicate file in backup.', 'boat-backup' ) );
		}
		if ( 0 === $record['offset'] && is_file( $path ) ) {
			// A retry after writing bytes but before saving the segment marker is safe.
			if ( $prior ) {
				throw new RuntimeException( __( 'Duplicate file in backup.', 'boat-backup' ) );
			}
		}
		$this->public_directory( dirname( $path ), $base . '/staging' );
		$handle = fopen( $path, 'c+b' );
		if ( ! $handle || ! ftruncate( $handle, $record['offset'] ) || 0 !== fseek( $handle, $record['offset'] ) || strlen( $bytes ) !== fwrite( $handle, $bytes ) ) {
			if ( is_resource( $handle ) ) {
				fclose( $handle );
			}
			throw new RuntimeException( __( 'Unable to stage a file. Check available disk space.', 'boat-backup' ) );
		}
		fclose( $handle );
		chmod( $path, 0644 );
		touch( $path, $record['mtime'] );
		$this->storage->write(
			$meta,
			array(
				'index' => $index,
				'next'  => $record['offset'] + strlen( $bytes ),
				'total' => $record['total'],
			)
		);
	}

	/**
	 * Public directory.
	 *
	 * @param mixed $path Path.
	 * @param mixed $root Root.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	private function public_directory( $path, $root ) {
		$this->storage->mkdir( $path );
		while ( $path !== $root && Storage::within( $path, $root ) ) {
			chmod( $path, 0755 );
			$path = dirname( $path );
		}
	}

	/**
	 * Overlay plan: every top-level file and every first-level child of a
	 * staged folder replaces the destination item with the same name. Items
	 * that exist only on the destination stay in place.
	 *
	 * @param array $job Operation state.
	 * @return array Paths relative to wp-content.
	 */
	public function moves( array $job ) {
		$base   = $this->storage->job_path( $job['id'] ) . '/staging';
		$result = array();
		if ( ! is_dir( $base ) ) {
			return $result;
		}
		$roots = iterator_to_array( new \FilesystemIterator( $base, \FilesystemIterator::SKIP_DOTS ) );
		ksort( $roots );
		foreach ( $roots as $root ) {
			$name = $root->getFilename();
			if ( ! $root->isDir() ) {
				if ( ! in_array( $name, self::DROPINS, true ) ) {
					$result[] = $name;
				}
				continue;
			}
			$children = iterator_to_array( new \FilesystemIterator( $root->getPathname(), \FilesystemIterator::SKIP_DOTS ) );
			ksort( $children );
			if ( ! $children && ! file_exists( WP_CONTENT_DIR . '/' . $name ) ) {
				$result[] = $name;
			}
			foreach ( $children as $child ) {
				$relative = $name . '/' . $child->getFilename();
				if ( ! in_array( $relative, self::HOSTING_MU_PLUGINS, true ) && ! ( 'plugins' === $name && 0 === strcasecmp( basename( BOAT_BACKUP_PATH ), $child->getFilename() ) ) ) {
					$result[] = $relative;
				}
			}
		}
		return $result;
	}

	/**
	 * Save the intent BEFORE either rename so a killed process can be reversed.
	 *
	 * @param array $job Job.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public function apply( array &$job ) {
		$base = $this->storage->job_path( $job['id'] );
		foreach ( $this->moves( $job ) as $relative ) {
			Archive::content_path( $relative );
			$live = WP_CONTENT_DIR . '/' . $relative;
			$new  = $base . '/staging/' . $relative;
			$old  = $base . '/recovery/files/' . $relative;
			// A destination item that is a symbolic link is moved as the link itself,
			// so its target is never written to and recovery restores the link.
			if ( strpos( $relative, '/' ) && ! is_dir( dirname( $live ) ) && ! wp_mkdir_p( dirname( $live ) ) ) {
				throw new RuntimeException( __( 'Unable to install staged content files.', 'boat-backup' ) );
			}
			$job['moves'][] = array(
				'path' => $relative,
				'old'  => self::exists( $live ),
				'new'  => self::exists( $new ),
			);
			$this->storage->save( $job );
			if ( self::exists( $live ) ) {
				$this->storage->mkdir( dirname( $old ) );
				if ( ! rename( $live, $old ) ) {
					throw new RuntimeException( __( 'Unable to retain the destination files for recovery.', 'boat-backup' ) );
				}
			}
			// phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- Restoring site content, never storing plugin-owned backup data in plugins.
			if ( self::exists( $new ) && ! rename( $new, $live ) ) {
				throw new RuntimeException( __( 'Unable to install staged content files.', 'boat-backup' ) );
			}
		}
	}

	/**
	 * Whether a path exists, counting symbolic links whose target is missing.
	 *
	 * @param string $path Path.
	 * @return bool Exists.
	 */
	private static function exists( $path ) {
		return file_exists( $path ) || is_link( $path );
	}

	/**
	 * Rollback.
	 *
	 * @param array $job Job.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public function rollback( array $job ) {
		$base = $this->storage->job_path( $job['id'] );
		foreach ( array_reverse( $job['moves'] ?? array() ) as $move ) {
			$relative = Archive::content_path( $move['path'] );
			$live     = WP_CONTENT_DIR . '/' . $relative;
			$new      = $base . '/staging/' . $relative;
			$old      = $base . '/recovery/files/' . $relative;
			if ( $move['new'] && ! self::exists( $new ) && self::exists( $live ) ) {
				$this->storage->mkdir( dirname( $new ) );
				// phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- Reverses a journaled content move into private staging.
				if ( ! rename( $live, $new ) ) {
					throw new RuntimeException( __( 'File recovery failed. Keep maintenance enabled and inspect the private recovery directory.', 'boat-backup' ) );
				}
			}
			// phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- Restoring the destination's previous content from its private recovery copy.
			if ( self::exists( $old ) && ! rename( $old, $live ) ) {
				throw new RuntimeException( __( 'Unable to restore the previous destination files.', 'boat-backup' ) );
			}
		}
	}
}
