<?php
class coreClementineTest extends coreClementineTest_Parent
{
    public function testTestsCanRun()
    {
        $this->assertTrue(true);
    }

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
