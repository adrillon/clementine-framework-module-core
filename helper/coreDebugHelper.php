<?php
/**
 * coreDebugHelper 
 * 
 * @uses coreDebugHelper_Parent
 * @package core
 * @version $id$
 * @copyright 
 * @author Pierre-Alexis <pa@quai13.com> 
 * @license 
 */
class coreDebugHelper extends coreDebugHelper_Parent
{

    public function trigger_error($error_msg, $error_type = E_USER_NOTICE, $backtrace_depth = 3)
    {
        // older PHP compatibility
        if (!defined('E_USER_DEPRECATED')) {
            define('E_USER_DEPRECATED', 16384);
        }
        $errtypes = array(E_USER_DEPRECATED => 'deprecated',
                          E_USER_NOTICE     => 'notice',
                          E_USER_WARNING    => 'warning',
                          E_USER_ERROR      => 'fatal error');
        $errfileline = '';
        $errfile = '';
        $errline = '';
        if ($backtrace_depth >= 0) {
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            $errfile = $backtrace[$backtrace_depth]['file'];
            $errline = $backtrace[$backtrace_depth]['line'];
            $errfileline = ' in <strong>' . $errfile . '</strong> on line <strong>' . $errline . '</strong>';
        }
        $errtype = 'unknown error';
        if (isset($errtypes[$error_type])) {
            $errtype = $errtypes[$error_type];
        }
        if (__DEBUGABLE__ || (
                Clementine::$config['clementine_debug']['send_errors_by_email'] &&
                Clementine::$config['clementine_debug']['send_errors_by_email_max'] &&
                Clementine::$_register['_handled_errors'] <= Clementine::$config['clementine_debug']['send_errors_by_email_max']
            )
        ) {
            Clementine::clementine_error_handler($error_type, $error_msg, $errfile, $errline);
        }
        if ($error_type == E_USER_ERROR) {
            die();
        }
        if (!isset($errtypes[$error_type])) {
            return false;
        }
        return true;
    }

    public function err404_noSuchMethod($nomail = 0)
    {
        if (__DEBUGABLE__ && Clementine::$config['clementine_debug']['display_errors']) {
            $request = $this->getRequest();
            $action = $request->ACT . 'Action';
            $msg = "Erreur 404 : pas de methode " . $request->CTRL . "->" . "$action()";
            if ($nomail) {
                $this->trigger_error($msg, 'E_USER_WARNING_NOMAIL');
            } else {
                $this->trigger_error($msg, E_USER_WARNING);
            }
        }
    }

    public function err404_noSuchController($ctrl)
    {
        if (__DEBUGABLE__ && Clementine::$config['clementine_debug']['display_errors']) {
            $msg = "Erreur 404 : pas de controleur " . $ctrl;
            $this->trigger_error($msg, E_USER_WARNING);
        }
    }

    public function err404_cannotLoadCtrl($nomail = 0)
    {
        if (__DEBUGABLE__ && Clementine::$config['clementine_debug']['display_errors']) {
            $request = $this->getRequest();
            $msg = "Erreur 404 : impossible de charger le controleur " . $request->CTRL;
            if ($nomail) {
                $this->trigger_error($msg, "E_USER_WARNING_NOMAIL", -1);
            } else {
                $this->trigger_error($msg, E_USER_WARNING, -1);
            }
        }
    }

    public function err404_noSuchBlock($path = null, $nomail = 0)
    {
        if (__DEBUGABLE__ && Clementine::$config['clementine_debug']['display_errors']) {
            if ($path) {
                $this->trigger_error("Erreur 404 : le bloc " . $path . " est introuvable ou a renvoye une erreur", E_USER_WARNING, 2);
            } else {
                $request = $this->getRequest();
                if (!$path) {
                    $path = $request->CTRL . '/' . $request->ACT;
                }
                if ($nomail) {
                    $this->trigger_error("Erreur 404 : la page " . $path . " est introuvable ou a renvoye une erreur", 'E_USER_WARNING_NOMAIL', -1);
                } else {
                    $this->trigger_error("Erreur 404 : la page " . $path . " est introuvable ou a renvoye une erreur", E_USER_WARNING, -1);
                }
            }
        }
    }

    public function err404_no404Block()
    {
        if (__DEBUGABLE__ && Clementine::$config['clementine_debug']['display_errors']) {
            $msg = "Erreur 404 : le block d'erreurs 404 est lui-même introuvable";
            $this->trigger_error($msg, E_USER_WARNING, -1);
        }
    }

    public function err404_noLanguageCode()
    {
        if (__DEBUGABLE__ && Clementine::$config['clementine_debug']['display_errors']) {
            $msg = "Erreur 404 : l'URL ne spécifie pas la langue demandée";
            $this->trigger_error($msg, E_USER_WARNING, -1);
        }
    }

    public function errFatale_noSuchModel($type, $element)
    {
        if (__DEBUGABLE__ && Clementine::$config['clementine_debug']['display_errors']) {
            $msg = $type . ' \'' . $element . '\' not found';
            $this->trigger_error($msg, E_USER_ERROR);
        }
    }

    public function memoryUsage()
    {
        if (Clementine::$config['clementine_debug']['memory_usage']) {
            if (version_compare(PHP_VERSION, '5.2.0') >= 0) {
                $size = memory_get_peak_usage();
            } else {
                $size = memory_get_usage();
            }
            $unites = array('b','k','m','g','t','p','e');
            $unite = strtolower(substr(preg_replace('/[0-9]*/', '', $size), 0, 1));
            if (!$unite) {
                $unite = 'b';
            }
            $i = floor(log($size, 1000));
            if (isset($unites[$i])) {
                $unite = $unites[$i];
                $memory_usage = $size / pow(1000, ($i));
            } else {
                $memory_usage = $size;
            }
            $memory_usage = number_format($memory_usage, 3, ',', ' ');
            if (version_compare(PHP_VERSION, '5.2.0') >= 0) {
                Clementine::$clementine_debug[] = 'Memory peak usage : ' . $memory_usage . ' ' . $unite;
            } else {
                Clementine::$clementine_debug[] = 'Memory usage : ' . $memory_usage . ' ' . $unite;
            }
        }
    }

