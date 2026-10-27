<?php
/**
 * Current SQL-dump package: checksummed SQL/file segments and a manifest.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions
 *
 * @package Wocody\BoatBackup
 */

namespace Wocody\BoatBackup;

// Exception messages are raw JSON data; HTML output is escaped at its boundary.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

use RuntimeException;
use ZipArchive;

/** Archive service for local migration operations. */
final class Archive {

	const VERSION = 4;

	const BLOCK = 4194304;

	/**
	 * Internal service or storage path.
	 *
	 * @var ZipArchive
	 */
	private $zip;

	/**
	 * Construct.
	 *
	 * @param mixed $path Path.
	 * @param mixed $write Write.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public function __construct( $path, $write = false ) {
		$this->zip = new ZipArchive();
		$result    = $this->zip->open( $path, $write ? ZipArchive::CREATE : ZipArchive::RDONLY );
		if ( true !== $result ) {
			throw new RuntimeException( __( 'The backup is not a readable Boat Backup & Migration ZIP package.', 'boat-backup' ) );
		}
	}

	/**
	 * Close.
	 *
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public function close() {
		if ( $this->zip && ! $this->zip->close() ) {
			throw new RuntimeException( __( 'Unable to finish the package. Check free disk space.', 'boat-backup' ) );
		}
		$this->zip = null;
	}

	/**
	 * Destruct.
	 *
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public function __destruct() {
		if ( $this->zip ) {
			$this->zip->close();
		}
	}

	/**
	 * Put.
	 *
	 * @param mixed $index Index.
	 * @param array $record Record.
	 * @param mixed $bytes Bytes.
	 * @param mixed $key Raw key of a password-protected package.
	 * @return int Plain payload bytes.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public function put( $index, array $record, $bytes, $key = null ) {
		if ( strlen( $bytes ) > self::BLOCK ) {
			throw new RuntimeException( __( 'A package segment exceeds the supported size.', 'boat-backup' ) );
		}
		$plain = strlen( $bytes );
		if ( null !== $key ) {
			$bytes = Crypto::seal( $key, $bytes, 'data/' . $index );
		}
		// Checksums cover the stored bytes, so protected packages reveal no plaintext digest.
		$record['size']   = strlen( $bytes );
		$record['sha256'] = hash( 'sha256', $bytes );
		$json             = wp_json_encode( $record, JSON_UNESCAPED_SLASHES );
		if ( false === $json || strlen( $json ) > 65536 ) {
			throw new RuntimeException( __( 'A package descriptor is too large.', 'boat-backup' ) );
		}
		foreach ( array(
			'data/' . $index            => $bytes,
			'index/' . $index . '.json' => $json,
		) as $name => $value ) {
			if ( ! $this->zip->addFromString( $name, $value ) || ! $this->zip->setCompressionName( $name, ZipArchive::CM_DEFLATE, 1 ) ) {
				throw new RuntimeException( __( 'Unable to write a package record.', 'boat-backup' ) );
			}
		}
		return $plain;
	}

	/**
	 * Finish.
	 *
	 * @param array $manifest Manifest.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public function finish( array $manifest ) {
		if ( ! $this->zip->addFromString( 'manifest.json', wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES ) ) ) {
			throw new RuntimeException( __( 'Unable to write the package manifest.', 'boat-backup' ) );
		}
	}

	/**
	 * Read json.
	 *
	 * @param mixed $name Name.
	 * @param mixed $limit Limit.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	private function read_json( $name, $limit ) {
		$stat = $this->zip->statName( $name );
		if ( ! $stat || $stat['size'] > $limit || 0 !== ( $stat['encryption_method'] ?? 0 ) ) {
			throw new RuntimeException( __( 'The backup contains an invalid or encrypted descriptor.', 'boat-backup' ) );
		}
		if ( ! $this->zip->getExternalAttributesIndex( $this->zip->locateName( $name ), $system, $attributes ) || ( ( $attributes >> 16 ) & 0170000 ) === 0120000 ) {
			throw new RuntimeException( __( 'Symbolic links are not supported.', 'boat-backup' ) );
		}
		$json  = $this->zip->getFromName( $name );
		$value = false === $json ? null : json_decode( $json, true, 64 );
		if ( ! is_array( $value ) || JSON_ERROR_NONE !== json_last_error() ) {
			throw new RuntimeException( __( 'The backup contains invalid JSON.', 'boat-backup' ) );
		}
		return $value;
	}

	/**
	 * Manifest.
	 *
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public function manifest() {
		$value = $this->read_json( 'manifest.json', 4 * 1024 * 1024 );
		if ( 'boat-backup' !== ( $value['format'] ?? '' ) || self::VERSION !== ( $value['version'] ?? null ) || ! isset( $value['records'], $value['bytes'], $value['source'] ) || ! is_int( $value['records'] ) || $value['records'] < 1 || ! is_int( $value['bytes'] ) || $value['bytes'] < 0 || $this->zip->numFiles !== $value['records'] * 2 + 1 ) {
			throw new RuntimeException( __( 'Unsupported or incomplete Boat Backup & Migration package.', 'boat-backup' ) );
		}
		foreach ( array( 'home', 'siteurl', 'home_url', 'site_url', 'content', 'content_url', 'prefix', 'wp_version' ) as $key ) {
			if ( ! isset( $value['source'][ $key ] ) || ! is_string( $value['source'][ $key ] ) || '' === $value['source'][ $key ] ) {
				throw new RuntimeException( __( 'The package is missing source site information.', 'boat-backup' ) );
			}
		}
		if ( ! isset( $value['options'] ) || ! is_array( $value['options'] ) ) {
			throw new RuntimeException( __( 'The package export options are invalid.', 'boat-backup' ) );
		}
		$value['options'] = Options::normalize( $value['options'] );
		if ( isset( $value['encryption'] ) ) {
			$value['encryption'] = Crypto::params( $value['encryption'] );
		}
		$database = ! $value['options']['no_database'];
		if ( ! isset( $value['database']['bytes'], $value['database']['tables'] ) || ! is_int( $value['database']['bytes'] ) || ! is_array( $value['database']['tables'] ) || ! isset( $value['source']['db_version'] ) || ! is_int( $value['source']['db_version'] ) || ( $database && ( $value['database']['bytes'] < 1 || ! $value['database']['tables'] ) ) || ( ! $database && ( 0 !== $value['database']['bytes'] || $value['database']['tables'] ) ) ) {
			throw new RuntimeException( __( 'The package is missing its native SQL dump metadata.', 'boat-backup' ) );
		}
		return $value;
	}

	/**
	 * Record.
	 *
	 * @param mixed $index Index.
	 * @param mixed $key Raw key of a password-protected package.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public function record( $index, $key = null ) {
		$record = $this->read_json( 'index/' . $index . '.json', 65536 );
		$stat   = $this->zip->statName( 'data/' . $index );
		if ( ! $stat || ! isset( $record['size'], $record['sha256'], $record['type'] ) || ! is_int( $record['size'] ) || $record['size'] < 0 || $record['size'] > self::BLOCK + ( null === $key ? 0 : Crypto::OVERHEAD ) || $record['size'] !== $stat['size'] || ! is_string( $record['sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $record['sha256'] ) || ! in_array( $record['type'], array( 'sql', 'file', 'directory' ), true ) || 0 !== ( $stat['encryption_method'] ?? 0 ) ) {
			throw new RuntimeException( __( 'The backup contains an invalid record.', 'boat-backup' ) );
		}
		foreach ( array( 'data/' . $index, 'index/' . $index . '.json' ) as $name ) {
			$position = $this->zip->locateName( $name );
			if ( ! $this->zip->getExternalAttributesIndex( $position, $system, $attributes ) || ( ( $attributes >> 16 ) & 0170000 ) === 0120000 ) {
				throw new RuntimeException( __( 'Symbolic links are not supported.', 'boat-backup' ) );
			}
		}
		$bytes = $this->zip->getFromName( 'data/' . $index );
		if ( false === $bytes || strlen( $bytes ) !== $record['size'] || ! hash_equals( $record['sha256'], hash( 'sha256', $bytes ) ) ) {
			throw new RuntimeException( __( 'Backup checksum verification failed.', 'boat-backup' ) );
		}
		if ( null !== $key ) {
			$bytes = Crypto::open( $key, $bytes, 'data/' . $index );
		}
		return array( $record, $bytes );
	}

	/**
	 * Content path.
	 *
	 * @param mixed $path Path.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public static function content_path( $path ) {
		if ( ! is_string( $path ) || strlen( $path ) > 4096 || ! preg_match( '//u', $path ) || false !== strpos( $path, '\\' ) || preg_match( '/[\x00-\x1f\x7f:]/', $path ) || preg_match( '~(?:^|/)(?:\.|\.\.)(?:/|$)|//~', $path ) || ! preg_match( '~^[^/]+(?:/[^/]+)*$~D', $path ) ) {
			throw new RuntimeException( __( 'The package contains an unsafe content path.', 'boat-backup' ) );
		}
		$parts = explode( '/', $path );
		foreach ( $parts as $part ) {
			if ( rtrim( $part, '. ' ) !== $part ) {
				throw new RuntimeException( __( 'The package contains an unsafe content path.', 'boat-backup' ) );
			}
		}
		if ( 'plugins' === $parts[0] && 0 === strcasecmp( $parts[1] ?? '', basename( BOAT_BACKUP_PATH ) ) ) {
			throw new RuntimeException( __( 'A package may not overwrite Boat Backup & Migration.', 'boat-backup' ) );
		}

		return $path;
	}
}
