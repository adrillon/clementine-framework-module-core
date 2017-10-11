<?php
$_SERVER['HTTP_HOST'] = 'phpunit_fake_http_host';
require_once (dirname(__FILE__) . '/../../core/lib/Clementine.php');
// fix for code coverage: http://www.voidcn.com/blog/Tom_Green/article/p-6004162.html
$php_token_autoload_file = '/usr/share/php/PHP/Token/Stream/Autoload.php';
if (file_exists($php_token_autoload_file)) {
    require_once($php_token_autoload_file);
}
global $Clementine;
$Clementine = new Clementine();
$Clementine->run(true);

ini_set('log_errors', 'off');
ini_set('display_errors', 'on');

class ClementineTestCase extends PHPUnit_Framework_TestCase
{
    // créer une BD de test par duplication de la BD de prod
    public static function setUpTestDB()
    {
        if (static::wouldOverwrite()) {
            return false;
        }
        $clemopts = Clementine::$register['clementine_cli_opts'];
        if (isset($clemopts['--init-testdata'])) {
            static::dropTestDB();
            static::createTestDB();
            static::populateTestDB();
        }
        static::shuntDB();
    }

    // court-circuite la config BD pour forcer l'utilisation de la BD de tests
    public static function shuntDB()
    {
        global $Clementine;
        $db = $Clementine->getModel('db');
        if (!empty(Clementine::$register[$db->clementine_db_config]['connection']) && Clementine::$register[$db->clementine_db_config]['connection']) {
            $db->close();
            $Clementine::$config['clementine_db'] = $Clementine::$config['clementine_dbtests'];
            $db->connect();
        } else {
            $Clementine::$config['clementine_db'] = $Clementine::$config['clementine_dbtests'];
        }
    }

    // (re)créer la BD de test
    public static function createTestDB()
    {
        global $Clementine;
        $db = $Clementine->getModel('db');
        $cmd_mysql = static::getMysqlCmd();
        $cmd_create = 'echo "CREATE DATABASE ' . $db->escape_string($Clementine::$config['clementine_dbtests']['name']) . ';" | ' . $cmd_mysql;
        $cmd_create_retval = 0;
        system($cmd_create, $cmd_create_retval);
        if ($cmd_create_retval) {
            die('Failed to recreate test database');
        }
    }

    public static function dropTestDB()
    {
        if (static::wouldOverwrite()) {
            return false;
        }
        $clemopts = Clementine::$register['clementine_cli_opts'];
        global $Clementine;
        $db = $Clementine->getModel('db');
        $cmd_mysql = static::getMysqlCmd();
        $cmd_drop = 'echo "DROP DATABASE IF EXISTS ' . $db->escape_string($Clementine::$config['clementine_dbtests']['name']) . ';" | ' . $cmd_mysql;
        $cmd_drop_retval = 0;
        system($cmd_drop, $cmd_drop_retval);
        if ($cmd_drop_retval) {
            die('Failed to drop test database');
        }
    }

    public static function populateTestDB()
    {
        global $Clementine;
        $db = $Clementine->getModel('db');
        $cmd_mysqldump = static::getMysqldumpCmd();
        $cmd_mysql = static::getMysqlCmd();
        $cmd_duplicate = $cmd_mysqldump . ' ' . escapeshellarg($Clementine::$config['clementine_db']['name']) . ' | ' . $cmd_mysql . ' ' . escapeshellarg($Clementine::$config['clementine_dbtests']['name']);
        $cmd_duplicate_retval = 0;
        system($cmd_duplicate, $cmd_duplicate_retval);
        if ($cmd_duplicate_retval) {
            static::tearDownAfterClass();
            die('Failed to populate test database');
        }
    }

    // get mysql command to connect to test database
    public static function getMysqlCmd()
    {
        global $Clementine;
        $cmd_mysql = "mysql";
        if (!empty($Clementine::$config['clementine_dbtests']['host'])) {
            $cmd_mysql .= " -h " . escapeshellarg($Clementine::$config['clementine_dbtests']['host']);
        }
        if (!empty($Clementine::$config['clementine_dbtests']['user'])) {
            $cmd_mysql .= " -u " . escapeshellarg($Clementine::$config['clementine_dbtests']['user']);
        }
        if (!empty($Clementine::$config['clementine_dbtests']['pass'])) {
            $cmd_mysql .= " -p" . escapeshellarg($Clementine::$config['clementine_dbtests']['pass']);
        }
        return $cmd_mysql;
    }

    // get mysqldump command to dump site database
    public static function getMysqldumpCmd()
    {
        global $Clementine;
        $cmd_mysqldump = "mysqldump";
        if (!empty($Clementine::$config['clementine_db']['host'])) {
            $cmd_mysqldump .= " -h " . escapeshellarg($Clementine::$config['clementine_db']['host']);
        }
        if (!empty($Clementine::$config['clementine_db']['user'])) {
            $cmd_mysqldump .= " -u " . escapeshellarg($Clementine::$config['clementine_db']['user']);
        }
        if (!empty($Clementine::$config['clementine_db']['pass'])) {
            $cmd_mysqldump .= " -p" . escapeshellarg($Clementine::$config['clementine_db']['pass']);
        }
        if (!empty($Clementine::$config['clementine_dbtests']['ignore'])) {
            $ignore_tables = explode(',', $Clementine::$config['clementine_dbtests']['ignore']);
            foreach ($ignore_tables as $ignore_table) {
                $cmd_mysqldump .= ' --ignore-table=' . escapeshellarg($Clementine::$config['clementine_dbtests']['name'] . '.' . $ignore_table);
            }
        }
        return $cmd_mysqldump;
    }

    // test if testdb would overwrite site db because of a bad config
    public static function wouldOverwrite()
    {
        $prod_host = strtolower(trim(Clementine::$config['clementine_db']['host']));
        if (false !== strpos($prod_host, '127.0.0')) {
            $prod_host = 'localhost';
        }
        $prod_port = '';
        if (!empty(Clementine::$config['clementine_db']['port'])) {
            $prod_port = strtolower(trim(Clementine::$config['clementine_db']['port']));
        }
        $prod_name = strtolower(trim(Clementine::$config['clementine_db']['name']));
        $test_host = strtolower(trim(Clementine::$config['clementine_dbtests']['host']));
        if (false !== strpos($test_host, '127.0.0')) {
            $test_host = 'localhost';
        }
        $test_port = '';
        if (!empty(Clementine::$config['clementine_dbtests']['port'])) {
            $test_port = strtolower(trim(Clementine::$config['clementine_dbtests']['port']));
        }
        $test_name = strtolower(trim(Clementine::$config['clementine_dbtests']['name']));
        $is_same_db = ($prod_host == $test_host && $prod_port == $test_port && $prod_name == $test_name);
        if ($is_same_db) {
            Clementine::$register['clementine_debug_helper']->trigger_error("Are you trying to destroy production database?", E_USER_WARNING, 2);
            return true;
        }
        return false;
    }

}
