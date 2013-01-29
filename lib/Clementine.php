<?php
/**
 * Clementine : classe de base du MVC
 * 
 * @package 
 * @version $id$
 * @copyright 
 * @author Pierre-Alexis de Solminihac <pa@quai13.com> 
 * @license 
 */
class Clementine
{
    // c'est dans cette variable que sont stockees les donnees transmises aux blocks
    public $data;
    static public $clementine_debug = array();
    static public $config = array();

    // variables utilisees pour que les modules puissent enregistrer dans un endroit centralisé des données
    static public $register = array();
    static private $_register = array('_parent_loaded_blocks'       => array(),
                                      '_parent_loaded_blocks_files' => array(),
                                      '_forbid_getcontroller' => 0);

    /**
     * __call : selon le modele de surcharge choisi dans ce framework, l'appel de parent::method() ne doit pas planter si la fonction n'existe pas
     * 
     * @param mixed $name 
     * @param mixed $args 
     * @access public
     * @return void
     */
    public function __call($name, $args)
    {
        $call_parent = 0;
        $trace = debug_backtrace();
        // verifie si la fonction est appelee au moyen de parent::
        if (isset($trace[1]) && isset($trace[2]) && isset($trace[1]['class']) && isset($trace[1]['function']) && isset($trace[2]['class']) && isset($trace[2]['function'])) {
            if ((strtolower($trace[1]['function']) == strtolower($trace[2]['function'])) && (strtolower(get_parent_class($trace[2]['class'])) == strtolower($trace[1]['class']))) {
                // l'appel de parent::method() ne doit pas planter, car sinon il n'y a plus d'independance des modules
                $call_parent = 1;
            }
        }
        if (!$call_parent) {
            if (!defined('__DEBUGABLE__') || __DEBUGABLE__) {
                echo "<br />\n" . '<strong>Clementine fatal error</strong>: Call to undefined method ' . $trace[1]['class'] . '::' . $name . ' in ' . $trace[1]['file'] . ' on line ' . $trace[1]['line'];
            }
            die();
        }
    }

    /**
     * run : lance l'application construite sur l'architecture MVC
     * 
     * @access public
     * @return void
     */
    public function run()
    {
        $mvc_generation_begin = microtime(true);
        $erreur_404 = 0;
        // si appel en CLI, on reconstruit _GET a partir de argv[3]
        if (!isset($_SERVER['SERVER_NAME'])) {
            global $argv;
            if (isset($argv[3])) {
                $tmp_GET_pairs = explode('&', $argv[3]);
                foreach ($tmp_GET_pairs as $str_pair) {
                    $pair = explode('=', $str_pair, 2);
                    if (isset($pair[1])) {
                        $_GET[$pair[0]] = $pair[1];
                    } else {
                        $_GET[$pair[0]] = '';
                    }
                }
            }
        }
        // (nécessaire pour map_url() qu'on appelle depuis le hook before_first_getRequest) : initialise Clementine::$register['request']
        // avant même le premier getRequest(), et supprime les slashes rajoutes par magic_quotes_gpc
        if (get_magic_quotes_gpc()) {
            Clementine::$register['request'] = array(
                'GET'     => $this->stripslashesRecursive($_GET),
                'POST'    => $this->stripslashesRecursive($_POST),
                'COOKIE'  => $this->stripslashesRecursive($_COOKIE),
                'REQUEST' => $this->stripslashesRecursive($_REQUEST));
        } else {
            Clementine::$register['request'] = array(
                'GET'     => $_GET,
                'POST'    => $_POST,
                'COOKIE'  => $_COOKIE,
                'REQUEST' => $_REQUEST);
        }
        $this->apply_config();
        if (__DEBUGABLE__) {
            $debug = $this->getHelper('debug');
        }
        $this->_getRequestURI();
        $this->hook('before_first_getRequest');
        $request = $this->getRequest();
        $this->hook('before_first_getController');
        $controller = $this->getController($request['CTRL']);
        $noblock = false;
        if (!$controller) {
            if ($request['METHOD'] == 'CLI') {
                header('CLI' . ' 404 Not Found', true);
            } else {
                header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found', true);
            }
            $erreur_404 = 1;
        } else {
            $this->hook('before_controller_action');
            // charge le controleur demande dans la requete
            if (count((array) $controller)) {
                $action = $request['ACT'] . 'Action';
                // appelle la fonction demandee dans la requete
                if (method_exists($controller, $action)) {
                    $result = $controller->$action($request);
                    if (isset($result['dont_getblock']) && $result['dont_getblock']) {
                        $noblock = true;
                    }
                } else {
                    header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found', true);
                    $erreur_404 = 1;
                    if (__DEBUGABLE__) {
                        $debug->err404_noSuchMethod();
                    }
                }
            } else {
                header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found', true);
                $erreur_404 = 1;
                if (__DEBUGABLE__) {
                    $debug->err404_cannotLoadCtrl();
                }
            }
        }
        $this->hook('before_block_rendering');
        // charge le bloc demande dans la requete
        if (!$erreur_404 && !$noblock) {
            $path = $request['CTRL'] . '/' . $request['ACT'];
            // charge la surcharge si possible, meme dans le cas de l'adoption
            $gotblock = $controller->getBlock($path, $controller->data, $request);
            if (!$gotblock) {
                header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found', true);
                $erreur_404 = 1;
            }
        }
        // si erreur 404, on charge un autre controleur
        if ($erreur_404) {
            if (__DEBUGABLE__) {
                $debug->err404_noSuchBlock();
            }
            $this->trigger404();
        }
        if (__DEBUGABLE__) {
            $debug->memoryUsage();
            $debug->generationTime($mvc_generation_begin, microtime(true));
            $debug->debug();
        }
    }

    /**
     * trigger404 : charge le controleur d'erreur 404 et le block associé
     * 
     * @param mixed $path 
     * @access public
     * @return void
     */
    public function trigger404()
    {
        header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found', true);
        $controller = $this->getController('errors');
        $action = 'err404Action';
        $request = $this->getRequest();
        $controller->$action($request); // $request sans faire appel à getRequest()
        if (!$controller->getBlock('errors/err404', $controller->data, $request)) {
            if (__DEBUGABLE__) {
                $this->getHelper('debug')->err404_no404Block();
            }
            echo '404 Not Found';
        }
        return $this->dontGetBlock();
    }

    /**
     * hook : execute le hook demande, tout en permettant de loguer les hooks utilisables !
     * 
     * @access public
     * @return void
     */
    public function hook($hookname, $args = null)
    {
        // TODO: dans le DEBUG verifications, verifier qu'on n'appelle pas les hooks sans passer par cette fonction
        $helper = $this->getHelper('hook');
        $was_called = false;
        if (method_exists($helper, $hookname)) {
            $was_called = true;
            $this->getHelper('hook')->$hookname($args);
        }
        if (__DEBUGABLE__) {
            $this->getHelper('debug')->debugHook($hookname, $was_called);
        }
    }