    public function generationTime($mvc_generation_begin, $mvc_generation_end)
    {
        if (Clementine::$config['clementine_debug']['generation_time']) {
            $mvc_generation_time = number_format(1000 * ($mvc_generation_end - $mvc_generation_begin), 0, ',', ' ');
            Clementine::$clementine_debug[] = 'Generation time : ' . $mvc_generation_time . ' ms';
        }
    }

    public function debugHook($hookname, $was_called)
    {
        if (__DEBUGABLE__ && Clementine::$config['clementine_debug']['hook']) {
            $i = 50;
            $tmp = 'HookHelper';
            $hooks_stack = array($tmp);
            $hooks_files_stack = array();
            for (; $parent = get_parent_class($tmp); $tmp = $parent) {
                if (substr($parent, - (strlen('Controller_Parent'))) == 'Controller_Parent') {
                    continue;
                }
                $hooks_stack[] = $parent;
            }
            $hooks_stack = array_reverse($hooks_stack);
            array_shift($hooks_stack);
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            if (!isset(Clementine::$clementine_debug['hook'][$hookname])) {
                Clementine::$clementine_debug['hook'][$hookname][] = '<strong>$this->hook(\'' . $hookname . '\')' . '</strong> ' . ($was_called ? '(est actif)' : '') . ' <br />' . implode(' &gt; ', $hooks_stack) . '->' . $hookname . '() ';
            }
            Clementine::$clementine_debug['hook'][$hookname][] = implode('<br />', array('file'  => '<em>' . $backtrace[1]['file'] . ':' . $backtrace[1]['line'] . '</em>'));
        }
    }

    public function debugBlock_init()
    {
        if (__DEBUGABLE__ && (Clementine::$config['clementine_debug']['block'] || Clementine::$config['clementine_debug']['block_label'])) {
            if (!isset(Clementine::$register['clementine_debug'])) {
                Clementine::$register['clementine_debug'] = array();
            }
            // on remet a 0 la pile
            Clementine::$register['clementine_debug']['block_files_stack'] = array();
        }
    }

    public function debugBlock_register_stack($scope, $module, $path, $file, $ignores, $load_parent)
    {
        if (__DEBUGABLE__ && (Clementine::$config['clementine_debug']['block'] || Clementine::$config['clementine_debug']['block_label'])) {
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            if (__DEBUGABLE__ && Clementine::$config['clementine_debug']['block_label']) {
                echo '<span class="Clementine_debug_block" style="margin: -5px; border: solid #FF6600 5px; padding: 5px; -moz-border-radius: 5px; background-color: #F80; color: #FFF; opacity: 0.2; position: absolute; font-size: 12px; line-height: 1.4em; z-index: 9998; " onmouseover="this.style.opacity=1; this.style.zIndex=9999; " onmouseout="this.style.opacity=0.2; this.style.zIndex=9998; ">';
                echo '<strong>' . $scope . '/' . $module . ' &gt; ' . $path . '</strong><br />';
            }
            array_pop($backtrace); // pas besoin de préciser /index.php
            // TODO: rajouter un array_pop car on est dans le controller Debug et non plus directement dans Clementine ?
            array_pop($backtrace); // pas besoin de préciser pour le fichier index.php de Clementine
            array_pop($backtrace); // vire le dernier getBlock, celui qui est appele automatiquement dans ce fichier
            $basetxt = $file;
            foreach ($backtrace as $trace) {
                if ((!$load_parent && $trace['function'] == 'getBlock') || ($load_parent && $trace['function'] == 'getParentBlock')) {
                    $basetxt .= '<br />qui est chargé par <em>' . $trace['file'] . '</em>:' . $trace['line'];
                }
            }
            if (strlen($basetxt)) {
                $txt = $basetxt;
                if ($ignores['is_ignored']) {
                    $txt = ' <div style="color: #F00; ">' . $module . ' ignoré <br />' . $basetxt . '</div>';
                }
                Clementine::$register['clementine_debug']['block_files_stack'][] = $txt;
            }
            if (__DEBUGABLE__ && Clementine::$config['clementine_debug']['block_label']) {
                echo '</span>';
            }
        }
    }

    public function debugBlock_warningRecursiveCall($path)
    {
        if (__DEBUGABLE__ && Clementine::$config['clementine_debug']['display_errors']) {
            $msg = 'Recursive block call for <strong>' . $path . '</strong>';
            $this->trigger_error($msg, E_USER_WARNING, 1);
        }
    }

    public function debugBlock_dumpStack($scope, $module, $path_array)
    {
        if (__DEBUGABLE__ && (Clementine::$config['clementine_debug']['block'])) {
            // affiche dans le tableau $this->debug l'ordre de surcharge pour ce block
            Clementine::$clementine_debug['block'][] = '<strong>' . $scope . '/' . $module . ' &gt; ' . implode('/', $path_array) . '</strong><br />' . implode("<br />", Clementine::$register['clementine_debug']['block_files_stack']);
        }
    }

    public function getControllerFromBlock()
    {
        $msg = 'les appels à getController depuis un bloc sont interdits';
        $this->trigger_error($msg, E_USER_ERROR, 2);
    }

    public function getControllerFromModel()
    {
        $msg = 'les appels à getController depuis un modèle sont interdits';
        $this->trigger_error($msg, E_USER_ERROR, 2);
    }


}
?>
