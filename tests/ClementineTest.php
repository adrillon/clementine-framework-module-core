<?php
$_SERVER['SERVER_NAME'] = 'pasus';
require_once ('../lib/Clementine.php');
global $Clementine;
$Clementine = new Clementine();
$Clementine->apply_config();

class ClementineTest extends PHPUnit_Framework_TestCase
{

    public function testgetModuleInfos()
    {
        global $Clementine;
        /*
        $expected = array (
            'version' => 1.6,
            'weight' => 0.1
        );
         */
        $result = $Clementine->getModuleInfos('core');
        $this->assertTrue(is_array($result));
        $this->assertTrue(isset($result['version']));
        $this->assertTrue(isset($result['weight']));
        $this->assertTrue((float) $result['weight'] == $result['weight']);
        $this->assertTrue((float) $result['version'] == $result['version']);
    }

    public function testgetHelper()
    {
        global $Clementine;
        $helper = $Clementine->getHelper('debug');
        $this->assertTrue('DebugHelper' == get_class($helper));
    }
}
?>