    /**
     * getOverrides : returns the overrides array
     * 
     * @access public
     * @return void
     */
    public function getOverrides()
    {
        if (!(isset(Clementine::$_register['overrides']) && Clementine::$_register['overrides'])) {
            $overrides = $this->getOverridesByWeights(false);
            Clementine::$_register['overrides'] = $overrides;
        }
        return Clementine::$_register['overrides'];
    }

    /**
     * getOverridesByWeights : returns the modules list, sorted by weight
     * 
     * @param mixed $only_weights 
     * @access public
     * @return void
     */
    public function getOverridesByWeights($only_weights = false)
    {
        // liste les dossiers contenus dans ../app/share
        $modules_weights = array();
        $modules_types = array();
        $scopes = array('share', 'local');
        foreach ($scopes as $scope) {
            $path = dirname(__FILE__) . '/../../../' . $scope . '/';
            if (!$dh = @opendir($path)) {
                return false;
            }
            while (false !== ($obj = readdir($dh))) {
                if ($obj == '.' || $obj == '..' || (isset($obj[0]) && $obj[0] == '.')) {
                    continue;
                }
                if (is_dir($path . '/' . $obj)) {
                    if (isset($modules_weights[$obj])) {
                        if (__DEBUGABLE__) {
                            $this->debug_overrides_module_twin($obj);
                        }
                        die();
                    }
                    $infos = $this->getModuleInfos($obj);
                    $modules_weights[$obj] = $infos['weight'];
                    $modules_types[$obj] = $scope;
                }
            }
            closedir($dh);
        }
        array_multisort(array_values($modules_weights), array_keys($modules_weights), $modules_weights);
        if ($only_weights) {
            $overrides = $modules_weights;
        } else {
            $overrides = array();
            foreach ($modules_weights as $module => $weight) {
                $overrides[$module] = $modules_types[$module];
            }
        }
        return $overrides;
    }

    /**
     * getRequest : decompose la requete en : langue, controleur, action
     * 
     * @access public
     * @return void
     */
    public function getRequest()
    {
        if (!(isset(Clementine::$register['request']) && isset(Clementine::$register['request']['CTRL']))) {
            // décompose la requête en elements
            $request = array ();
            $tmp_request_uri = Clementine::$register['request_uri'];
            $args_pos = strpos($tmp_request_uri, '?');
            if ($args_pos === 0) {
                $request_tmp = '';
                $args = $tmp_request_uri;
            } else {
                if ($args_pos) {
                    $request_tmp = substr($tmp_request_uri, 0, $args_pos);
                    $args = substr($tmp_request_uri, $args_pos);
                } else {
                    $request_tmp = $tmp_request_uri;
                    $args = '';
                }
            }
            $request_tmp = explode('/', $request_tmp);
            // extrait la langue demandee
            $lang_dispos = explode(',', __LANG_DISPOS__);
            $lang_candidat = '';
            if (isset($request_tmp[0])) {
                $lang_candidat = $request_tmp[0];
            }
            if ((count($lang_dispos) > 1) && $lang_candidat && in_array($lang_candidat, $lang_dispos)) {
                $request['LANG'] = $lang_candidat;
            } else {
                $request['LANG'] = __DEFAULT_LANG__;
                // si code langue demande invalide, redirige vers une 404 sauf pour la page d'accueil
                if ((count($lang_dispos) > 1) && strlen($lang_candidat)) {
                    header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found', true);
                    if (__DEBUGABLE__) {
                        $this->getHelper('debug')->err404_noLanguageCode();
                    }
                    $request['CTRL'] = 'errors';
                    $request['ACT'] = 'err404';
                }
            }
            // extrait le controleur et l'action, en tenant compte du decalage si le site est multilingue
            if (!isset($request['CTRL']) && !isset($request['ACT'])) {
                $decalage = (__LANG_DISPOS__ != __DEFAULT_LANG__) ? 1 : 0;
                $request['CTRL']    = strtolower(preg_replace('/[^a-zA-Z0-9_]/S', '_', strtolower(trim((isset($request_tmp[0 + $decalage]) && strlen(trim($request_tmp[0 + $decalage]))) ? trim($request_tmp[0 + $decalage]) : ''))));
                $request['ACT']     = strtolower(preg_replace('/[^a-zA-Z0-9_]/S', '_', trim((isset($request_tmp[1 + $decalage]) && strlen(trim($request_tmp[1 + $decalage]))) ? trim($request_tmp[1 + $decalage]) : '')));
                if (!strlen($request['CTRL'])) {
                    $request['CTRL'] = 'index';
                }
                if (!strlen($request['ACT'])) {
                    $request['ACT'] = 'index';
                }
            }
            $request['ARGS'] = $args;
            if (count($lang_dispos) > 1) {
                define('__BASE__', __BASE_URL__ . '/' . $request['LANG']);
                define('__WWW__', __WWW_ROOT__ . '/' . $request['LANG']);
                // URL equivalentes dans les autres langues
                $curpage = implode('/', $request);
                $request['EQUIV'] = array();
                foreach ($lang_dispos as $lang) {
                    $request['EQUIV'][$lang] = __WWW_ROOT__ . '/' . preg_replace('@^' . $request['LANG'] . '@', $lang . '', $curpage);
                }
            } else {
                define('__BASE__', __BASE_URL__);
                define('__WWW__', __WWW_ROOT__);
                // URL equivalente de la page courante
                $currequest = $request;
                array_shift($currequest);
                $curpage = implode('/', $currequest);
                $request['EQUIV'] = array();
                $request['EQUIV'][$request['LANG']] = __WWW_ROOT__ . '/' . $curpage;
            }
            // commodité : enregistre l'URL complète
            $request['FULLURL'] = $request['EQUIV'][$request['LANG']];
            if (isset($_SERVER['SERVER_NAME'])) {
                $request['METHOD'] = $_SERVER['REQUEST_METHOD'];
            } else {
                $request['METHOD'] = 'CLI';
            }
            // la requete est-elle une requete en AJAX ?
            $request['AJAX'] = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest') ? 1 : 0;
            // demande-t-on de vider le cache ?
            $request['NOCACHE'] = (isset($_SERVER['HTTP_PRAGMA']) && $_SERVER['HTTP_PRAGMA'] == 'no-cache') ? 1 : 0;
            Clementine::$register['request'] += $request;
        }
        return Clementine::$register['request'];
    }

