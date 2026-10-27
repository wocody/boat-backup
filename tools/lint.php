<?php
/** Check shipped PHP and test/tool syntax without traversing vendor. */
$root = dirname( __DIR__ );
$files = array_merge( glob( $root . '/*.php' ), glob( $root . '/includes/*.php' ), glob( $root . '/tests/*.php' ), glob( $root . '/tools/*.php' ) );
foreach ( $files as $file ) {
	passthru( escapeshellarg( PHP_BINARY ) . ' -n -l ' . escapeshellarg( $file ), $code );
	if ( $code ) {
		exit( $code );
	}
}
