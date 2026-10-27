<?php
/**
 * Serialization-aware byte-string replacement without PHP object instantiation.
 *
 * @package Wocody\BoatBackup
 */

namespace Wocody\BoatBackup;

// Exception messages are raw JSON data; HTML output is escaped at its boundary.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
// Page builders store content as base64; it is decoded only to rewrite addresses, never executed.
// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

use RuntimeException;

/** Rewriter service for local migration operations. */
final class Rewriter {

	/**
	 * Internal service or storage path.
	 *
	 * @var array<string,string>
	 */
	private $replacements;


	/**
	 * Construct.
	 *
	 * @param array $replacements Replacements.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public function __construct( array $replacements ) {
		unset( $replacements[''] );
		uksort(
			$replacements,
			static function ( $a, $b ) {
				return strlen( $b ) <=> strlen( $a );
			}
		);
		$this->replacements = $replacements;
	}

	/**
	 * Replace.
	 *
	 * @param mixed $value Value.
	 * @param mixed $depth Depth.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	public function replace( $value, $depth = 0 ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}
		if ( $depth <= 64 && ( 'N;' === $value || preg_match( '/^(?:[aOCE]:\d+:|s:\d+:"|[birR]:-?\d+;|d:(?:[\d.+Ee-]+|NAN|INF|-INF);)/', $value ) ) ) {
			try {
				$offset = 0;
				$result = $this->token( $value, $offset, $depth );
				if ( strlen( $value ) === $offset ) {
					return $result;
				}
			} catch ( RuntimeException $error ) {
				unset( $error );
			}
			// Text that only looks serialized (or is
			// already damaged) is migrated with plain replacement instead of
			// stopping the restore.
		}
		// Visual Composer stores raw HTML/JS blocks as base64; rewrite inside them
		// and leave the encoded text itself out of plain replacement.
		$parts = preg_split( '~(\[(vc_raw_html|vc_raw_js)\][A-Za-z0-9+/=]*\[/\2\])~', $value, -1, PREG_SPLIT_DELIM_CAPTURE );
		$out   = '';
		for ( $i = 0, $count = count( $parts ); $i < $count; ++$i ) {
			if ( 1 === $i % 3 ) {
				$tag  = $parts[ $i + 1 ];
				$body = substr( $parts[ $i ], strlen( $tag ) + 2, -strlen( $tag ) - 3 );
				$out .= '[' . $tag . ']' . $this->base64( $body, $depth ) . '[/' . $tag . ']';
				++$i;
			} else {
				$out .= strtr( $parts[ $i ], $this->replacements );
			}
		}
		return $out;
	}

	/**
	 * Rewrite a base64 payload in its decoded form; invalid base64 is kept as is.
	 *
	 * @param string $value Base64 text.
	 * @param int    $depth Nesting depth.
	 * @return string Base64 text.
	 */
	public function base64( $value, $depth = 0 ) {
		$decoded = '' === $value ? false : base64_decode( $value, true );
		if ( false === $decoded ) {
			return $value;
		}
		$replaced = $this->replace( $decoded, $depth + 1 );
		return $replaced === $decoded ? $value : base64_encode( $replaced );
	}

	/**
	 * Token.
	 *
	 * @param mixed $input Input.
	 * @param mixed $offset Offset.
	 * @param mixed $depth Depth.
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	private function token( $input, &$offset, $depth ) {
		if ( $depth > 64 ) {
			throw new RuntimeException( __( 'Serialized data exceeds the safe nesting depth.', 'boat-backup' ) );
		}
		$rest = substr( $input, $offset );
		if ( preg_match( '/^(N;|[birR]:-?\d+;|d:(?:[+-]?[\d.Ee+-]+|NAN|INF|-INF);)/', $rest, $match ) ) {
			$offset += strlen( $match[0] );
			return $match[0];
		}
		if ( preg_match( '/^([sE]):(\d+):"/', $rest, $match ) ) {
			$offset += strlen( $match[0] );
			$length  = (int) $match[2];
			$value   = substr( $input, $offset, $length );
			$offset += $length;
			if ( strlen( $value ) !== $length || '";' !== substr( $input, $offset, 2 ) ) {
				$this->invalid();
			}
			$offset += 2;
			$value   = 's' === $match[1] ? $this->replace( $value, $depth + 1 ) : $value;
			return $match[1] . ':' . strlen( $value ) . ':"' . $value . '";';
		}
		if ( preg_match( '/^a:(\d+):\{/', $rest, $match ) ) {
			$head  = $match[0];
			$count = (int) $match[1];
		} elseif ( preg_match( '/^O:(\d+):"/', $rest, $match ) ) {
			$start   = $offset;
			$offset += strlen( $match[0] );
			$class   = substr( $input, $offset, (int) $match[1] );
			$offset += strlen( $class );
			if ( ! preg_match( '/^":(\d+):\{/', substr( $input, $offset ), $tail ) ) {
				$this->invalid();
			}
			$offset += strlen( $tail[0] );
			$head    = substr( $input, $start, $offset - $start );
			$count   = (int) $tail[1];
			$offset  = $start;
		} elseif ( preg_match( '/^C:(\d+):"/', $rest, $match ) ) {
			// Custom Serializable payloads have their own grammar. Never instantiate them.
			$start   = $offset;
			$offset += strlen( $match[0] ) + (int) $match[1];
			if ( ! preg_match( '/^":(\d+):\{/', substr( $input, $offset ), $tail ) ) {
				$this->invalid();
			}
			$offset += strlen( $tail[0] );
			$payload = substr( $input, $offset, (int) $tail[1] );
			$offset += strlen( $payload );
			if ( '}' !== substr( $input, $offset, 1 ) ) {
				$this->invalid();
			}
			// A custom Serializable payload has its own grammar: keep it intact.
			++$offset;
			return substr( $input, $start, $offset - $start );
		} else {
			$this->invalid();
		}
		$offset += strlen( $head );
		if ( $count > strlen( $input ) ) {
			$this->invalid();
		}
		$result = $head;
		for ( $i = 0; $i < $count * 2; ++$i ) {
			$result .= $this->token( $input, $offset, $depth + 1 );
		}
		if ( '}' !== substr( $input, $offset, 1 ) ) {
			$this->invalid();
		}
		++$offset;
		return $result . '}';
	}

	/**
	 * Invalid.
	 *
	 * @return mixed Operation result, or no value for state-only actions.
	 * @throws \RuntimeException When validation or an underlying operation fails.
	 */
	private function invalid() {
		throw new RuntimeException( __( 'Malformed serialized data cannot be safely migrated.', 'boat-backup' ) );
	}
}