    /**
     * _getRequestURI : reconstruit si nécessaire le contenu de $_SERVER['REQUEST_URI] et stocke dans Clementine::$register['request_uri']
     * 
     * @access private
     * @return void
     */
    private function _getRequestURI()
    {
        if (!(isset(Clementine::$register['request_uri']) && Clementine::$register['request_uri'])) {
            // selon appel HTTP ou CLI
            if (isset($_SERVER['SERVER_NAME'])) {
                Clementine::$register['request_uri'] = substr($_SERVER['REQUEST_URI'], (strlen(__BASE_URL__) + 1));
            } else {
                global $argv;
                Clementine::$register['request_uri'] = '';
                if (isset($argv[2])) {
                    $ctrl_act = explode('/', $argv[2], 2);
                    $ctrl = $ctrl_act[0];
                    Clementine::$register['request_uri'] = $ctrl;
                    $action = '';
                    if (isset($ctrl_act[1])) {
                        $action = $ctrl_act[1];
                        Clementine::$register['request_uri'] .= '/' . $action;
                    }
                }
            }
        }
        return Clementine::$register['request_uri'];
    }

    /**
     * _require : wrapper pour require afin d'éviter les écrasements de variables
     * 
     * @param mixed $file 
     * @param mixed $data 
     * @access private
     * @return void
     */
    private function _require($file, $data = null, $request = null)
    {
        require($file);
    }

    /**
     * _factory : renvoie une instance du controleur/modele $element
     * 
     * @param mixed $element 
     * @param mixed $type 
     * @param mixed $testonly : ne pas planter ni declencher le debug si aucune instance ne correspond
     * @param mixed $params 
     * @access private
     * @return void
     */
    private function _factory($element, $type, $testonly = 0, $params = null)
    {
        switch ($type) {
            case 'Model':
                $type_short = 'model';
                break;
            case 'Helper':
                $type_short = 'helper';
                break;
            case 'Controller':
                $type_short = 'ctrl';
                break;
            default:
                return false;
        }
        $element = ucfirst(strtolower($element));
        $elementname = ucfirst($element) . $type;
        if (__DEBUGABLE__ && !$testonly) {
            $this->debug_factory_init_file_stack($type_short);
        }
        if (!class_exists($elementname, false)) {
            $overrides = $this->getOverrides();
            foreach ($overrides as $current => $scope) {
                $current_class = $current . $elementname;
                $file_path = __FILES_ROOT__ . '/app/' . $scope . '/' . $current . '/' . $type_short . '/' . $current_class . '.php';
                if (file_exists($file_path)) {
                    if (isset($prev)) {
                        $parent_class = $prev . $elementname;
                        eval ('abstract class ' . $current_class . '_Parent extends ' . $parent_class . ' {}');
                    }
                    if (!class_exists($current_class . '_Parent', false)) {
                        $adopter = '__CLEMENTINE_CLASS_' . strtoupper($element) . '_' . strtoupper($type) . '_EXTENDS__';
                        if (defined($adopter)) {
                            if (!class_exists(constant($adopter), false)) {
                                // strips the "Controller/Model" part
                                $this->_factory(substr(constant($adopter), 0, - strlen($type)), $type, $testonly, $params);
                            }
                            eval ('abstract class ' . $current_class . '_Parent extends ' . constant($adopter) . ' {}');
                        } else {
                            if ($type == 'Controller') {
                                eval ('abstract class ' . $current_class . '_Parent extends Clementine {}');
                            } else {
                                // desactive les appels a getController depuis Model et Helper
                                eval ('abstract class ' . $current_class . '_Parent extends Clementine {
                                    public function getController($ctrl, $params = null) {
                                        $this->getHelper("debug")->getControllerFromModel();
                                    }
                                }');
                            }
                        }
                    }
                    if (__DEBUGABLE__ && !$testonly) {
                        $this->debug_factory_register_stack($type_short, $file_path);
                    }
                    $this->_require($file_path);
                    $prev = $current;
                }
            }
            if (isset($prev) && class_exists($prev . $elementname, false)) {
                eval ('class ' . $elementname . ' extends ' . $prev . $elementname . ' {}');
            } else {
                if ($type == 'Controller') {
                    if (__DEBUGABLE__ && !$testonly) {
                        $this->getHelper('debug')->err404_noSuchController($elementname);
                    }
                    return false;
                } else {
                    // erreur fatale : on a demande a charger un modele qui n'existe pas
                    if (!$testonly) {
                        if (__DEBUGABLE__ && !$testonly) {
                            $this->getHelper('debug')->errFatale_noSuchModel($type, $element);
                        }
                        die();
                    }
                }
            }
        }
        if ($type == 'Controller') {
            $request = $this->getRequest();
            $new_element = new $elementname($request, $params);
        } else {
            $new_element = new $elementname($params);
        }
        if (__DEBUGABLE__ && !$testonly) {
            $this->debug_factory($type_short, $new_element);
        }
        return $new_element;
    }

    /**
     * getModel : charge le modele le plus au sommet de la pile de surcharge
     * 
     * @param mixed $model 
     * @access public
     * @return void
     */
    public function getModel($model, $params = null)
    {
        return $this->_factory($model, 'Model', 0, $params);
    }

    /**
     * getHelper : charge le helper le plus au sommet de la pile de surcharge
     * 
     * @param mixed $helper 
     * @access public
     * @return void
     */
    public function getHelper($helper, $params = null)
    {
        return $this->_factory($helper, 'Helper', 0, $params);
    }

    /**
     * getController : charge le controleur demande le plus au sommet de la pile de surcharge
     * 
     * @param mixed $ctrl 
     * @access public
     * @return void
     */
    public function getController($ctrl, $params = null)
    {
        if (!Clementine::$_register['_forbid_getcontroller']) {
            return $this->_factory($ctrl, 'Controller', 0, $params);
        }
        if (__DEBUGABLE__) {
            $this->getHelper('debug')->getControllerFromBlock();
        }
        die();
    }

