<?php
/** Build only explicitly allowlisted runtime files. */
$root = dirname( __DIR__ );
$version = '0.3.0';
if ( ! is_dir( $root . '/dist' ) ) {
	mkdir( $root . '/dist', 0755 );
}
$path = $root . '/dist/boat-backup-' . $version . '.zip';
$zip = new ZipArchive();
if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	throw new RuntimeException( 'Unable to create distribution ZIP.' );
}
$files = array( 'boat-backup.php', 'uninstall.php', 'readme.txt', 'LICENSE', 'CHANGELOG.md' );
$extensions = array( 'includes' => array( 'php' ), 'assets' => array( 'js', 'css' ), 'languages' => array( 'pot', 'po', 'mo' ) );
foreach ( array( 'includes', 'assets', 'languages' ) as $directory ) {
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $directory, FilesystemIterator::SKIP_DOTS ) ) as $entry ) {
		if ( $entry->isFile() && ! $entry->isLink() && in_array( $entry->getExtension(), $extensions[ $directory ], true ) ) {
			$files[] = substr( $entry->getPathname(), strlen( $root ) + 1 );
		}
	}
}
sort( $files );
foreach ( $files as $file ) {
	if ( ! is_file( $root . '/' . $file ) || ! $zip->addFile( $root . '/' . $file, 'boat-backup/' . $file ) ) {
		throw new RuntimeException( 'Required distribution file missing: ' . $file );
	}
}
if ( ! $zip->close() ) {
	throw new RuntimeException( 'Unable to finish distribution ZIP.' );
}
echo $path . PHP_EOL;
