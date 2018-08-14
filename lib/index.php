<?php
require dirname(__FILE__) . '/Clementine.php';
try {
    $Clementine = new Clementine();
    $Clementine->run();
} catch(ClementineDieException $e) {
    // do nothing instead of dieing for testability's sake
}