    /**
     * getBlock : charge le block demande le plus au sommet de la pile de surcharge
     * 
     * @param mixed $path 
     * @param mixed $data 
     * @param mixed $load_parent : pour charger le bloc de la surcharge precedente 
     * @param mixed $testonly : ne charge pas vraiment le block mais renvoie vrai s'il existe
     * @access public
     * @return void
     */
    public function getBlock($path, $data = null, $request = null, $ignores = null, $load_parent = false, $testonly = false)
    {
        ++Clementine::$_register['_forbid_getcontroller'];
        $path = strtolower($path);
        if (__DEBUGABLE__) {
            $this->getHelper('debug')->debugBlock_init();
        }
        $tmp_path_array = explode('/', $path);
        $path_array = array((isset($tmp_path_array[0]) && $tmp_path_array[0]) ? $tmp_path_array[0] : 'index',
                            (isset($tmp_path_array[1]) && $tmp_path_array[1]) ? $tmp_path_array[1] : 'index');
        $niveau3 = null;
        if (isset($tmp_path_array[2])) {
            $niveau3 = $tmp_path_array[2];
        }
        // prend le bloc du theme le plus haut possible dans la surcharge
        $reverse = array_reverse($this->getOverrides());
        if ($load_parent && isset(Clementine::$_register['_parent_loaded_blocks_files'][$path])) {
            $nb_shift = count(Clementine::$_register['_parent_loaded_blocks_files'][$path]);
            for (; $nb_shift; --$nb_shift) {
                array_shift($reverse);
            }
        }
        $vue_affichee = 0;
        $vue_recursive = 0;
        $module = '';
        $reverse_keys = array_keys($reverse);
        $pos = array_search($ignores, $reverse_keys);
        foreach ($reverse as $module => $scope) {
            $a_ignorer = 0;
            if (count($ignores)) {
                // on ignore $module s'il est avant (ou au même rang que) l'element $ignores dans les overrides
                if ($pos !== false) {
                    $curpos = array_search($module, $reverse_keys);
                    if ($curpos !== false && $curpos <= $pos) {
                        $a_ignorer = 1;
                    }
                }
            }
            $file = __FILES_ROOT__ . '/app/' . $scope . '/' . $module . '/view/' . $path_array[0] . '/' . $path_array[1];
            if ($niveau3) {
                $file .= '/' . $niveau3;
            }
            $file .= '.php';
            $block_exists = file_exists($file);
            if ($block_exists) {
                $load_block = 0;
                if (!isset(Clementine::$_register['_parent_loaded_blocks_files'][$path])) {
                    $load_block = 1;
                } else {
                    // si le block n'est pas deja charge
                    if (!in_array($file, Clementine::$_register['_parent_loaded_blocks_files'][$path])) {
                        $load_block = 1;
                    } else {
                        // si load parent, ce n'est pas un appel recursif
                        if ($load_parent) {
                            continue;
                        }
                    }
                }
                if ($load_block) {
                    // securite pour eviter les boucles infinies : si j'ai la ligne getBlock('index/index') placee dans
                    // le bloc charge par getBlock('index/index') j'obtiens une recursivite infinie !
                    // on empile le block avant le require...
                    Clementine::$_register['_parent_loaded_blocks_files'][$path][] = $file;
                    Clementine::$_register['_parent_loaded_blocks'][] = $path;
                    // debug special : mise en evidence des blocs charges
                    if (__DEBUGABLE__) {
                        $this->getHelper('debug')->debugBlock_register_stack($scope, $module, $path, $file, array('ignores' => $ignores, 'is_ignored' => $a_ignorer), $load_parent);
                    }
                    // semaphore pour eviter les appels a getController depuis un block
                    if (!$testonly && !$a_ignorer) {
                        if (__DEBUGABLE__ && Clementine::$config['clementine_debug']['block_filename']) {
                            $depth = count(Clementine::$_register['_parent_loaded_blocks']);
                            echo "\r\n<!-- (depth " . $depth . ') begins ' . $file . " -->\r\n";
                        }
                        if (!$request) {
                            $request = $this->getRequest();
                        }
                        $this->_require($file, $data, $request);
                        if (__DEBUGABLE__ && Clementine::$config['clementine_debug']['block_filename']) {
                            $depth = count(Clementine::$_register['_parent_loaded_blocks']);
                            echo "\r\n<!-- (depth " . $depth . ') end of ' . $file . " -->\r\n";
                        }
                    }
                    // ... et on depile le block apres le require
                    array_pop(Clementine::$_register['_parent_loaded_blocks_files'][$path]);
                    array_pop(Clementine::$_register['_parent_loaded_blocks']);
                    if (!$a_ignorer) {
                        $vue_affichee = 1;
                        break;
                    }
                } else {
                    // warning : recursive block call
                    if (__DEBUGABLE__) {
                        $this->getHelper('debug')->debugBlock_warningRecursiveCall($path);
                    }
                    $vue_recursive = 1;
                    break;
                }
            }
        }
        $found = 1;
        if (!$vue_affichee && !$vue_recursive) {
            $found = 0;
        } else if ($vue_affichee && !$vue_recursive) {
            if (__DEBUGABLE__) {
                $this->getHelper('debug')->debugBlock_dumpStack($scope, $module, $path_array);
            }
        }
        if (!$found) {
            $adopter = '__CLEMENTINE_CLASS_' . strtoupper($path_array[0]) . '_VIEW_EXTENDS__';
            if (defined($adopter)) {
                // le block n'a pas de parent mais il est adopte
                $tuteur_path = substr(strtolower(constant($adopter)), 0, -4) . '/' . $path_array[1]; // strips de "view" part
                if ($niveau3) {
                    $tuteur_path .= '/' . $niveau3;
                }
                $found = $this->getBlock($tuteur_path, $data, $request, $ignores, $load_parent, $testonly);
            }
        }
        if (__DEBUGABLE__ && !$found && !$testonly && !$load_parent) {
            $this->getHelper('debug')->err404_noSuchBlock($path);
        }
        --Clementine::$_register['_forbid_getcontroller'];
        return $found;
    }

    /**
     * getParentBlock : charge le block parent du block depuis lequel est appelee cette fonction
     * 
     * @param mixed $data 
     * @access public
     * @return void
     */
    public function getParentBlock($data = null, $request = null, $ignores = null)
    {
        $parent_blocks = Clementine::$_register['_parent_loaded_blocks'];
        $last_block = array_pop($parent_blocks);
        if ($last_block) {
            return $this->getBlock($last_block, $data, $request, $ignores, true);
        } else {
            return 0;
        }
    }

    /**
     * getBlockHtml : wrapper pour getBlock qui renvoie le code HTML au lieu de l'afficher grace a la bufferisation de sortie
     * 
     * @param mixed $path 
     * @param mixed $data 
     * @param mixed $load_parent 
     * @access public
     * @return void
     */
    public function getBlockHtml($path, $data = null, $request = null, $ignores = null, $load_parent = false)
    {
        ob_start();
        $this->getBlock($path, $data, $request, $ignores, $load_parent);
        $script = ob_get_contents();
        ob_end_clean();
        return $script;
    }

    /**
     * dontGetBlock : pour que Clementine ne charge pas automatiquement la vue
     *                associee au controleur principal
     * 
     * @access public
     * @return void
     */
    public function dontGetBlock()
    {
        return array ('dont_getblock' => true);
    }

    /**
     * _canGetFactory : renvoie vrai si l'element (Model, Helper ou Controller) est chargeable
     *                  cette fonction chargera la classe demandee si elle n'est pas deja chargee
     * 
     * @param mixed $element 
     * @param mixed $type 
     * @param mixed $params 
     * @access private
     * @return void
     */
    private function _canGetFactory($element, $type, $params = null)
    {
        if ($type != 'Model' && $type != 'Helper' && $type != 'Controller') {
            return false;
        }
        if ($type == 'Controller' && Clementine::$_register['_forbid_getcontroller']) {
            return false;
        }
        $element = ucfirst(strtolower($element));
        if (!class_exists($element . $type)) {
            // charge la classe si possible, car elle n'est pas deja chargee
            $this->_factory($element, $type, 1, $params);
            return class_exists($element . $type);
        }
        return true;
    }

