<?php
/**
 * Export options carried by the package and honored by every restore step.
 *
 * @package Wocody\BoatBackup
 */

namespace Wocody\BoatBackup;

// Exception messages are raw JSON data; HTML output is escaped at its boundary.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

use RuntimeException;

/** Option normalization shared by the REST API, WP-CLI and package reader. */
final class Options {

	/** Boolean exclusions, in the order the export screen presents them. */
	const FLAGS = array(
		'no_spam_comments',
		'no_post_revisions',
		'no_media',
		'no_themes',
		'no_inactive_themes',
		'no_muplugins',
		'no_plugins',
		'no_inactive_plugins',
		'no_cache',
		'no_database',
		'no_email_replace',
	);

	/** Upper bounds for administrator-defined find/replace pairs. */
	const MAX_PAIRS = 50;

	const MAX_VALUE = 4096;

	/**
	 * Accept only known flags and complete find/replace pairs.
	 *
	 * Pairs where either side is empty are ignored, so an unused row in the
	 * export form never changes data.
	 *
	 * @param mixed $raw Untrusted options.
	 * @return array Normalized options.
	 * @throws RuntimeException When the replacement list is malformed.
	 */
	public static function normalize( $raw ) {
		$raw     = is_array( $raw ) ? $raw : array();
		$options = array();
		foreach ( self::FLAGS as $flag ) {
			$options[ $flag ] = ! empty( $raw[ $flag ] ) && true === filter_var( $raw[ $flag ], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		}
		$pairs = $raw['replace'] ?? array();
		if ( ! is_array( $pairs ) || count( $pairs ) > self::MAX_PAIRS ) {
			throw new RuntimeException( __( 'The find and replace list is invalid.', 'boat-backup' ) );
		}
		$options['replace'] = array();
		foreach ( $pairs as $pair ) {
			$find    = is_array( $pair ) ? ( $pair['find'] ?? '' ) : '';
			$replace = is_array( $pair ) ? ( $pair['replace'] ?? '' ) : '';
			if ( ! is_string( $find ) || ! is_string( $replace ) || strlen( $find ) > self::MAX_VALUE || strlen( $replace ) > self::MAX_VALUE || preg_match( '/[\x00]/', $find . $replace ) ) {
				throw new RuntimeException( __( 'The find and replace list is invalid.', 'boat-backup' ) );
			}
			if ( '' !== $find && '' !== $replace && $find !== $replace ) {
				$options['replace'][] = array(
					'find'    => $find,
					'replace' => $replace,
				);
			}
		}
		return $options;
	}

	/**
	 * Top-level wp-content folders excluded by the selected options.
	 *
	 * @param array $options Normalized options.
	 * @return array Folder names.
	 */
	public static function excluded_roots( array $options ) {
		$roots = array();
		if ( $options['no_media'] ) {
			$roots[] = 'uploads';
			$roots[] = 'blogs.dir';
		}
		if ( $options['no_themes'] ) {
			$roots[] = 'themes';
		}
		if ( $options['no_plugins'] ) {
			$roots[] = 'plugins';
		}
		if ( $options['no_muplugins'] ) {
			$roots[] = 'mu-plugins';
		}
		if ( $options['no_cache'] ) {
			$roots[] = 'cache';
		}
		return $roots;
	}
}
