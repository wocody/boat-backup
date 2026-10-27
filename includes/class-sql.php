<?php
/**
 * Byte-preserving SQL dump lexer and statement reader.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions, Generic.CodeAnalysis.AssignmentInCondition
 *
 * @package Wocody\BoatBackup
 */

namespace Wocody\BoatBackup;

use RuntimeException;

/** Lex SQL without interpreting column types or decoding binary bytes as UTF-8. */
final class Sql {

	/**
	 * Read one complete statement, including multiline native CREATE definitions.
	 *
	 * @param resource $stream Dump stream.
	 * @param int      $limit Host-dependent statement limit.
	 * @return array|null Tokens, or null at a clean end of file.
	 * @throws RuntimeException When truncated, oversized or containing extra statements.
	 */
	public static function read( $stream, $limit ) {
		$sql = '';
		while ( false !== ( $line = fgets( $stream, $limit + 2 ) ) ) {
			$sql .= $line;
			if ( strlen( $sql ) > $limit ) {
				throw new RuntimeException( __( 'A SQL statement exceeds the available PHP memory or database packet limit.', 'boat-backup' ) );
			}
			$tokens = self::tokens( $sql, true );
			if ( null === $tokens ) {
				continue;
			}
			$ends = array_keys( array_column( $tokens, 'raw' ), ';', true );
			if ( $ends ) {
				if ( 1 !== count( $ends ) || count( $tokens ) - 1 !== $ends[0] ) {
					throw new RuntimeException( __( 'The SQL dump contains an unexpected statement boundary.', 'boat-backup' ) );
				}
				array_pop( $tokens );
				return $tokens;
			}
		}
		if ( ! feof( $stream ) || null === self::tokens( $sql, true ) || self::tokens( $sql ) ) {
			throw new RuntimeException( __( 'The SQL dump ends inside an unfinished statement.', 'boat-backup' ) );
		}
		return null;
	}

	/**
	 * Tokenize syntax, preserving exact quoted strings and versioned DDL comments.
	 *
	 * @param string $sql SQL bytes.
	 * @param bool   $partial Whether an unfinished token may return null.
	 * @return array|null Typed raw tokens.
	 * @throws RuntimeException When a quoted token is incomplete.
	 */
	public static function tokens( $sql, $partial = false ) {
		$result = array();
		$length = strlen( $sql );
		for ( $i = 0; $i < $length; ) {
			$c = $sql[ $i ];
			if ( false !== strpos( " \t\r\n\f", $c ) ) {
				++$i;
				continue;
			}
			if ( '#' === $c || ( '--' === substr( $sql, $i, 2 ) && ( $i + 2 === $length || ord( $sql[ $i + 2 ] ) <= 32 ) ) ) {
				$end = strpos( $sql, "\n", $i );
				$i   = false === $end ? $length : $end + 1;
				continue;
			}
			if ( '/*' === substr( $sql, $i, 2 ) ) {
				$end = strpos( $sql, '*/', $i + 2 );
				if ( false === $end ) {
					return self::unfinished( $partial );
				}
				if ( '!' === ( $sql[ $i + 2 ] ?? '' ) ) {
					$result[] = array(
						'kind' => 'comment',
						'raw'  => substr( $sql, $i, $end + 2 - $i ),
					);
				}
				$i = $end + 2;
				continue;
			}
			$start = $i++;
			if ( "'" === $c || '"' === $c || '`' === $c ) {
				$closed = false;
				while ( $i < $length ) {
					if ( '\\' === $sql[ $i ] && '`' !== $c ) {
						$i += 2;
					} elseif ( $c === $sql[ $i ] ) {
						++$i;
						if ( $i < $length && $c === $sql[ $i ] ) {
							++$i;
						} else {
							$closed = true;
							break;
						}
					} else {
						++$i;
					}
				}
				if ( ! $closed ) {
					return self::unfinished( $partial );
				}
				$kind = '`' === $c ? 'identifier' : 'string';
			} elseif ( preg_match( '/[A-Za-z0-9_$]/', $c ) ) {
				while ( $i < $length && preg_match( '/[A-Za-z0-9_$]/', $sql[ $i ] ) ) {
					++$i;
				}
				$kind = 'word';
			} else {
				$kind = 'symbol';
			}
			$result[] = array(
				'kind' => $kind,
				'raw'  => substr( $sql, $start, $i - $start ),
			);
		}
		return $result;
	}

	/**
	 * Signal an incomplete quoted token.
	 *
	 * @param bool $partial Whether to defer until the next input line.
	 * @return null
	 * @throws RuntimeException When the caller expects complete SQL.
	 */
	private static function unfinished( $partial ) {
		if ( $partial ) {
			return null;
		}
		throw new RuntimeException( __( 'The SQL dump contains an unfinished quoted value or comment.', 'boat-backup' ) );
	}

	/**
	 * Render syntax without modifying the contents of quoted strings.
	 *
	 * @param array $tokens Tokens.
	 * @return string SQL statement without a terminator.
	 */
	public static function render( array $tokens ) {
		return implode( ' ', array_column( $tokens, 'raw' ) );
	}

	/**
	 * Decode the SQL literal escapes emitted by the native MySQL driver.
	 *
	 * @param string $literal Quoted SQL bytes.
	 * @return string Unescaped bytes, including NUL and invalid UTF-8.
	 */
	public static function value( $literal ) {
		$quote = $literal[0];
		$value = substr( $literal, 1, -1 );
		return strtr(
			$value,
			array(
				'\\0'           => "\0",
				'\\n'           => "\n",
				'\\r'           => "\r",
				'\\b'           => "\x08",
				'\\t'           => "\t",
				'\\Z'           => "\x1a",
				'\\\\'          => '\\',
				"\\'"           => "'",
				'\\"'           => '"',
				$quote . $quote => $quote,
			)
		);
	}
	/**
	 * Split a parenthesized list while preserving nested native SQL expressions.
	 *
	 * @param array $tokens SQL tokens.
	 * @param int   $offset Cursor at an opening parenthesis, advanced past its close.
	 * @return array List items as token arrays.
	 * @throws RuntimeException When delimiters are unbalanced.
	 */
	public static function group( array $tokens, &$offset ) {
		if ( '(' !== ( $tokens[ $offset ]['raw'] ?? '' ) ) {
			throw new RuntimeException( __( 'The SQL dump contains an invalid parenthesized list.', 'boat-backup' ) );
		}
		$depth = 1;
		$items = array();
		$item  = array();
		while ( isset( $tokens[ ++$offset ] ) ) {
			$token = $tokens[ $offset ];
			if ( 'symbol' === $token['kind'] ) {
				if ( '(' === $token['raw'] ) {
					++$depth;
				} elseif ( ')' === $token['raw'] ) {
					if ( 0 === --$depth ) {
						if ( $item ) {
							$items[] = $item;
						}
						++$offset;
						return $items;
					}
				} elseif ( ',' === $token['raw'] && 1 === $depth ) {
					$items[] = $item;
					$item    = array();
					continue;
				}
			}
			$item[] = $token;
		}
		throw new RuntimeException( __( 'The SQL dump contains unbalanced parentheses.', 'boat-backup' ) );
	}
}