    /**
     * canGetModel : renvoie vrai si le Model est chargeable
     *               cette fonction chargera la classe demandee si elle n'est pas deja chargee
     * 
     * @param mixed $model 
     * @param mixed $params 
     * @access public
     * @return void
     */
    public function canGetModel($model, $params = null)
    {
        return $this->_canGetFactory($model, 'Model', $params = null);
    }

    /**
     * canGetHelper : renvoie vrai si le Helper est chargeable
     *                cette fonction chargera la classe demandee si elle n'est pas deja chargee
     * 
     * @param mixed $helper 
     * @param mixed $params 
     * @access public
     * @return void
     */
    public function canGetHelper($helper, $params = null)
    {
        return $this->_canGetFactory($helper, 'Helper', $params = null);
    }

    /**
     * canGetController : renvoie vrai si le Controller est chargeable
     *                    cette fonction chargera la classe demandee si elle n'est pas deja chargee
     * 
     * @param mixed $ctrl 
     * @param mixed $params 
     * @access public
     * @return void
     */
    public function canGetController($ctrl, $params = null)
    {
        return $this->_canGetFactory($ctrl, 'Controller', $params = null);
    }

    /**
     * canGetBlock : wrapper de getBlock qui renvoie vrai seulement si le block peut être chargé
     * 
     * @param mixed $path 
     * @param mixed $data 
     * @param mixed $load_parent 
     * @access public
     * @return void
     */
    public function canGetBlock($path, $data = null, $request = null, $ignores = null, $load_parent = false)
    {
        return $this->getBlock($path, $data, $request, $ignores, $load_parent, true);
    }

    /**
     * getCurrentModule : renvoie le nom du module dans lequel est fait l'appel a cette fonction
     * 
     * @access public
     * @return void
     */
    public function getCurrentModule()
    {
        /*$backtrace = debug_backtrace();*/
        /*$file = $backtrace[0]['file']; */
        /*if ($file == __FILES_ROOT__ . '/app/share/core/lib/Clementine.php') {*/
            /*error_log('interneeee');*/
            /*$file = $backtrace[1]['file']; */
        /*}*/
        /*return preg_replace('@/.*@', '', substr($file, strlen(__FILES_ROOT__ . '/app/...../')));*/
        $module = '';
        $class = get_class($this);
        $types = array('Controller', 'Model', 'Helper');
        foreach ($types as $type) {
            if (strpos($class, $type) !== false) {
                $module = strtolower(substr($class, 0, - strlen($type)));
                break;
            }
        }
        return $module;
    }

    public function getModuleConfig($module = null)
    {
        if (!$module) {
            $module = $this->getCurrentModule();
        }
        if (isset(Clementine::$config['module_' . $module])) {
            return Clementine::$config['module_' . $module];
        }
        return false;
    }

