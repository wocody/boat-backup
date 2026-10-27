<?php
/** Native dump fixtures and process-interruption scenarios for disposable sites only. */

use Wocody\BoatBackup\Database;
use Wocody\BoatBackup\Plugin;
use Wocody\BoatBackup\Sql;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'BOAT_BACKUP_TEST_SITE' ) || ! BOAT_BACKUP_TEST_SITE ) {
    throw new RuntimeException( 'Requires a disposable BOAT_BACKUP_TEST_SITE through WP-CLI.' );
}
global $wpdb;
$engine = Plugin::engine();
$db = new Database( $wpdb );
$assert = function ( $condition, $label ) { if ( ! $condition ) throw new RuntimeException( 'FAIL: ' . $label ); };
$action = $args[0] ?? '';
$name = $args[1] ?? '';
$quoted = function ( $suffix ) use ( $wpdb ) { return Database::identifier( $wpdb->prefix . $suffix ); };
switch ( $action ) {
    case 'seed':
        $mode = $db->begin_session();
        try {
            $db->query( 'CREATE TABLE ' . $quoted('fixture_spatial') . ' (id int NOT NULL PRIMARY KEY, shape point NOT NULL, line_value linestring, polygon_value polygon, collection_value geometrycollection, empty_value geometry NULL, body longtext, raw_value longblob, amount decimal(10,2) unsigned DEFAULT 0, bits bit(8), n int, calculated int GENERATED ALWAYS AS (n + 1) STORED, SPATIAL KEY shape_index (shape)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' );
            $geometry = array('POINT(-38.5 -3.7)', 'LINESTRING(1 2,3 4)', 'POLYGON((0 0,0 1,1 1,0 0))', 'GEOMETRYCOLLECTION(POINT(1 2))');
            $values = array_map( function($wkt) use ($db) { return 'ST_GeomFromText(' . $db->literal($wkt) . ',4326)'; }, $geometry );
            $raw = "\0\xff\n'\\" . home_url('/opaque/');
            update_option('boat_backup_native_raw', base64_encode($raw));
            $db->query( 'INSERT INTO ' . $quoted('fixture_spatial') . ' (id,shape,line_value,polygon_value,collection_value,empty_value,body,raw_value,amount,bits,n) VALUES (1,' . implode(',', $values) . ',NULL,' . $db->literal(serialize(array('url'=>home_url('/spatial/'),'quoted'=>"a;\nb'c"))) . ',' . $db->literal($raw) . ",12.34,b'10000001',2)" );
            update_option('boat_backup_native_geometry', $db->query('SELECT HEX(shape) AS shape, HEX(line_value) AS line_value, HEX(polygon_value) AS polygon_value, HEX(collection_value) AS collection_value FROM '.$quoted('fixture_spatial'))->fetch_assoc());
            $db->query( 'CREATE TABLE ' . $quoted('fixture_myisam') . ' (body longtext NOT NULL, raw_value longblob NULL, label varchar(32) DEFAULT NULL, FULLTEXT KEY body_search (body)) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' );
            for($i=0;$i<435;++$i) $db->query('INSERT INTO '.$quoted('fixture_myisam').' VALUES ('.$db->literal(serialize(array('url'=>home_url('/mixed/'),'duplicate'=>$i%3))).','.$db->literal("\0\xff").',NULL)');
            $db->query( 'CREATE TABLE ' . $quoted('fixture_memory') . ' (label varchar(32)) ENGINE=MEMORY DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' );
            for($i=0;$i<17;++$i) $db->query('INSERT INTO '.$quoted('fixture_memory')." VALUES ('duplicate')");
            $db->query('CREATE TABLE '.$quoted('fixture_parent').' (id int NOT NULL PRIMARY KEY) ENGINE=InnoDB');
            $db->query('CREATE TABLE '.$quoted('fixture_child').' (id int NOT NULL PRIMARY KEY, parent_id int, CONSTRAINT native_test_fk FOREIGN KEY (parent_id) REFERENCES '.$quoted('fixture_parent').'(id)) ENGINE=InnoDB');
            $db->query('INSERT INTO '.$quoted('fixture_parent').' VALUES (1)');
            $db->query('INSERT INTO '.$quoted('fixture_child').' VALUES (1,1)');
            foreach(array('options','posts','usermeta') as $suffix) $db->query('ALTER TABLE '.$quoted($suffix).' ENGINE=MyISAM');
        } finally { $db->end_session($mode); }
        echo "PASS native spatial, generated, binary, FK, MEMORY and MyISAM fixtures seeded\n";
        break;
    case 'replay':
        $job = $engine->start('restore',$name);
        while('load'!==$job['phase']) $job=$engine->step($job['id']);
        // Advance through bounded batches until part of the keyless table is loaded.
        $i=array_search('fixture_myisam',array_column($engine->storage->load($job['id'])['database'],'table'),true);
        $assert(false!==$i,'keyless fixture declared');
        $stage=Database::identifier($db->stage_name($job['id'],$i));
        for($step=0;$step<1000;++$step) {
            $state=$engine->storage->load($job['id']);
            if($db->exists($db->stage_name($job['id'],$i))) {
                $count=(int)$db->query('SELECT COUNT(*) AS n FROM '.$stage)->fetch_assoc()['n'];
                if($count>0 && $count<435) break;
            }
            $job=$engine->step($job['id']);
        }
        $assert(isset($count)&&$count>0&&$count<435,'real partial table batch loaded');
        $stream=fopen($engine->storage->job_path($job['id']).'/database.sql','rb');
        fseek($stream,$state['sql_offset']);
        $plan=Database::plan(Sql::read($stream,$db->statement_limit()),$state['database']);fclose($stream);
        $assert('insert'===$plan['kind']&&$i===$plan['table'],'next query inserts into the partially loaded table');
        $state['pending_table']=$i;$engine->storage->save($state);
        // Simulate a lost process after INSERT, before publishing its byte cursor.
        $db->apply($plan,$state,new \Wocody\BoatBackup\Rewriter(array()));
        $assert($count+1===(int)$db->query('SELECT COUNT(*) AS n FROM '.$stage)->fetch_assoc()['n'],'uncheckpointed row really persisted');
        $engine=Plugin::engine();
        do{$job=$engine->step($job['id']);}while('ready'!==$job['phase']);
        $assert(435===(int)$db->query('SELECT COUNT(*) AS n FROM '.$stage)->fetch_assoc()['n'],'table replay preserves duplicate rows exactly once');
        echo 'PASS native SQL interrupted table replay; READY='.$job['id']."\n";
        break;
    case 'verify':
        $row=$db->query('SELECT *,HEX(bits) AS bit_hex,ST_SRID(shape) AS srid,ST_AsText(shape) AS point_text,ST_AsText(line_value) AS line_text,ST_AsText(polygon_value) AS polygon_text,ST_AsText(collection_value) AS collection_text FROM '.$quoted('fixture_spatial'))->fetch_assoc();
        $assert('4326'===(string)$row['srid'],'geometry SRID preserved');
        $expected_geometry=get_option('boat_backup_native_geometry');$assert(is_array($expected_geometry)&&4===count($expected_geometry),'source geometry byte fixture present');
        foreach($expected_geometry as $field=>$hex) $assert(strtoupper(bin2hex($row[$field]))===$hex,$field.' native geometry bytes preserved across server representations');
        $assert(null===$row['empty_value'],'NULL geometry');
        $assert(base64_decode(get_option('boat_backup_native_raw'),true)===$row['raw_value'],'opaque bytes containing a source URL preserved');
        $assert(home_url('/spatial/')===unserialize($row['body'])['url'],'serialized spatial-table text adapted');
        $assert('3'===(string)$row['calculated']&&'12.34'===$row['amount']&&'81'===$row['bit_hex'],'generated, decimal and bit values preserved');
        $assert(435===(int)$db->query('SELECT COUNT(*) AS n FROM '.$quoted('fixture_myisam'))->fetch_assoc()['n'],'keyless duplicate rows preserved');
        $assert(17===(int)$db->query('SELECT COUNT(*) AS n FROM '.$quoted('fixture_memory'))->fetch_assoc()['n'],'MEMORY rows preserved without an engine allowlist');
        foreach(array('fixture_myisam'=>'MyISAM','fixture_memory'=>'MEMORY','fixture_spatial'=>'InnoDB','options'=>'MyISAM') as $suffix=>$expected) $assert($expected===$db->describe($suffix,0)['engine'],$suffix.' engine matches native definition');
        $create=$db->query('SHOW CREATE TABLE '.$quoted('fixture_child'))->fetch_assoc()['Create Table'];
        $assert(false===strpos($create,'FOREIGN KEY'),'foreign references omitted by compatibility normalization');
        $assert(1===(int)$db->query('SELECT COUNT(*) AS n FROM '.$quoted('fixture_child'))->fetch_assoc()['n'],'FK table data preserved');
        $index=$db->query('SHOW INDEX FROM '.$quoted('fixture_spatial')." WHERE Key_name='shape_index'")->fetch_assoc();
        $assert('SPATIAL'===$index['Index_type'],'spatial index preserved');
        echo "PASS native dump engines, geometry/SRID, spatial index, generated columns, opaque bytes, duplicates and FK adaptation\n";
        break;
    case 'partial-switch':
        $summary=$engine->current();$job=$engine->storage->load($summary['id']);$assert('ready'===$job['phase'],'ready before partial switch');
        $job['old_tables']=$db->tables();$job['old_plugins']=$db->active_plugins($wpdb->options);$job['phase']='applying';$engine->storage->save($job);
        $marker=$engine->storage->root().'/maintenance.json';$engine->storage->write($marker,array('id'=>$job['id']));
        $live=$quoted($job['old_tables'][0]);$old=Database::identifier($db->stage_name($job['id'],0,true));$db->query('RENAME TABLE '.$live.' TO '.$old);
        try{try{$engine->recover_interrupted();throw new LogicException('Partial switch accepted');}catch(RuntimeException $error){$assert(false!==strpos($error->getMessage(),'incomplete'),'partial switch identified');$assert(is_file($marker),'maintenance preserved');}}
        finally{$db->query('RENAME TABLE '.$old.' TO '.$live);$engine->recover_interrupted();}
        echo "PASS incomplete table switch fails closed\n";
        break;
    default:throw new RuntimeException('Unknown native SQL scenario.');
}
