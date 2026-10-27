<?php
/** Destructive recovery scenarios, guarded for disposable WordPress test sites. */

use Wocody\BoatBackup\Archive;
use Wocody\BoatBackup\Content;
use Wocody\BoatBackup\Database;
use Wocody\BoatBackup\Plugin;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'BOAT_BACKUP_TEST_SITE' ) || ! BOAT_BACKUP_TEST_SITE ) {
    throw new RuntimeException( 'Requires a disposable BOAT_BACKUP_TEST_SITE through WP-CLI.' );
}
global $wpdb;
$engine = Plugin::engine();
$db = new Database( $wpdb );
$assert = function ( $ok, $label ) { if ( ! $ok ) throw new RuntimeException( 'FAIL: ' . $label ); };
$rejects = function ( callable $fn, $label ) use ( $assert ) {
    try { $fn(); } catch ( Throwable $error ) { echo 'REJECTED ' . $label . "\n"; return; }
    $assert( false, 'accepted ' . $label );
};
$action = $args[0] ?? '';
$name = $args[1] ?? '';
switch ( $action ) {
    case 'upload':
        $path = $engine->storage->backup_path( $name );
        $job = $engine->start( 'import', 'retry.wpboat', filesize( $path ) );
        $stream = fopen( $path, 'rb' );
        $offset = 0;
        while ( ! feof( $stream ) ) {
            $bytes = fread( $stream, 32768 );
            if ( '' === $bytes ) break;
            $job = $engine->upload( $job['id'], $offset, $bytes );
            if ( 0 === $offset ) {
                $repeat = $engine->upload( $job['id'], $offset, $bytes );
                $assert( $job['upload_offset'] === $repeat['upload_offset'], 'upload replay idempotent' );
                $rejects( function () use ( $engine, $job, $bytes ) { $engine->upload( $job['id'], 0, str_repeat( 'z', strlen( $bytes ) ) ); }, 'changed repeated segment' );
                $rejects( function () use ( $engine, $job ) { $engine->upload( $job['id'], $job['upload_offset'] + 2, 'a' ); }, 'out-of-order upload' );
            }
            $offset += strlen( $bytes );
        }
        fclose( $stream );
        $job = $engine->step( $job['id'] );
        $assert( 'preflight' === $job['phase'], 'upload complete' );
        $engine->cancel( $job['id'] );
        echo "PASS upload replay and cancellation\n";
        break;
    case 'failed-commit':
        $job = $engine->current();
        $assert( $job && 'ready' === $job['phase'], 'ready job exists' );
        // A packaged name moved before the failure proves that moves are reversed.
        $sentinel = WP_CONTENT_DIR . '/uploads/ação.txt';
        file_put_contents( $sentinel, 'preserve destination' );
        // boat-extra/nested is the last packaged item; a read-only parent makes
        // that move fail after earlier items were already replaced.
        $blocked = WP_CONTENT_DIR . '/boat-extra';
        $assert( wp_mkdir_p( $blocked . '/nested' ) && chmod( $blocked, 0555 ), 'read-only destination folder created' );
        $before = $db->tables();
        try {
            $rejects( function () use ( $engine, $job ) { $engine->commit( $job['id'] ); }, 'unwritable destination during final moves' );
        } finally {
            chmod( $blocked, 0755 );
        }
        $assert( 'preserve destination' === file_get_contents( $sentinel ), 'file rollback' );
        $assert( $before === $db->tables(), 'live tables untouched' );
        $assert( 'ready' === $engine->current()['phase'], 'failed commit ready to retry' );
        $assert( ! is_file( $engine->storage->root() . '/maintenance.json' ), 'maintenance cleared after rollback' );
        echo "PASS failed commit reversed\n";
        break;
    case 'interrupt-before-swap':
    case 'interrupt-after-swap':
        $summary = $engine->current();
        $job = $engine->storage->load( $summary['id'] );
        $assert( 'ready' === $job['phase'], 'ready before simulated process death' );
        $job['old_tables'] = $db->tables();
        $job['old_plugins'] = $db->active_plugins( $wpdb->options );
        $job['phase'] = 'applying';
        $job['moves'] = array();
        $engine->storage->save( $job );
        $engine->storage->write( $engine->storage->root() . '/maintenance.json', array( 'id' => $job['id'] ) );
        $db->set_active_plugins( $wpdb->options, array( plugin_basename( BOAT_BACKUP_FILE ) ) );
        wp_cache_flush();
        ( new Content( $engine->storage ) )->apply( $job );
        if ( 'interrupt-after-swap' === $action ) {
            foreach ( $job['database'] as $i => $table ) {
                if ( 'options' === $table['table'] ) {
                    $db->set_active_plugins( $db->stage_name( $job['id'], $i ), array_values( array_unique( array_merge( array( plugin_basename( BOAT_BACKUP_FILE ) ), $job['source_plugins'] ) ) ) );
                }
            }
            $db->swap( $job );
        }
        echo 'SIMULATED PROCESS DEATH ' . $action . ' ' . $job['id'] . "\n";
        // Intentionally leave maintenance/journal for the NEXT WordPress bootstrap.
        break;
    case 'verify-before-recovery':
        $job = $engine->current();
        $assert( $job && 'ready' === $job['phase'], 'bootstrap reversed interrupted moves' );
        $assert( 'preserve destination' === file_get_contents( WP_CONTENT_DIR . '/uploads/ação.txt' ), 'bootstrap recovered destination sentinel' );
        $assert( ! is_file( $engine->storage->root() . '/maintenance.json' ), 'bootstrap removed maintenance' );
        echo "PASS interrupted commit recovery before swap\n";
        break;
    case 'verify-after-recovery':
        $assert( null === $engine->current(), 'lost-response commit completed' );
        $assert( ! is_file( $engine->storage->root() . '/maintenance.json' ), 'maintenance cleared after committed swap' );
        $assert( '日本語 ação' === file_get_contents( WP_CONTENT_DIR . '/uploads/ação.txt' ), 'source file set installed' );
        echo "PASS interrupted commit recovery after swap\n";
        break;
    default:
        throw new RuntimeException( 'Unknown failure scenario.' );
}
