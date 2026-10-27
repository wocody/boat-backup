<?php
/** Dependency-free tests of hostile input and serialization boundaries. */

use Wocody\BoatBackup\Archive;
use Wocody\BoatBackup\Content;
use Wocody\BoatBackup\Crypto;
use Wocody\BoatBackup\Database;
use Wocody\BoatBackup\Engine;
use Wocody\BoatBackup\Options;
use Wocody\BoatBackup\Rewriter;
use Wocody\BoatBackup\Storage;
use Wocody\BoatBackup\Sql;

$tmp = sys_get_temp_dir() . '/boat-unit-' . bin2hex( random_bytes( 8 ) );
mkdir( $tmp . '/public/wp-content/plugins/boat-backup', 0755, true );
define( 'ABSPATH', $tmp . '/public/' );
define( 'WP_CONTENT_DIR', $tmp . '/public/wp-content' );
define( 'BOAT_BACKUP_PATH', WP_CONTENT_DIR . '/plugins/boat-backup' );
define( 'BOAT_BACKUP_STORAGE_PATH', $tmp . '/private' );
$_SERVER['DOCUMENT_ROOT'] = ABSPATH;
function __( $text, $domain = '' ) { return $text; }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_unslash( $value ) { return $value; }
function sanitize_text_field( $value ) { return $value; }
foreach ( array( 'options', 'crypto', 'storage', 'archive', 'rewriter', 'content', 'sql', 'database', 'engine' ) as $class ) {
    require dirname( __DIR__ ) . '/includes/class-' . $class . '.php';
}
$passed = 0;
function check( $condition, $label ) {
    global $passed;
    if ( ! $condition ) throw new RuntimeException( 'FAIL: ' . $label );
    ++$passed;
}
function rejects( callable $fn, $label ) {
    try { $fn(); } catch ( Throwable $error ) { check( true, $label ); return; }
    throw new RuntimeException( 'FAIL (accepted): ' . $label );
}
class WakeupTrap {
    public $url = 'https://old.test/ação';
    public function __wakeup() { throw new RuntimeException( 'Object instantiated!' ); }
}
$rewriter = new Rewriter( array( 'https://old.test' => 'https://destination.example.test', '/old/content' => '/new/site/content' ) );
check( 'https://destination.example.test/ação' === $rewriter->replace( 'https://old.test/ação' ), 'Unicode URL replacement' );
$data = array( 'nested' => array( 'url' => 'https://old.test/ação', 'path' => '/old/content/uploads' ), 'null' => null, 'bool' => false, 'float' => 1.25 );
$expected = $data;
$expected['nested']['url'] = 'https://destination.example.test/ação';
$expected['nested']['path'] = '/new/site/content/uploads';
check( serialize( $expected ) === $rewriter->replace( serialize( $data ) ), 'serialized array lengths' );
check( serialize( serialize( $expected ) ) === $rewriter->replace( serialize( serialize( $data ) ) ), 'nested serialized strings' );
$object = serialize( new WakeupTrap() );
check( false !== strpos( $rewriter->replace( $object ), 'https://destination.example.test/ação' ), 'object bytes rewritten without wakeup' );
$reference = 'https://old.test';
$references = array( &$reference, &$reference );
check( false !== strpos( $rewriter->replace( serialize( $references ) ), 'R:2;' ), 'references retained' );
check( 'a: human text' === $rewriter->replace( 'a: human text' ), 'plain string with colon' );
// Visual Composer raw blocks and BeTheme items hold base64 payloads.
$html    = base64_encode( rawurlencode( '<a href="https://old.test/x">x</a>' ) );
$encoded = new Rewriter( array( 'https://old.test' => 'https://destination.example.test', rawurlencode( 'https://old.test' ) => rawurlencode( 'https://destination.example.test' ) ) );
$shorted = $encoded->replace( 'before https://old.test [vc_raw_html]' . $html . '[/vc_raw_html] after' );
check( 'before https://destination.example.test [vc_raw_html]' . base64_encode( rawurlencode( '<a href="https://destination.example.test/x">x</a>' ) ) . '[/vc_raw_html] after' === $shorted, 'Visual Composer raw HTML rewritten inside base64' );
check( '[vc_raw_js]not*base64[/vc_raw_js]' === $rewriter->replace( '[vc_raw_js]not*base64[/vc_raw_js]' ), 'non-base64 raw block left intact' );
$items = base64_encode( serialize( array( array( 'url' => 'https://old.test/item' ) ) ) );
check( serialize( array( array( 'url' => 'https://destination.example.test/item' ) ) ) === base64_decode( $rewriter->base64( $items ) ), 'BeTheme serialized base64 rewritten with valid lengths' );
check( 'plain text!' === $rewriter->base64( 'plain text!' ), 'invalid base64 kept' );
// Damaged serialized data is migrated with plain replacement, never stopping the restore.
check( 's:999:"https://destination.example.test";' === $rewriter->replace( 's:999:"https://old.test";' ), 'invalid serialized lengths fall back to plain replacement' );
check( 'a:1:{s:3:"url";}' === $rewriter->replace( 'a:1:{s:3:"url";}' ), 'truncated serialized array kept' );
check( 'a:2: https://destination.example.test' === $rewriter->replace( 'a:2: https://old.test' ), 'text that only looks serialized is rewritten' );
$custom = 'C:1:"X":16:{https://old.test}';
check( $custom === $rewriter->replace( $custom ), 'custom serialization kept intact' );
check( serialize( array( 'bad' => 's:9:"x";', 'url' => 'https://destination.example.test' ) ) === $rewriter->replace( serialize( array( 'bad' => 's:9:"x";', 'url' => 'https://old.test' ) ) ), 'damaged nested value does not break its valid container' );
$deep = 'https://old.test';
for ( $i = 0; $i < 70; ++$i ) { $deep = serialize( $deep ); }
check( false !== strpos( $rewriter->replace( $deep ), 'destination.example.test' ), 'excessive nesting falls back without failing' );
check( 'C:1:"X":3:{abc}' === $rewriter->replace( 'C:1:"X":3:{abc}' ), 'opaque custom serialization retained' );
foreach ( array( '../wp-config.php', '/etc/passwd', 'uploads/../bad.php', 'uploads/a/./b', 'uploads//a', 'uploads\\evil', 'uploads/a:b', "uploads/evil\0.php", 'plugins/boat-backup/payload.php', 'plugins/BOAT-BACKUP/payload.php', 'plugins/boat-backup./payload.php', 'uploads/file.txt ' ) as $path ) {
    rejects( function () use ( $path ) { Archive::content_path( $path ); }, 'unsafe path: ' . $path );
}
check( 'uploads/2026/ação.jpg' === Archive::content_path( 'uploads/2026/ação.jpg' ), 'Unicode content paths' );
foreach ( array( 'mu-plugins/site.php', 'polylang/flags/br.png', 'fv-flowplayer-custom', 'sgs_encrypt_key.php' ) as $path ) {
    check( $path === Archive::content_path( $path ), 'any wp-content folder or top-level file: ' . $path );
}
// Export options: exclusions and find/replace pairs.
$defaults = Options::normalize( array() );
check( 11 === count( array_filter( $defaults, 'is_bool' ) ) && ! in_array( true, $defaults, true ) && array() === $defaults['replace'], 'options default to a complete export' );
$options = Options::normalize( array( 'no_cache' => true, 'no_media' => '1', 'unknown' => true, 'no_plugins' => 'yes-please', 'replace' => array( array( 'find' => 'Old', 'replace' => 'New' ), array( 'find' => '', 'replace' => 'x' ), array( 'find' => 'same', 'replace' => 'same' ) ) ) );
check( $options['no_cache'] && $options['no_media'] && ! $options['no_plugins'] && ! isset( $options['unknown'] ), 'only known boolean flags are accepted' );
check( array( array( 'find' => 'Old', 'replace' => 'New' ) ) === $options['replace'], 'incomplete or identity pairs ignored' );
rejects( function () { Options::normalize( array( 'replace' => array_fill( 0, 51, array( 'find' => 'a', 'replace' => 'b' ) ) ) ); }, 'too many replace pairs' );
rejects( function () { Options::normalize( array( 'replace' => array( array( 'find' => array( 'a' ), 'replace' => 'b' ) ) ) ); }, 'non-string replace value' );
check( array( 'uploads', 'blogs.dir', 'cache' ) === Options::excluded_roots( $options ), 'media and cache roots excluded' );
$export = array( 'options' => $options, 'skip' => array( 'plugins/inactive', 'themes/old' ) );
check( Content::excluded( 'media/files', array( 'options' => $defaults, 'skip' => array( 'media/files' ) ) ), 'nested custom uploads folder skipped with media' );
foreach ( array( 'boat-backups', 'cache', 'uploads', 'ai1wm-backups', 'upgrade', 'object-cache.php', 'advanced-cache.php', 'plugins/boat-backup', 'plugins/a/.git', 'plugins/inactive', 'themes/old' ) as $path ) {
    check( Content::excluded( $path, $export ), 'excluded from export: ' . $path );
}
foreach ( array( 'plugins/a/cache', 'plugins/a/node_modules', 'mu-plugins', 'polylang', 'languages', 'sgs_encrypt_key.php', 'themes/old/sub', 'plugins/active' ) as $path ) {
    check( ! Content::excluded( $path, $export ), 'included in export: ' . $path );
}
$partition = Engine::deactivations( array( 'akismet/akismet.php', 'wps-hide-login/wps-hide-login.php', 'really-simple-ssl/rlrsssl-really-simple-ssl.php', 'renamed-really-simple-ssl-pro/rlrsssl-really-simple-ssl.php', 'other/index.php' ), false );
check( array( 'akismet/akismet.php', 'other/index.php' ) === $partition['active'] && 3 === count( $partition['deactivated'] ), 'login, CAPTCHA and SSL plugins left inactive without HTTPS' );
$partition = Engine::deactivations( array( 'really-simple-ssl/rlrsssl-really-simple-ssl.php', 'hide-my-wp/index.php' ), true );
check( array( 'really-simple-ssl/rlrsssl-really-simple-ssl.php' ) === $partition['active'], 'SSL plugins stay active on HTTPS destinations' );
foreach ( array( '', str_repeat( 'a', 65 ), "bad\0name", "bad\nname" ) as $name ) {
    rejects( function () use ( $name ) { Database::identifier( $name ); }, 'unsafe SQL identifier' );
}
// Native SQL is not restricted by column type; quoted binary values stay byte-exact.
check( '`a``b`' === Database::identifier( 'a`b' ), 'native quoted identifier' );
check( '`column.name`' === Database::identifier( 'column.name' ), 'native punctuation is quoted as a single identifier' );
check( '`' . str_repeat( 'ã', 64 ) . '`' === Database::identifier( str_repeat( 'ã', 64 ) ), 'identifier bounds count Unicode characters' );
$native = "CREATE TABLE `boat_backup_dump_0` (`id` int, `shape` point NOT NULL, `other` geometry, `calc` int GENERATED ALWAYS AS ((`id` + 1)) VIRTUAL, SPATIAL KEY `shape_index` (`shape`), CONSTRAINT `fk` FOREIGN KEY (`id`) REFERENCES `parent` (`id`)) ENGINE=Aria TRANSACTIONAL=1 ROW_FORMAT=PAGE COMMENT='ENGINE=Aria; keep literal'";
$adapted = Database::compatible_ddl( $native );
check( false !== strpos( $adapted, 'point' ) && false !== strpos( $adapted, 'SPATIAL KEY' ) && false !== strpos( $adapted, 'GENERATED ALWAYS' ), 'native spatial/generated definitions survive' );
check( false === strpos( $adapted, 'FOREIGN KEY' ) && false === strpos( $adapted, 'TRANSACTIONAL' ) && false === strpos( $adapted, 'ROW_FORMAT' ), 'reference compatibility options and constraints removed' );
check( 'MyISAM' === Database::ddl_engine( $adapted ), 'Aria normalized to MyISAM' );
check( false !== strpos( $adapted, "'ENGINE=Aria; keep literal'" ), 'DDL literals are not rewritten as options' );
check( 'InnoDB' === Database::ddl_engine( Database::compatible_ddl( 'CREATE TABLE `boat_backup_dump_0` (`a` int) TYPE=InnoDB' ) ), 'native TYPE syntax normalized' );
$tables = array( array( 'table' => 'native' ) );
check( 'create' === Database::plan( Sql::tokens( $adapted ), $tables )['kind'], 'native definition accepted without a type grammar' );
$bytes = "\0\xff\\'\nhttps://old.test/geometry;\x1a";
$literal = "'" . strtr( $bytes, array( "\0" => '\\0', "\n" => '\\n', "\r" => '\\r', '\\' => '\\\\', "'" => "\\'", "\x1a" => '\\Z' ) ) . "'";
$insert = 'INSERT INTO `boat_backup_dump_0` (`shape`,`nothing`) VALUES (' . $literal . ',NULL)';
$plan = Database::plan( Sql::tokens( $insert ), $tables );
check( $bytes === $plan['values'][0] && null === $plan['values'][1], 'opaque bytes and SQL NULL roundtrip through the lexer' );
check( "a'b" === Sql::value( "'a''b'" ), 'doubled quote literal' );
$hex = Database::plan( Sql::tokens( 'INSERT INTO `boat_backup_dump_0` (`bits`) VALUES (0x81)' ), $tables );
check( "\x81" === $hex['values'][0] && $hex['hex'][0], 'native bit hexadecimal literal is byte-exact' );
foreach ( array( 'DROP DATABASE site', 'DELETE FROM `boat_backup_dump_0`', 'DROP TABLE IF EXISTS `wp_posts`', 'INSERT INTO `boat_backup_dump_0` (`a`) VALUES (NOW())', 'INSERT INTO `boat_backup_dump_0` (`a`,`a`) VALUES (NULL,NULL)', 'CREATE TABLE `boat_backup_dump_0` (`a` int) AS SELECT * FROM users', "CREATE TABLE `boat_backup_dump_0` (`a` int) DATA DIRECTORY='/tmp'" ) as $sql ) {
    rejects( function () use ( $sql, $tables ) { Database::plan( Sql::tokens( $sql ), $tables ); }, 'foreign SQL target or unsupported operation rejected' );
}
$stream = fopen( 'php://temp', 'w+b' );
fwrite( $stream, "-- header\nCREATE TABLE `boat_backup_dump_0` (\n`comment` text COMMENT 'a;\nb'\n) ENGINE=InnoDB;\n" . $insert . ";\n" ); rewind( $stream );
check( 'create' === Database::plan( Sql::read( $stream, 65536 ), $tables )['kind'], 'multiline native DDL and quoted semicolon read as one statement' );
check( $bytes === Database::plan( Sql::read( $stream, 65536 ), $tables )['values'][0], 'binary insert read after multiline DDL' );
check( null === Sql::read( $stream, 65536 ), 'clean SQL EOF' ); fclose( $stream );
foreach ( array( "INSERT INTO `boat_backup_dump_0` (`a`) VALUES ('unfinished", 'COMMIT; DROP DATABASE test;', 'COMMIT' ) as $sql ) {
    rejects( function () use ( $sql ) { $stream = fopen( 'php://temp', 'w+b' ); fwrite( $stream, $sql ); rewind( $stream ); try { Sql::read( $stream, 65536 ); } finally { fclose( $stream ); } }, 'truncated or combined SQL statements rejected' );
}
$storage = new Storage();
file_put_contents( $storage->backup_path( 'labelled.wpboat' ), 'x' );
$storage->label( 'labelled.wpboat', 'Before redesign' );
check( 'Before redesign' === array_column( $storage->backups(), 'label', 'name' )['labelled.wpboat'], 'backup label listed' );
$storage->label( 'labelled.wpboat', '' );
check( '' === array_column( $storage->backups(), 'label', 'name' )['labelled.wpboat'], 'backup label removed' );
rejects( function () use ( $storage ) { $storage->label( 'missing.wpboat', 'x' ); }, 'label for a missing backup' );
unlink( $storage->backup_path( 'labelled.wpboat' ) );
check( ! Storage::within( $tmp . '/public-sibling', $tmp . '/public' ), 'path segment boundary' );
rejects( function () use ( $storage ) { $storage->backup_path( '../private/site.json' ); }, 'backup path traversal' );
rejects( function () use ( $storage ) { $storage->job_path( '../x' ); }, 'job path traversal' );
rejects( function () use ( $storage ) { $storage->remove_tree( ABSPATH ); }, 'unowned deletion' );
$lock = $storage->lock();
rejects( function () use ( $storage ) { $storage->lock(); }, 'concurrent lock' );
fclose( $lock );
$manifest = array( 'format' => 'boat-backup', 'version' => Archive::VERSION, 'options' => Options::normalize( array() ), 'database' => array( 'bytes' => 1, 'tables' => array( array( 'table' => 'options', 'engine' => 'InnoDB', 'dump_engine' => 'InnoDB' ) ) ), 'records' => 1, 'bytes' => 5, 'source' => array( 'home' => 'https://old.test', 'siteurl' => 'https://old.test', 'home_url' => 'https://old.test', 'site_url' => 'https://old.test', 'content' => '/old/content', 'prefix' => 'old_', 'content_url' => 'https://old.test/wp-content', 'wp_version' => '6.0', 'db_version' => 1 ) );
$package = $tmp . '/package.wpboat';
$zip = new Archive( $package, true );
$zip->put( 0, array( 'type' => 'file', 'path' => 'uploads/file.txt', 'offset' => 0, 'total' => 5, 'mtime' => 1 ), 'hello' );
$zip->finish( $manifest );
$zip->close();
foreach ( array( 1, 2, 3 ) as $obsolete ) {
    $manifest['version'] = $obsolete;
    $old = new Archive( $tmp . '/obsolete-' . $obsolete . '.wpboat', true );
    $old->put( 0, array( 'type' => 'file', 'path' => 'uploads/file.txt', 'offset' => 0, 'total' => 5, 'mtime' => 1 ), 'hello' );
    $old->finish( $manifest ); $old->close();
    $old = new Archive( $tmp . '/obsolete-' . $obsolete . '.wpboat' );
    rejects( function () use ( $old ) { $old->manifest(); }, 'obsolete package version refused' ); $old->close();
}
$manifest['version'] = Archive::VERSION;
$variants = array(
    'missing options'           => array( 'options' => null ),
    'database flag without SQL' => array( 'database' => array( 'bytes' => 0, 'tables' => array() ) ),
    'files-only package with SQL' => array( 'options' => Options::normalize( array( 'no_database' => true ) ) ),
);
foreach ( $variants as $label => $change ) {
    $variant = array_filter( array_merge( $manifest, $change ), static function ( $value ) { return null !== $value; } );
    $other   = new Archive( $tmp . '/variant.wpboat', true );
    $other->put( 0, array( 'type' => 'file', 'path' => 'uploads/file.txt', 'offset' => 0, 'total' => 5, 'mtime' => 1 ), 'hello' );
    $other->finish( $variant ); $other->close();
    $other = new Archive( $tmp . '/variant.wpboat' );
    rejects( function () use ( $other ) { $other->manifest(); }, 'manifest rejected: ' . $label ); $other->close();
    unlink( $tmp . '/variant.wpboat' );
}
$files_only = array_merge( $manifest, array( 'options' => Options::normalize( array( 'no_database' => true ) ), 'database' => array( 'bytes' => 0, 'tables' => array() ) ) );
$other      = new Archive( $tmp . '/files-only.wpboat', true );
$other->put( 0, array( 'type' => 'file', 'path' => 'uploads/file.txt', 'offset' => 0, 'total' => 5, 'mtime' => 1 ), 'hello' );
$other->finish( $files_only ); $other->close();
$other = new Archive( $tmp . '/files-only.wpboat' );
check( $other->manifest()['options']['no_database'], 'files-only package accepted without SQL' ); $other->close();
$zip = new Archive( $package );
check( 1 === $zip->manifest()['records'], 'package manifest roundtrip' );
// Password protection: AES-256-GCM segments bound to their record names.
if ( Crypto::available() ) {
    list( $params, $key ) = Crypto::create( 'correct horse' );
    check( $key === Crypto::unlock( Crypto::params( $params ), 'correct horse' ), 'password unlocks its key' );
    rejects( function () use ( $params ) { Crypto::unlock( $params, 'wrong' ); }, 'wrong password refused' );
    rejects( function () use ( $params ) { Crypto::params( array_merge( $params, array( 'iterations' => 10 ) ) ); }, 'weak key derivation refused' );
    rejects( function () { Crypto::create( '' ); }, 'empty password refused' );
    $sealed = $tmp . '/sealed.wpboat';
    $secret = new Archive( $sealed, true );
    check( 6 === $secret->put( 0, array( 'type' => 'file', 'path' => 'uploads/a.txt', 'offset' => 0, 'total' => 6, 'mtime' => 1 ), 'secret', $key ), 'protected put reports plain bytes' );
    $secret->put( 1, array( 'type' => 'file', 'path' => 'uploads/b.txt', 'offset' => 0, 'total' => 5, 'mtime' => 1 ), 'other', $key );
    $secret->finish( array_merge( $manifest, array( 'records' => 2, 'bytes' => 11, 'encryption' => $params ) ) );
    $secret->close();
    $raw_sealed = new ZipArchive();
    $raw_sealed->open( $sealed );
    check( false === strpos( $raw_sealed->getFromName( 'data/0' ), 'secret' ) && false === strpos( $raw_sealed->getFromName( 'index/0.json' ), hash( 'sha256', 'secret' ) ), 'protected segment and descriptor reveal no plaintext' );
    $raw_sealed->close();
    $secret = new Archive( $sealed );
    check( isset( $secret->manifest()['encryption'] ) && 'secret' === $secret->record( 0, $key )[1], 'protected segment roundtrip' );
    rejects( function () use ( $secret ) { $secret->record( 0, str_repeat( "\0", 32 ) ); }, 'wrong key cannot read a segment' );
    $secret->close();
    // Swapping two complete records (data and descriptor) fails authentication.
    $raw_sealed->open( $sealed );
    $d0 = $raw_sealed->getFromName( 'data/0' ); $i0 = $raw_sealed->getFromName( 'index/0.json' );
    $raw_sealed->addFromString( 'data/0', $raw_sealed->getFromName( 'data/1' ) ); $raw_sealed->addFromString( 'index/0.json', $raw_sealed->getFromName( 'index/1.json' ) );
    $raw_sealed->addFromString( 'data/1', $d0 ); $raw_sealed->addFromString( 'index/1.json', $i0 );
    $raw_sealed->close();
    $secret = new Archive( $sealed );
    rejects( function () use ( $secret, $key ) { $secret->record( 0, $key ); }, 'reordered protected records refused' );
    $secret->close();
}
check( 'hello' === $zip->record( 0 )[1], 'record checksum roundtrip' );
$zip->close();
$link_package = $tmp . '/symlink.wpboat';
$linked = new Archive( $link_package, true );
$linked->put( 0, array( 'type' => 'file', 'path' => 'uploads/file.txt', 'offset' => 0, 'total' => 5, 'mtime' => 1 ), 'hello' );
$linked->finish( $manifest );
$linked->close();
$raw_link = new ZipArchive();
$raw_link->open( $link_package );
$raw_link->setExternalAttributesName( 'data/0', ZipArchive::OPSYS_UNIX, 0120777 << 16 );
$raw_link->close();
$linked = new Archive( $link_package );
rejects( function () use ( $linked ) { $linked->record( 0 ); }, 'ZIP data symbolic link' );
$linked->close();
$raw_link->open( $link_package );
$raw_link->setExternalAttributesName( 'manifest.json', ZipArchive::OPSYS_UNIX, 0120777 << 16 );
$raw_link->close();
$linked = new Archive( $link_package );
rejects( function () use ( $linked ) { $linked->manifest(); }, 'ZIP manifest symbolic link' );
$linked->close();
$raw = new ZipArchive();
$raw->open( $package );
$raw->addFromString( 'data/0', 'world' );
$raw->close();
$zip = new Archive( $package );
rejects( function () use ( $zip ) { $zip->record( 0 ); }, 'checksum corruption' );
$zip->close();
file_put_contents( $tmp . '/truncated.wpboat', substr( file_get_contents( $package ), 0, 30 ) );
rejects( function () use ( $tmp ) { new Archive( $tmp . '/truncated.wpboat' ); }, 'truncated ZIP' );
$raw->open( $package );
$raw->addFromString( '../evil.php', 'bad' );
$raw->close();
$zip = new Archive( $package );
rejects( function () use ( $zip ) { $zip->manifest(); }, 'undeclared ZIP entry' );
$zip->close();
$id = str_repeat( 'a', 32 );
$storage->mkdir( $storage->job_path( $id ) );
$content = new Content( $storage );
$record = array( 'type' => 'file', 'path' => 'uploads/empty.txt', 'total' => 0, 'offset' => 0, 'mtime' => 1 );
$content->stage( array( 'id' => $id ), 1, $record, '' );
$content->stage( array( 'id' => $id ), 1, $record, '' );
check( is_file( $storage->job_path( $id ) . '/staging/uploads/empty.txt' ), 'zero-byte file and same-record retry' );
rejects( function () use ( $content, $record, $id ) { $content->stage( array( 'id' => $id ), 2, $record, '' ); }, 'duplicate empty file' );
$record = array( 'type' => 'file', 'path' => 'uploads/parts.txt', 'total' => 6, 'offset' => 0, 'mtime' => 1 );
$content->stage( array( 'id' => $id ), 3, $record, 'abc' );
$record['offset'] = 4;
rejects( function () use ( $content, $record, $id ) { $content->stage( array( 'id' => $id ), 4, $record, 'ef' ); }, 'out-of-order file segment' );
$record['offset'] = 3;
$content->stage( array( 'id' => $id ), 4, $record, 'def' );
check( 'abcdef' === file_get_contents( $storage->job_path( $id ) . '/staging/uploads/parts.txt' ), 'segmented file reconstruction' );
check( 0755 === ( fileperms( $storage->job_path( $id ) . '/staging/uploads' ) & 0777 ), 'published directory permissions' );
// Symbolic links are exported as their targets, without loops; broken links are skipped.
$links = WP_CONTENT_DIR . '/linked';
mkdir( $links . '/real/x', 0755, true );
mkdir( $links . '/real/y', 0755, true );
file_put_contents( $links . '/real/file.txt', 'target' );
symlink( 'real/file.txt', $links . '/file-link' );
symlink( 'real', $links . '/dir-link' );
symlink( 'missing', $links . '/broken' );
symlink( '..', $links . '/real/up' );
symlink( '../y', $links . '/real/x/to-y' );
symlink( '../x', $links . '/real/y/to-x' );
$scan_id = str_repeat( 'b', 32 );
$storage->mkdir( $storage->job_path( $scan_id ) );
$scan_job = array( 'id' => $scan_id, 'options' => $defaults, 'inventory_bytes' => 0, 'file_records' => 0, 'scan' => array( array( 'path' => 'linked', 'offset' => 0 ) ) );
for ( $i = 0; $i < 100 && $scan_job['scan']; ++$i ) {
    $content->scan( $scan_job );
}
$scanned = array();
foreach ( file( $storage->job_path( $scan_id ) . '/inventory.ndjson' ) as $line ) {
    $item = json_decode( $line, true );
    $scanned[ $item['path'] ] = $item['type'];
}
check( ! $scan_job['scan'], 'link cycles terminate' );
check( 'file' === ( $scanned['linked/file-link'] ?? '' ) && 'file' === ( $scanned['linked/dir-link/file.txt'] ?? '' ), 'file and folder links exported as their targets' );
check( ! isset( $scanned['linked/broken'] ) && ! isset( $scanned['linked/real/up'] ), 'broken and ancestor links skipped' );
check( isset( $scanned['linked/real/x/to-y/to-x'] ) && ! preg_grep( '~to-(?:x/to-y/to-x|y/to-x/to-y)~', array_keys( $scanned ) ), 'mutually linked folders stop when a chain revisits a folder' );
$scan_job['file_cursor'] = 0;
$scan_job['file_offset'] = 0;
$exported = array();
while ( null !== ( $record = $content->export_record( $scan_job ) ) ) {
    $exported[ $record[0]['path'] ] = $record[1];
}
check( 'target' === $exported['linked/file-link'], 'linked file content exported' );
// Files that change, vanish or have unusual names never stop an export.
$live = WP_CONTENT_DIR . '/changing';
mkdir( $live . '/locked', 0755, true );
file_put_contents( $live . '/grows.log', 'one' );
file_put_contents( $live . '/vanishes.txt', 'x' );
file_put_contents( $live . "/bad\nname.txt", 'x' );
file_put_contents( $live . '/colon:name.txt', 'x' );
file_put_contents( $live . '/locked/secret.txt', 'x' );
chmod( $live . '/locked', 0000 );
$change_id  = str_repeat( 'd', 32 );
$storage->mkdir( $storage->job_path( $change_id ) );
$change_job = array( 'id' => $change_id, 'options' => $defaults, 'inventory_bytes' => 0, 'file_records' => 0, 'scan' => array( array( 'path' => 'changing', 'offset' => 0 ) ), 'file_cursor' => 0, 'file_offset' => 0 );
while ( $change_job['scan'] ) {
    $content->scan( $change_job );
}
chmod( $live . '/locked', 0755 );
file_put_contents( $live . '/grows.log', 'one two three' );
unlink( $live . '/vanishes.txt' );
$exported = array();
while ( null !== ( $record = $content->export_record( $change_job ) ) ) {
    $exported[ $record[0]['path'] ] = array( $record[0]['total'] ?? null, $record[1] );
}
check( array( 13, 'one two three' ) === $exported['changing/grows.log'], 'file changed after listing exported as it is now' );
check( ! isset( $exported['changing/vanishes.txt'] ), 'file removed after listing skipped' );
check( ! isset( $exported['changing/colon:name.txt'] ) && ! preg_grep( '~bad~', array_keys( $exported ) ) && ! isset( $change_job['warnings'] ), 'unsupported names skipped silently' );
if ( function_exists( 'posix_geteuid' ) && 0 !== posix_geteuid() ) {
    check( ! isset( $exported['changing/locked/secret.txt'] ) && isset( $exported['changing/locked'] ), 'unreadable folder skipped silently' );
}
// Restores overlay first-level items: same names are replaced, destination-only items stay.
foreach ( array( 'plugins/kept/a.php' => 'x', 'mu-plugins/gd-system-plugin.php' => 'x', 'mu-plugins/site.php' => 'x', 'polylang/flag.png' => 'x', 'top.php' => 'x', 'object-cache.php' => 'x' ) as $path => $bytes ) {
    $content->stage( array( 'id' => $id ), 100 + strlen( $path ), array( 'type' => 'file', 'path' => $path, 'total' => 1, 'offset' => 0, 'mtime' => 1 ), $bytes );
}
$content->stage( array( 'id' => $id ), 300, array( 'type' => 'directory', 'path' => 'empty-root' ), '' );
check( array( 'empty-root', 'mu-plugins/site.php', 'plugins/kept', 'polylang/flag.png', 'top.php', 'uploads/empty.txt', 'uploads/parts.txt' ) === $content->moves( array( 'id' => $id ) ), 'overlay plan skips drop-ins and hosting must-use plugins' );
// Exercise the error-1030 retry and capability-based collation policy.
require __DIR__ . '/driver.php';
$connection = new BoatWpdbDouble();
$database = new Database($connection);
$filters = Options::normalize( array( 'no_spam_comments' => true, 'no_post_revisions' => true ) );
check( " WHERE `comment_approved` <> 'spam'" === $database->where( 'comments', $filters ) && false !== strpos( $database->where( 'commentmeta', $filters ), '`wp_comments`' ), 'spam comments and their metadata filtered' );
check( " WHERE `post_type` <> 'revision'" === $database->where( 'posts', $filters ) && false !== strpos( $database->where( 'postmeta', $filters ), "`post_type` = 'revision'" ), 'post revisions and their metadata filtered' );
check( '' === $database->where( 'posts', $defaults ) && '' === $database->where( 'options', $filters ), 'other tables and default exports are unfiltered' );
$ddl = 'CREATE TABLE `boat_backup_dump_0` (`id` int, `shape` point) ENGINE=Aria DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci TRANSACTIONAL=1';
$job = array('id'=>str_repeat('c',32), 'database'=>array(array('table'=>'native')));
$plan = Database::plan(Sql::tokens($ddl), $job['database']);
$database->apply($plan,$job,new Rewriter(array()));
check(false!==strpos($connection->dbh->queries[0],'ENGINE = MyISAM'), 'Aria normalized before native DDL execution');
check(false!==strpos($connection->dbh->queries[1],'ENGINE=InnoDB'), 'MySQL error 1030 retries as InnoDB');
check(false!==strpos($connection->dbh->queries[0],'utf8_unicode_ci')&&false===strpos($connection->dbh->queries[0],'utf8mb4'), 'destination capabilities adapt utf8mb4 collations');
check('InnoDB'===$job['database'][0]['target_engine'], 'actual target engine reported after retry');
$connection = new BoatWpdbDouble();$connection->dbh->fail=1286;$database=new Database($connection);
rejects(function()use($database,$plan,&$job){$database->apply($plan,$job,new Rewriter(array()));}, 'other SQL errors stop staging');
check(1===count($connection->dbh->queries), 'non-1030 error never triggers a silent engine retry');
// Collations unknown to the destination fall back to the closest supported ones.
$known = array( 'utf8mb4_unicode_520_ci' => 'utf8mb4', 'utf8mb4_unicode_ci' => 'utf8mb4', 'utf8mb4_bin' => 'utf8mb4', 'utf8_general_ci' => 'utf8', 'latin1_swedish_ci' => 'latin1' );
check( 'utf8mb4_unicode_520_ci' === Database::collation( 'utf8mb4_0900_ai_ci', $known ), 'MySQL 8 collation adapted' );
check( 'utf8mb4_bin' === Database::collation( 'utf8mb4_0900_bin', $known ), 'binary collation stays binary' );
check( 'utf8_general_ci' === Database::collation( 'utf8mb3_general_ci', $known ) && 'utf8' === Database::charset( 'utf8mb3', $known ), 'utf8mb3 names adapted for older servers' );
check( 'utf8mb4_unicode_520_ci' === Database::collation( 'utf8mb4_uca1400_ai_ci', $known ), 'MariaDB uca1400 collation adapted' );
check( 'latin1_swedish_ci' === Database::collation( 'latin1_swedish_ci', $known ) && 'koi8r_bin' === Database::collation( 'koi8r_bin', $known ), 'supported or unmatched collations unchanged' );
$connection = new BoatWpdbDouble(); $connection->dbh->fail = 0; $connection->dbh->collations = array( array( 'Collation' => 'utf8mb4_unicode_520_ci', 'Charset' => 'utf8mb4' ), array( 'Collation' => 'utf8_general_ci', 'Charset' => 'utf8' ) );
$database = new Database( $connection );
$adapted  = $database->adapt_charsets( "CREATE TABLE `boat_backup_dump_0` (`a` varchar(9) CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci COMMENT 'COLLATE utf8mb4_0900_ai_ci') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci" );
check( false !== strpos( $adapted, 'SET utf8 COLLATE utf8_general_ci' ) && false !== strpos( $adapted, 'COLLATE = utf8mb4_unicode_520_ci' ) && false !== strpos( $adapted, "'COLLATE utf8mb4_0900_ai_ci'" ), 'DDL collations adapted outside literals' );
// Cleanup stays within the random, process-owned temporary test directory.
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $tmp, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
foreach ( $iterator as $entry ) { $entry->isDir() && ! $entry->isLink() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() ); }
rmdir( $tmp );
echo "PASS: {$passed} assertions\n";
