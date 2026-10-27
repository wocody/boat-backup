<?php
/** Run ONLY against disposable WordPress installations: wp eval-file ... <action>. */

use Wocody\BoatBackup\Database;
use Wocody\BoatBackup\Plugin;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'BOAT_BACKUP_TEST_SITE' ) || ! BOAT_BACKUP_TEST_SITE ) {
    throw new RuntimeException( 'Requires WP-CLI and an explicit BOAT_BACKUP_TEST_SITE constant in a disposable wp-config.php.' );
}
$action = $args[0] ?? '';
global $wpdb;
$engine = Plugin::engine();
$fail = function ( $ok, $label ) { if ( ! $ok ) throw new RuntimeException( 'FAIL: ' . $label ); };
switch ( $action ) {
    case 'seed':
        wp_insert_post( array( 'post_title' => 'Migração ação 日本語', 'post_name' => 'boat-fixture', 'post_status' => 'publish', 'post_content' => home_url( '/example/' ) ) );
        $object = new stdClass();
        $object->url = home_url( '/object/' );
        update_option( 'boat_backup_fixture', array( 'url' => home_url( '/ação/' ), 'path' => WP_CONTENT_DIR . '/uploads/fixture.bin', 'nested' => serialize( array( 'url' => home_url( '/nested/' ) ) ), 'object' => $object, 'json' => wp_json_encode( array( 'url' => home_url( '/json/' ) ) ) ) );
        $wpdb->query( 'CREATE TABLE ' . Database::identifier( $wpdb->prefix . 'fixture' ) . " (id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, body longtext NOT NULL, binary_value longblob, choice enum('one','two') NOT NULL DEFAULT 'one', label varchar(32) DEFAULT NULL, UNIQUE KEY label_key (label)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci" );
        $wpdb->insert( $wpdb->prefix . 'fixture', array( 'body' => serialize( array( 'url' => home_url( '/custom/' ) ) ), 'binary_value' => "\x00\xffhttps://old.test\x00", 'choice' => 'two', 'label' => 'ação' ) );
        $wpdb->query( 'ALTER TABLE ' . Database::identifier( $wpdb->prefix . 'fixture' ) . ' AUTO_INCREMENT = 5000' );
        update_option( 'boat_backup_fixture_next_id', 5000 );
        wp_mkdir_p( WP_CONTENT_DIR . '/uploads' );
        $stream = fopen( WP_CONTENT_DIR . '/uploads/fixture.bin', 'wb' );
        for ( $i = 0; $i < 80; ++$i ) fwrite( $stream, hash( 'sha512', 'fixture-' . $i, true ) . str_repeat( 'x', 131008 ) );
        fclose( $stream );
        // Keep the compressed package larger than one HTTP upload segment.
        file_put_contents( WP_CONTENT_DIR . '/uploads/upload-parts.bin', random_bytes( 2 * 1024 * 1024 ) );
        file_put_contents( WP_CONTENT_DIR . '/uploads/empty.txt', '' );
        file_put_contents( WP_CONTENT_DIR . '/uploads/ação.txt', '日本語 ação' );
        // Content outside the classic roots migrates like any other wp-content folder.
        wp_mkdir_p( WP_CONTENT_DIR . '/boat-extra/nested' );
        file_put_contents( WP_CONTENT_DIR . '/boat-extra/nested/data.txt', 'extra' );
        wp_mkdir_p( WPMU_PLUGIN_DIR );
        file_put_contents( WPMU_PLUGIN_DIR . '/boat-fixture.php', "<?php\nadd_filter('boat_backup_mu_fixture', '__return_true');\n" );
        wp_mkdir_p( WP_PLUGIN_DIR . '/boat-fixture' );
        file_put_contents( WP_PLUGIN_DIR . '/boat-fixture/plugin.php', "<?php\n/* Plugin Name: Boat Backup integration fixture */\nadd_filter('boat_backup_fixture_filter', '__return_true');\n" );
        activate_plugin( 'boat-fixture/plugin.php' );
        $menu = wp_create_nav_menu( 'Fixture menu' );
        wp_update_nav_menu_item( $menu, 0, array( 'menu-item-title' => 'Fixture', 'menu-item-url' => home_url( '/menu/' ), 'menu-item-type' => 'custom', 'menu-item-status' => 'publish' ) );
        echo "SEEDED\n";
        break;
    case 'target':
        // Destination-only items must survive the overlay restore.
        file_put_contents( WP_CONTENT_DIR . '/uploads/destination-only.txt', 'kept' );
        wp_mkdir_p( WP_PLUGIN_DIR . '/boat-destination-only' );
        file_put_contents( WP_PLUGIN_DIR . '/boat-destination-only/plugin.php', "<?php\n/* Plugin Name: Boat Backup destination-only fixture */\n" );
        echo "TARGET\n";
        break;
    case 'export':
        // Optional flags, for example: export no_spam_comments no_cache password=secret.
        $options = array();
        foreach ( array_slice( $args, 1 ) as $flag ) {
            list( $key, $value ) = array_pad( explode( '=', $flag, 2 ), 2, true );
            $options[ $key ] = $value;
        }
        $job = $engine->start( 'export', '', 0, $options );
        $phase = '';
        for ( $i = 0; $i < 10000 && 'completed' !== $job['phase']; ++$i ) {
            $job = $engine->step( $job['id'] );
            if ( $job['phase'] !== $phase ) { echo 'PHASE ' . $job['phase'] . "\n"; $phase = $job['phase']; }
        }
        $fail( 'completed' === $job['phase'], 'export finished' );
        echo 'BACKUP=' . $engine->storage->backup_path( $job['backup'] ) . "\n";
        break;
    case 'stage':
        $name = $args[1] ?? '';
        $job = $engine->start( 'restore', $name );
        $before = get_option( 'blogname' );
        $phase = '';
        for ( $i = 0; $i < 10000 && 'ready' !== $job['phase']; ++$i ) {
            if ( 'password' === $job['phase'] ) {
                $fail( isset( $args[2] ), 'protected package needs a password argument' );
                $job = $engine->unlock( $job['id'], $args[2] );
            }
            $job = $engine->step( $job['id'] );
            if ( $job['phase'] !== $phase ) { echo 'PHASE ' . $job['phase'] . "\n"; $phase = $job['phase']; }
        }
        $fail( 'ready' === $job['phase'], 'staging finished' );
        $fail( $before === get_option( 'blogname' ), 'staging preserved destination' );
        echo 'JOB=' . $job['id'] . "\n";
        break;
    case 'commit':
        $job = $engine->current();
        $result = $engine->commit( $job['id'] );
        $fail( 'completed' === $result['phase'], 'commit completed' );
        echo 'COMMITTED=' . $result['id'] . "\n";
        break;
    case 'verify':
        $value = get_option( 'boat_backup_fixture' );
        $fail( home_url( '/ação/' ) === $value['url'], 'serialized Unicode URL' );
        $fail( WP_CONTENT_DIR . '/uploads/fixture.bin' === $value['path'], 'content path replacement' );
        $fail( serialize( array( 'url' => home_url( '/nested/' ) ) ) === $value['nested'], 'nested serialized value' );
        $fail( home_url( '/object/' ) === $value['object']->url, 'object serialization' );
        $fail( home_url( '/json/' ) === json_decode( $value['json'], true )['url'], 'escaped JSON URL' );
        $fail( ! get_user_by( 'login', 'target_admin' ) && get_user_by( 'login', 'source_admin' ), 'source users replaced destination users' );
        $fail( current_user_can( 'manage_options' ) || get_user_by( 'login', 'source_admin' )->has_cap( 'manage_options' ), 'prefix-aware roles and usermeta' );
        $row = $wpdb->get_row( 'SELECT * FROM ' . Database::identifier( $wpdb->prefix . 'fixture' ), ARRAY_A );
        $fail( serialize( array( 'url' => home_url( '/custom/' ) ) ) === $row['body'], 'custom table serialized value' );
        $fail( "\x00\xffhttps://old.test\x00" === $row['binary_value'], 'binary column preserved' );
        $fail( 'two' === $row['choice'] && 'ação' === $row['label'], 'enum and Unicode custom columns' );
        if ( get_option( 'boat_backup_fixture_next_id' ) ) {
            $status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $wpdb->prefix . 'fixture' ), ARRAY_A );
            $fail( '5000' === (string) $status['Auto_increment'], 'auto-increment next value preserved' );
        }
        $fail( 10485760 === filesize( WP_CONTENT_DIR . '/uploads/fixture.bin' ), 'large segmented file' );
        $fail( 2097152 === filesize( WP_CONTENT_DIR . '/uploads/upload-parts.bin' ), 'multipart upload fixture' );
        $fail( 0 === filesize( WP_CONTENT_DIR . '/uploads/empty.txt' ), 'empty file' );
        $fail( '日本語 ação' === file_get_contents( WP_CONTENT_DIR . '/uploads/ação.txt' ), 'Unicode filename and content' );
        $fail( 'kept' === file_get_contents( WP_CONTENT_DIR . '/uploads/destination-only.txt' ), 'destination-only upload kept' );
        $fail( is_file( WP_PLUGIN_DIR . '/boat-destination-only/plugin.php' ), 'destination-only plugin kept on disk' );
        $fail( ! in_array( 'boat-destination-only/plugin.php', get_option( 'active_plugins' ), true ), 'destination-only plugin inactive' );
        $fail( 'extra' === file_get_contents( WP_CONTENT_DIR . '/boat-extra/nested/data.txt' ), 'additional wp-content folder' );
        $fail( is_file( WPMU_PLUGIN_DIR . '/boat-fixture.php' ), 'must-use plugin restored' );
        $fail( in_array( plugin_basename( BOAT_BACKUP_FILE ), get_option( 'active_plugins' ), true ), 'migration plugin remains active' );
        $fail( in_array( 'boat-fixture/plugin.php', get_option( 'active_plugins' ), true ), 'source plugin activation preserved' );
        $fail( (bool) apply_filters( 'boat_backup_fixture_filter', false ), 'restored plugin executes' );
        $fail( count( wp_get_nav_menus() ) > 0, 'navigation menu restored' );
        $fail( ! $engine->current(), 'active operation cleared' );
        echo "PASS: migrated database, files, users, roles, plugins, menus and URLs\n";
        break;
    default:
        throw new RuntimeException( 'Unknown integration action.' );
}
