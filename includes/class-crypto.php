<?php
/**
 * Password protection of package segments.
 *
 * @package Wocody\BoatBackup
 */

namespace Wocody\BoatBackup;

// Exception messages are raw JSON data; HTML output is escaped at its boundary.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

use RuntimeException;

/**
 * AES-256-GCM with a PBKDF2-SHA256 key. Each segment carries its own nonce and
 * is bound to its record name, so segments cannot be swapped or reordered.
 */
final class Crypto {

	const CIPHER = 'aes-256-gcm';

	const ITERATIONS = 200000;

	/** Nonce and tag bytes added to every sealed segment. */
	const OVERHEAD = 28;

	const CHECK = 'boat-backup';

	/**
	 * Whether this PHP build can protect backups.
	 *
	 * @return bool Availability.
	 */
	public static function available() {
		return function_exists( 'openssl_encrypt' ) && in_array( self::CIPHER, openssl_get_cipher_methods(), true );
	}

	/**
	 * Create the manifest parameters and key for a new password.
	 *
	 * @param string $password Password.
	 * @return array{0: array, 1: string} Manifest parameters and raw key.
	 * @throws RuntimeException When protection is unavailable or the password is invalid.
	 */
	public static function create( $password ) {
		self::require_password( $password );
		$salt   = random_bytes( 16 );
		$key    = hash_pbkdf2( 'sha256', $password, $salt, self::ITERATIONS, 32, true );
		$params = array(
			'cipher'     => self::CIPHER,
			'kdf'        => 'pbkdf2-sha256',
			'iterations' => self::ITERATIONS,
			'salt'       => base64_encode( $salt ),
			'check'      => base64_encode( self::seal( $key, self::CHECK, 'check' ) ),
		);
		return array( $params, $key );
	}

	/**
	 * Derive and verify the key of an existing package.
	 *
	 * @param array  $params Manifest parameters.
	 * @param string $password Password.
	 * @return string Raw key.
	 * @throws RuntimeException When the password is wrong.
	 */
	public static function unlock( array $params, $password ) {
		self::require_password( $password );
		$key = hash_pbkdf2( 'sha256', $password, base64_decode( $params['salt'], true ), $params['iterations'], 32, true );
		try {
			$valid = self::CHECK === self::open( $key, base64_decode( $params['check'], true ), 'check' );
		} catch ( RuntimeException $error ) {
			$valid = false;
		}
		if ( ! $valid ) {
			throw new RuntimeException( __( 'The backup password is incorrect.', 'boat-backup' ) );
		}
		return $key;
	}

	/**
	 * Validate manifest parameters before any key derivation.
	 *
	 * @param mixed $params Untrusted parameters.
	 * @return array Parameters.
	 * @throws RuntimeException When they are malformed.
	 */
	public static function params( $params ) {
		$salt  = is_array( $params ) && is_string( $params['salt'] ?? null ) ? base64_decode( $params['salt'], true ) : false;
		$check = is_array( $params ) && is_string( $params['check'] ?? null ) ? base64_decode( $params['check'], true ) : false;
		if ( ! $salt || 16 !== strlen( $salt ) || ! $check || strlen( $check ) !== strlen( self::CHECK ) + self::OVERHEAD || self::CIPHER !== ( $params['cipher'] ?? '' ) || 'pbkdf2-sha256' !== ( $params['kdf'] ?? '' ) || ! is_int( $params['iterations'] ?? null ) || $params['iterations'] < 100000 || $params['iterations'] > 2000000 ) {
			throw new RuntimeException( __( 'The backup password protection is invalid.', 'boat-backup' ) );
		}
		return $params;
	}

	/**
	 * Encrypt one segment.
	 *
	 * @param string $key Raw key.
	 * @param string $bytes Plain bytes.
	 * @param string $name Record name bound as additional data.
	 * @return string Nonce, tag and ciphertext.
	 * @throws RuntimeException When encryption fails.
	 */
	public static function seal( $key, $bytes, $name ) {
		$nonce  = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( $bytes, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, $name, 16 );
		if ( false === $cipher ) {
			throw new RuntimeException( __( 'Unable to encrypt the backup.', 'boat-backup' ) );
		}
		return $nonce . $tag . $cipher;
	}

	/**
	 * Decrypt and authenticate one segment.
	 *
	 * @param string $key Raw key.
	 * @param string $sealed Nonce, tag and ciphertext.
	 * @param string $name Record name bound as additional data.
	 * @return string Plain bytes.
	 * @throws RuntimeException When the segment was altered or the key is wrong.
	 */
	public static function open( $key, $sealed, $name ) {
		$plain = strlen( $sealed ) < self::OVERHEAD ? false : openssl_decrypt( substr( $sealed, self::OVERHEAD ), self::CIPHER, $key, OPENSSL_RAW_DATA, substr( $sealed, 0, 12 ), substr( $sealed, 12, 16 ), $name );
		if ( false === $plain ) {
			throw new RuntimeException( __( 'Backup decryption failed. The file is damaged or the password is wrong.', 'boat-backup' ) );
		}
		return $plain;
	}

	/**
	 * Require protection support and a usable password.
	 *
	 * @param mixed $password Password.
	 * @return void
	 * @throws RuntimeException When unusable.
	 */
	private static function require_password( $password ) {
		if ( ! self::available() ) {
			throw new RuntimeException( __( 'Password protection requires the PHP OpenSSL extension with AES-256-GCM.', 'boat-backup' ) );
		}
		if ( ! is_string( $password ) || '' === $password || strlen( $password ) > 1024 ) {
			throw new RuntimeException( __( 'Enter a backup password of up to 1024 characters.', 'boat-backup' ) );
		}
	}
}