    /**
     * apply_config : determine la config et applique en consequence les modifs au comportement de PHP
     * 
     * @access private
     * @return void
     */
    public function apply_config()
    {
        // charge la config
        $config = $this->_get_config();
        // definit les constantes necessaires au fonctionnement de l'adoption
        $adopters = array('model', 'view', 'controller');
        foreach ($adopters as $adopter) {
            if (isset($config['clementine_inherit_' . $adopter]) && is_array($config['clementine_inherit_' . $adopter])) {
                foreach ($config['clementine_inherit_' . $adopter] as $classname => $parentclassname) {
                    define('__CLEMENTINE_CLASS_' . strtoupper($classname) . '_' . strtoupper($adopter) . '_EXTENDS__', ucfirst($parentclassname) . ucfirst($adopter));
                }
            }
        }
        if (isset($config['clementine_inherit_config']) && is_array($config['clementine_inherit_config'])) {
            foreach ($config['clementine_inherit_config'] as $module => $parentmodule) {
                define('__CLEMENTINE_CONFIG_' . strtoupper($module) . '_EXTENDS__', $parentmodule . '_CONFIG');
                if (!isset($config['module_' . $module])) {
                    $config['module_' . $module] = array();
                }
                $config['module_' . $module] = array_merge($config['module_' . $parentmodule], $config['module_' . $module]);
            }
        }
        // valeurs par défaut et calcul de variables de configuration si elles n'ont pas deja ete definies
        if (isset($config['clementine_global']['os'])) {
            define('__OS__', $config['clementine_global']['os']);
        } else {
            $uname = explode(' ', php_uname('s'));
            define('__OS__', strtolower($uname[0]));
            unset($uname);
        }
        // si appel CLI
        $usage = 'Usage : /usr/bin/php index.php "http://www.site.com" "ctrl[/action]" "[id=1&query=string]"';
        if (!isset($_SERVER['SERVER_NAME'])) {
            global $argv;
            if (isset($argv[1]) && preg_match('@https?://@', $argv[1])) {
                define('__WWW_ROOT__'   , $argv[1]);
            } else {
                echo $usage;
                die();
            }
        }
        if (isset($config['clementine_global']['base_url'])) {
            define('__BASE_URL__', $config['clementine_global']['base_url']);
        } else {
            // selon appel HTTP ou CLI
            if (isset($_SERVER['SERVER_NAME'])) {
                $tmp = substr(__FILE__, strlen(preg_replace('/\/$/S', '', $_SERVER['DOCUMENT_ROOT'])));
                $tmp = substr($tmp, 0, - strlen('/app/share/core/lib/Clementine.php'));
                if (__OS__ == 'windows') {
                    $tmp = str_replace('\\', '/', $tmp);
                }
                define('__BASE_URL__', $tmp);
                unset ($tmp);
                define('__FILES_ROOT__'     , str_replace('//', '/', $_SERVER['DOCUMENT_ROOT'] . __BASE_URL__));
            } else {
                global $argv;
                if (isset($argv[1]) && preg_match('@https?://@', $argv[1])) {
                    $tmp = preg_replace('@https?://[^/]+@', '', $argv[1]);
                    define('__BASE_URL__', $tmp);
                    unset ($tmp);
                    define('__FILES_ROOT__'     , realpath(dirname(__FILE__) . '/../../../../'));
                } else {
                    echo $usage;
                    die();
                }
            }
        }
        if (isset($config['clementine_global']['php_encoding'])) {
            define('__PHP_ENCODING__', $config['clementine_global']['php_encoding']);
        } else {
            define('__PHP_ENCODING__', 'UTF-8');
        }
        if (isset($config['clementine_global']['html_encoding'])) {
            define('__HTML_ENCODING__', $config['clementine_global']['html_encoding']);
        } else {
            define('__HTML_ENCODING__', 'utf-8');
        }
        if (isset($config['clementine_global']['sql_encoding'])) {
            define('__SQL_ENCODING__', $config['clementine_global']['sql_encoding']);
        } else {
            define('__SQL_ENCODING__', 'utf8');
        }
        $protocol = 'http://';
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']) {
            $protocol = 'https://';
        }
        // si appel HTTP
        if (isset($_SERVER['SERVER_NAME'])) {
            define('__WWW_ROOT__'       , $protocol . $_SERVER['SERVER_NAME'] . __BASE_URL__);
        }
        $overrides = $this->getOverrides();
        foreach ($overrides as $module => $scope) {
            define('__WWW_ROOT_'   . strtoupper($module) . '__', __WWW_ROOT__   . '/app/' . $scope . '/' . $module);
            define('__FILES_ROOT_' . strtoupper($module) . '__', __FILES_ROOT__ . '/app/' . $scope . '/' . $module);
        }
        if (isset($config['clementine_debug']) && 
            isset($config['clementine_debug']['enabled']) && $config['clementine_debug']['enabled'] && 
            isset($config['clementine_debug']['allowed_ip']) && 
            ((!$config['clementine_debug']['allowed_ip']) || (in_array($_SERVER['REMOTE_ADDR'], explode(',', $config['clementine_debug']['allowed_ip']))))) {
            define('__DEBUGABLE__', '1');
        } else {
            define('__DEBUGABLE__', '0');
        }
        // modifications du comportement de PHP selon la configuration choisie
        if (__DEBUGABLE__ && Clementine::$config['clementine_debug']['display_errors']) {
            ini_set('display_errors', 'on');
            error_reporting(E_ALL);
        } else {
            ini_set('display_errors', 'off');
        }
        // definit la langue par defaut
        $lang_dispos = array();
        if (isset($config['clementine_global']['lang'])) {
            $lang_dispos = explode(',', $config['clementine_global']['lang']);
            define('__LANG_DISPOS__', $config['clementine_global']['lang']);
        } else {
            define('__LANG_DISPOS__', '');
        }
        if (isset($lang_dispos[0]) && $lang_dispos[0]) {
            define('__DEFAULT_LANG__', $lang_dispos[0]);
        } else {
            echo "<br />\n" . '<strong>Clementine warning</strong>: missing value in <strong>config.ini</strong>' . " for <em>lang</em><br />\n";
        }
        mb_internal_encoding(__PHP_ENCODING__);
        // set session cookie path to base url (so you can mix projects on the same domain without mixing sessions)
        ini_set('session.cookie_path', __BASE_URL__ . '/');
        // securite : on ne passe l'id de session que par cookie
        ini_set('session.use_trans_sid', 0);
        // contre le duplicate content : on ne passe jamais l'id de session dans l'url
        ini_set('session.use_only_cookies', 1);
        // locale de PHP
        setlocale(LC_ALL, Clementine::$config['clementine_global']['locale_LC_ALL']);
        setlocale(LC_COLLATE, Clementine::$config['clementine_global']['locale_LC_COLLATE']);
        setlocale(LC_CTYPE, Clementine::$config['clementine_global']['locale_LC_CTYPE']);
        setlocale(LC_MONETARY, Clementine::$config['clementine_global']['locale_LC_MONETARY']);
        setlocale(LC_NUMERIC, Clementine::$config['clementine_global']['locale_LC_NUMERIC']);
        setlocale(LC_TIME, Clementine::$config['clementine_global']['locale_LC_TIME']);
        if (defined('LC_MESSAGES')) {
            setlocale(LC_MESSAGES, Clementine::$config['clementine_global']['locale_LC_MESSAGES']);
        }
        // force l'encodage du site mais n'envoie les headers que si possible (sinon PHPUnit n'aime pas...)
        if (!headers_sent()) {
            header('Content-type: text/html; charset="' . __HTML_ENCODING__ . '"');
        }
        if (__DEBUGABLE__ && Clementine::$config['clementine_debug']['overrides']) {
            $message = '<table width="100%" style="font-size: 12px; line-height: 1.4em; text-align: left; "><thead><tr><th>module</th><th>version</th><th>type</th><th>poids</th></tr></thead><tbody>';
            $reverse_overrides = array_reverse($overrides);
            foreach ($reverse_overrides as $module => $scope) {
                $infos = $this->getModuleInfos($module);
                $message .= "<tr><td>$module</td><td>" . $infos['version'] . "</td><td>$overrides[$module]</td><td>" . $infos['weight'] . "</td></tr>";
            }
            $message .= '</tbody></table>';
            Clementine::$clementine_debug['overrides'][] = $message;
        }
        Clementine::$config = $config;
    }

    /**
     * _get_config 
     * 
     * @access private
     * @return void
     */
    private function _get_config()
    {
        if (!(isset(Clementine::$config['clementine_global']))) {
            if (!is_file(realpath(dirname(__FILE__) . '/../../../local/site/etc/config.ini'))) {
                echo "<br />\n" . '<strong>Clementine fatal error</strong>: fichier de configuration manquant : /app/local/site/etc/config.ini';
                die();
            }
            $overrides = $this->getOverrides();
            $app_path = dirname(__FILE__) . '/../../../';
            $config = array();
            foreach ($overrides as $module => $scope) {
                $filepath = $app_path . $scope . '/' . $module . '/etc/config.ini';
                if (is_file($filepath)) {
                    // php < 5.3 compatibility
                    if (version_compare(PHP_VERSION, '5.3.0') >= 0) {
                        $tmp = parse_ini_file($filepath, true, INI_SCANNER_RAW);
                    } else {
                        $tmp = parse_ini_file($filepath, true);
                    }
                    if (is_array($tmp)) {
                        $config = array_merge_recursive($config, $tmp);
                    }
                }
            }
            // surcharge : ecrase avec la derniere valeur
            foreach ($config as &$section) {
                foreach ($section as $key => $val) {
                    if (is_array($val)) {
                        $cnt = count($val);
                        if ($cnt) {
                            $section[$key] = $val[$cnt - 1];
                        }
                    }
                }
            }
            if (!isset($config['clementine_global'])) {
                echo "<br />\n<strong>Clementine fatal error</strong>: missing <em>[clementine_global]</em> section in <strong>config.ini</strong><br />\n";
                die();
            }
            Clementine::$config = $config;
        }
        return Clementine::$config;
    }

    /**
     * debug : affiche si demande des informations de debug en bas de page
     * 
     * @access private
     * @return void
     */
    public function debug()
    {
        $request = $this->getRequest();
        if (__DEBUGABLE__ && !$request['AJAX'] && !defined('__NO_DEBUG_DIV__')) {
            $types = array('hook'       => 'Hooks appelés sur cette page',
                           'ctrl'       => 'Contrôleurs de cette page',
                           'model'      => 'Modèles chargés sur cette page',
                           'block'      => 'Blocks chargés sur cette page',
                           'helper'     => 'Helpers chargés sur cette page',
                           'heritage'   => '<span style="color: red">Sanity-check sur les héritages : pour éviter les conflits entre surcharges</span>',
                           'overrides'  => 'Modules chargés (et poids)',
                           'sql'        => 'Log des requêtes SQL exécutées');
?>
        <div id="Clementine_debug_div" style="background: #EEE; font-family: courier; font-size: 14px; padding: 0.5em; -moz-border-radius: 5px; " >
            <div style="text-align: right; ">
            <strong>DEBUG</strong>
            <span 
                style="cursor: pointer;"
                onclick='document.getElementById("Clementine_debug_ol").style.display = (parseInt(document.cookie.substring(parseInt("Clementine_debug_div_hide".length) + document.cookie.indexOf("Clementine_debug_div_hide") + 1, parseInt("Clementine_debug_div_hide".length) + document.cookie.indexOf("Clementine_debug_div_hide") + 2)) ? "block" : "none"); document.cookie="Clementine_debug_div_hide=" + escape(parseInt(document.cookie.substring(parseInt("Clementine_debug_div_hide".length) + document.cookie.indexOf("Clementine_debug_div_hide") + 1, parseInt("Clementine_debug_div_hide".length) + document.cookie.indexOf("Clementine_debug_div_hide") + 2)) ? "0" : "1") + "; path=<?php echo __BASE_URL__ . "/"; ?>"'>[toggle]</span>
            </div>
            <ol id="Clementine_debug_ol" style="text-align: left; padding: 0.5em 0; margin: 0; list-style-position: inside; <?php echo (isset($_COOKIE['Clementine_debug_div_hide']) && ($_COOKIE['Clementine_debug_div_hide'])) ? 'display: none; ' : 'display: block; '; ?>">
<?php
            // affiche les messages par type
            foreach ($types as $type => $libelle) {
                if (isset(Clementine::$clementine_debug[$type]) && count(Clementine::$clementine_debug[$type])) {
?>
                    <li style="margin: 3px; border: solid #AAA 3px; padding: 3px; -moz-border-radius: 5px; background-color: #CCC; font-size: 12px; line-height: 1.4em; z-index: 9998">
                        <strong><?php echo $libelle; ?></strong>
                        <table style="width: 100%; "<?php echo ($type == 'sql') ? ' class="clementine_debug-dataTables"' : ''; ?>>
<?php
                    if (isset(Clementine::$clementine_debug[$type])) {
                        if (isset(Clementine::$clementine_debug[$type][0]) && is_array(Clementine::$clementine_debug[$type][0])) {
                            $titles = array_keys(Clementine::$clementine_debug[$type][0]);
?>
                            <thead>
                                <tr>
<?php
                            foreach ($titles as $title) {
                                echo '<th>' . $title . '</th>';
                            }
?>
                                </tr>
                            </thead>
<?php
                        }
                        if ($type == 'block') {
                            Clementine::$clementine_debug[$type] = array_reverse(Clementine::$clementine_debug[$type]);
                        }
                        // debug sql : cumul du temps passe en *_query
                        $duree_totale_sql = 0;
                        foreach (Clementine::$clementine_debug[$type] as $msg) {
                            // debug sql : cumul du temps passe en *_query et conversion de microsecondes a millisecondes
                            if ($type == 'sql') {
                                $msg['duree'] *= 1000;
                                $duree_totale_sql += $msg['duree'];
                                $msg['duree'] = number_format($msg['duree'], 3, ',', ' ') . '&nbsp;ms';
                            }
?>
                            <tr style="background-color: #DDD; border: solid #CCC 3px; "><td style="white-space: pre-wrap; padding: 5px; "><?php
                            if (is_array($msg)) {
                                echo implode('</td><td style="white-space: pre-wrap; padding: 5px; ">', $msg);
                            } else {
                                echo $msg;
                            }
?></td></tr>
<?php
                        }
?>
                        </table>
                        <table style="width: 100%; ">
<?php
                        // debug sql : cumul du temps passe en *_query
                        if ($type == 'sql') {
?>
                            <tr style="background-color: #DDD; border: solid #CCC 3px; "><td colspan="3" style="padding: 5px; ">
                                <strong>Durée totale passé en query (hors fetch) : </strong><?php echo number_format($duree_totale_sql, 3, ',', ' '); ?> ms
                            </td></tr>
<?php
                        }
                    }
?>
                        </table>
                    </li>
<?php
                }
            }
            // debug non classe dans $types
            foreach (Clementine::$clementine_debug as $type => $msg) {
                if (!in_array($type, array_keys($types), true)) {
?>
                    <li style="margin: 3px; border: solid #AAA 3px; padding: 3px; -moz-border-radius: 5px; background-color: #CCC; font-size: 12px; line-height: 1.4em; z-index: 9998">
<?php
                    if (is_array($msg)) {
                        foreach ($msg as $message) {
                            echo $message . '<br />';
                        }
                    } else {
                        echo $msg;
                    }
?>
                    </li>
<?php
                }
            }
?>
            </ol>
        </div>
<?php
        }
    }

    public function debug_factory_init_file_stack($type)
    {
        if (__DEBUGABLE__ && Clementine::$config['clementine_debug'][$type]) {
            if (!isset(Clementine::$_register['clementine_debug'])) {
                Clementine::$_register['clementine_debug'] = array();
            }
            if (!isset(Clementine::$_register['clementine_debug']['files_stack'])) {
                Clementine::$_register['clementine_debug']['files_stack'] = array();
            }
            // on remet a 0 la pile
            Clementine::$_register['clementine_debug']['files_stack'][$type] = array();
        }
    }

    public function debug_factory_register_stack($type, $file_path)
    {
        if (__DEBUGABLE__ && Clementine::$config['clementine_debug'][$type]) {
            Clementine::$_register['clementine_debug']['files_stack'][$type][] = 'extends ' . $file_path;
        }
    }

    public function debug_factory($type, $element)
    {
        if (__DEBUGABLE__ && Clementine::$config['clementine_debug'][$type]) {
            // affiche dans le tableau $this->debug l'ordre de surcharge pour ce controleur/modele
            $tmp = $element;
            $elements_stack = array(get_class($tmp));
            for (; $parent = get_parent_class($tmp); $tmp = $parent) {
                if (substr($parent, - (strlen($type . '_Parent'))) == $type . '_Parent') {
                    continue;
                }
                $elements_stack[] = $parent;
            }
            $files_stack = Clementine::$_register['clementine_debug']['files_stack'][$type];
            $elt = array_shift($elements_stack);
            if (count($files_stack)) {
                Clementine::$clementine_debug[$type][] = '<strong>' . $elt . '</strong><br />' . implode("<br />", array_reverse($files_stack));
            }
            // verifie que les methodes non heritees sont bien prefixees par le nom du module
            if (__DEBUGABLE__ && Clementine::$config['clementine_debug']['heritage']) {
                $final_name = array_pop($elements_stack);
                foreach ($elements_stack as $step) {
                    if (isset($oldstep)) {
                        $differences = array_diff(get_class_methods($step), get_class_methods($oldstep));
                        $module_name = substr($step, 0, - strlen($final_name));
                        foreach ($differences as $diff) {
                            if (strpos($diff, $module_name) !== 0) {
                                if ($type == 'Model' || ((substr($diff, - strlen('Action')) !== 'Action') && ($diff !== '__construct'))) {
                                    Clementine::$clementine_debug['heritage'][$type][] = '<strong>' . $step . '::' . $diff . '()</strong> n\'est pas une surcharge et devrait donc s\'appeler <strong>' . $module_name . ucfirst($diff) . '()</strong>';
                                }
                            }
                        }
                    }
                    $oldstep = $step;
                }
            }
        }
    }

    public function debug_overrides_module_twin($module)
    {
        if (__DEBUGABLE__) {
            echo "<br />\n" . '<strong>Clementine fatal error</strong>: directories <em>app/share/' . $module . '</em> and <em>app/local/' . $module . '</em> can not coexist';
        }
    }

    /**
    * getModuleInfos : renvoie le poids d'un module installé
    * 
    * @param mixed $module 
    * @access public
    * @return void
    */
    public function getModuleInfos($module)
    {
        $module = preg_replace('/[^a-zA-Z0-9_]/S', '', $module);
        $types = array('share', 'local');
        foreach ($types as $type) {
            $filepath = realpath(dirname(__FILE__) . '/../../../' . $type . '/' . $module . '/etc/module.ini');
            if (is_file($filepath)) {
                $config = array();
                // php < 5.3 compatibility
                if (version_compare(PHP_VERSION, '5.3.0') >= 0) {
                    $infos = parse_ini_file($filepath, true, INI_SCANNER_RAW);
                } else {
                    $infos = parse_ini_file($filepath, true);
                }
                if (isset($infos['weight'])) {
                    $infos['weight'] = (float) $infos['weight'];
                    return $infos;
                }
            }
        }
        return false;
    }

    /**
     * map_url : met en place un url_rewriting dans Clementine par un preg_replace(). A utiliser depuis le hook 'before_first_getRequest'
     * 
     * @param mixed $from_expreg 
     * @param mixed $to 
     * @param mixed $redirection_http : effectue une redirection HTTP de code $redirection_http au lieu d'un mapping d'url
     * @access public
     * @return void
     */
    public function map_url ($from_expreg, $to, $redirection_http = null)
    {
        if (!(isset(Clementine::$register['request']) && isset(Clementine::$register['request']['CTRL']))) {
            // multilingue : separe l'url demandee et le prefixe langue
            $prefixe_langue = '';
            if (count(explode(',', __LANG_DISPOS__)) > 1) {
                $matches = array();
                preg_match('/^[a-z]+\//', Clementine::$register['request_uri'], $matches);
                if (isset($matches[0])) {
                    $prefixe_langue = $matches[0];
                }
            }
            $old_request_uri = Clementine::$register['request_uri'];
            Clementine::$register['request_uri'] = preg_replace('#' . $from_expreg . '#', $to, Clementine::$register['request_uri']);
            // ajoute les parametres GET de la nouvelle URL au tableau $_GET s'ils n'y sont pas deja
            $pos = strpos(Clementine::$register['request_uri'], '?');
            if ($pos !== false) {
                $query_string = substr(Clementine::$register['request_uri'], $pos + 1);
                $params = explode('&', $query_string);
                foreach ($params as $param) {
                    if ($param) {
                        if (strpos($param, '=') !== false) {
                            list($key, $val) = explode('=', $param, 2);
                        } else {
                            $key = $param;
                        }
                        if (!array_key_exists($key, $_GET)) {
                            $_GET[$key] = $val;
                            Clementine::$register['request']['GET'] = $_GET;
                        }
                    }
                }
            }
            if (Clementine::$register['request_uri'] != $old_request_uri) {
                if ($redirection_http) {
                    // si appel en CLI on affiche un message à la place
                    if (!isset($_SERVER['SERVER_NAME'])) {
                        echo ('Redirects with code ' . $redirection_http . ' to: ' . __WWW_ROOT__ . '/' . Clementine::$register['request_uri']);
                    } else {
                        header('Location: ' . __WWW_ROOT__ . '/' . Clementine::$register['request_uri'], true, $redirection_http);
                    }
                    die();
                } else {
                    if (__DEBUGABLE__ && Clementine::$config['clementine_debug']['hook']) {
                        Clementine::$clementine_debug['hook'][] = '<strong>$this->hook(\'before_first_getController\')</strong><a href="' . __BASE_URL__ . '/' . $from_expreg . '">' . __BASE_URL__ . '/' . $from_expreg . '</a> => 
                                                                                                  <a href="' . __BASE_URL__ . '/' . $to . '">' . __BASE_URL__ . '/' . $to . '</a>';
                    }
                }
            }
        } elseif (__DEBUGABLE__ && Clementine::$config['clementine_debug']['display_errors']) {
            $backtrace = debug_backtrace();
            $errfile = $backtrace[0]['file'];
            $errline = $backtrace[0]['line'];
            echo "<br />\n" . '<strong>Clementine warning</strong>: map_url() must be called before getRequest() in <strong>' . $errfile . '</strong> on line <strong>' . $errline . '</strong>' . "<br />\n";
        }
    }

    /**
     * canonical_url : remappe une url vers une route Clementine, de manière à rendre l'url canonique
     * 
     * @param mixed $from : url visible, par exemple "accueil"
     * @param mixed $to : route Clementine associée, par exemple "index/index"
     * @access public
     * @return void
     */
    public function canonical_url ($from, $to)
    {
        $this->map_url('^' . $to . '(\?.*)*$', $from . '\\1', 301);      
        $this->map_url('^' . $from . '(\?.*)*$', $to . '\\1');           
    }

    /**
     * stripslashesRecursive : recursive stripslashes
     * 
     * @param mixed $array 
     * @access public
     * @return void
     */
    public function stripslashesRecursive($array)
    {
        $new = array();
        foreach ($array as $key => $value) {
            $key = stripslashes($key);
            if (is_array($value)) {
                $new[$key] = $this->stripslashesRecursive($value);
            } else {
                $new[$key] = stripslashes($value);
            }
        }
        return $new;
    }

}
?>
