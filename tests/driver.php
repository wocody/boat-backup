<?php
/** Isolated native-driver doubles, loaded only by dependency-free unit tests. */
namespace Wocody\BoatBackup {
    function mysqli_query($connection, $sql, $mode) { return $connection->query($sql); }
    function mysqli_errno($connection) { return $connection->error; }
    function mysqli_real_escape_string($connection, $bytes) { return addslashes($bytes); }
}
namespace {
    class BoatDriverResult {
        private $row;
        public function __construct($row) { $this->row=$row; }
        public function fetch_assoc() { return $this->row; }
    }
    class BoatDriverRows {
        private $rows;
        public function __construct($rows) { $this->rows=$rows; }
        public function fetch_assoc() { return array_shift($this->rows); }
        public function free() {}
    }
    class BoatDriverDouble {
        public $queries=array();
        public $error=0;
        public $fail=1030;
        public $collations=array();
        public function query($sql) {
            if (0===strpos($sql,'SHOW COLLATION')) return new BoatDriverRows($this->collations);
            $this->queries[]=$sql;
            if (0===strpos($sql,'CREATE TABLE') && $this->fail) { $this->error=$this->fail; $this->fail=0; return false; }
            if (0===strpos($sql,'SHOW TABLE STATUS')) return new BoatDriverResult(array('Engine'=>'InnoDB'));
            $this->error=0;return true;
        }
    }
    class BoatWpdbDouble {
        public $dbh;
        public $prefix='wp_';
        public function __construct() { $this->dbh=new BoatDriverDouble(); }
        public function has_cap($feature) { return false; }
    }
}
