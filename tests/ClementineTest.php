<?php
$_SERVER['HTTP_HOST'] = 'phpunit_fake_http_host';
require_once (__DIR__ . '/../../core/lib/Clementine.php');
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
        $this->assertTrue($result['weight'] == 0.1);
    }

    public function testgetModel()
    {
        global $Clementine;
        $model = $Clementine->getModel('traduction');
        $this->assertTrue('TraductionModel' == get_class($model));
    }


    public function testgetHelper()
    {
        global $Clementine;
        $debughelper = $Clementine->getHelper('debug');
        $this->assertTrue('DebugHelper' == get_class($debughelper));
        $hookhelper = $Clementine->getHelper('hook');
        $this->assertTrue('HookHelper' == get_class($hookhelper));
    }

}
