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
    static private $_register = array(
        'all_blocks' => array(),
        '_handled_errors' => 0,
        '_parent_loaded_blocks' => array(),
        '_parent_loaded_blocks_files' => array(),
        '_canGetBlock' => array(),
        '_forbid_getcontroller' => 0
    );

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
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        // verifie si la fonction est appelee au moyen de parent::
        if (isset($trace[1]) && isset($trace[2]) && isset($trace[1]['class']) && isset($trace[1]['function']) && isset($trace[2]['class']) && isset($trace[2]['function'])) {
            if ((strtolower($trace[1]['function']) == strtolower($trace[2]['function'])) && (strtolower(get_parent_class($trace[2]['class'])) == strtolower($trace[1]['class']))) {
                // l'appel de parent::method() ne doit pas planter, car sinon il n'y a plus d'independance des modules
                $call_parent = 1;
            }
        }
        if (!$call_parent) {
            if (!defined('__DEBUGABLE__') || __DEBUGABLE__) {
                $methodname = $trace[1]['class'] . $trace[1]['type'] . $trace[1]['function'] . '()';
                Clementine::$register['clementine_debug_helper']->trigger_error("Call to undefined method " . $methodname, E_USER_ERROR, 2);
            }
            die();
        }
    }

    /**
     * __construct : selon le modele de surcharge choisi dans ce framework, l'appel de parent::__construct() ne doit pas planter si la fonction n'existe pas
     *
     * @access public
     * @return void
     */
    public function __construct()
    {
    }

    /**
     * run : lance l'application construite sur l'architecture MVC
     *
     * @access public
     * @return void
     */
    public function run()
    {
        Clementine::$_register['mvc_generation_begin'] = microtime(true);
        Clementine::$_register['use_apc'] = ini_get('apc.enabled');
        $erreur_404 = 0;
        $this->apply_config();
        // (nécessaire pour map_url() qu'on appelle depuis le hook before_request) : initialise Clementine::$register['request']
        // avant même le premier getRequest(), et supprime les slashes rajoutes par magic_quotes_gpc
        Clementine::$register['request'] = new ClementineRequest();
        if (__DEBUGABLE__) {
            $debug = $this->getHelper('debug');
            Clementine::$register['clementine_debug_helper'] = $debug;
        }
        $this->_getRequestURI();
        $this->hook('before_request', Clementine::$register['request']);
        $this->populateRequest();
        $request = $this->getRequest();
        $this->hook('before_first_getController');
        $controller = $this->getController($request->CTRL, array(
            'no_mail_if_404' => true
        ));
        $noblock = false;
        if (!$controller) {
            if (!$erreur_404) {
                if ($request->INVOCATION_METHOD == 'CLI') {
                    header('CLI' . ' 404 Not Found', true);
                } else {
                    header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found', true);
                }
                $erreur_404 = 1;
            }
        } else {
            $fromcache = null;
            if (Clementine::$_register['use_apc']) {
                if ($request->server('string', 'HTTP_PRAGMA') == 'no-cache') {
                    apc_delete('clementine_core-all_blocks');
                }
                Clementine::$_register['all_blocks'] = apc_fetch('clementine_core-all_blocks', $fromcache);
            }
            if (!$fromcache) {
                $mask = __FILES_ROOT__ . '/app/{*/,}*/*/view/*/{*/,}*.php';
                Clementine::$_register['all_blocks'] = array_flip(glob($mask, GLOB_BRACE|GLOB_NOSORT|GLOB_NOCHECK));
                unset(Clementine::$_register['all_blocks'][$mask]);
                if (Clementine::$_register['use_apc']) {
                    apc_store('clementine_core-all_blocks', Clementine::$_register['all_blocks']);
                }
            }
            $this->hook('before_controller_action');
            // charge le controleur demande dans la requete
            if (count((array)$controller)) {
                $action = $request->ACT . 'Action';
                // appelle la fonction demandee dans la requete
                if (method_exists($controller, $action)) {
                    $result = $controller->$action($request);
                    if (isset($result['dont_getblock']) && $result['dont_getblock']) {
                        $noblock = true;
                    }
                } else {
                    if (!$erreur_404) {
                        header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found', true);
                        $erreur_404 = 1;
                    }
                    if (__DEBUGABLE__) {
                        $debug->err404_noSuchMethod(1);
                    }
                }
            } else {
                if (!$erreur_404) {
                    header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found', true);
                    $erreur_404 = 1;
                }
                if (__DEBUGABLE__) {
                    $debug->err404_cannotLoadCtrl(1);
                }
            }
        }
        $this->hook('before_block_rendering');
        // charge le bloc demande dans la requete
        if (!$erreur_404 && !$noblock) {
            $path = $request->CTRL . '/' . $request->ACT;
            // charge la surcharge si possible, meme dans le cas de l'adoption
            $gotblock = $controller->getBlock($path, $controller->data, $request);
            if (!$gotblock) {
                header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found', true);
                $erreur_404 = 1;
            }
        }
        // si erreur 404, on charge un autre controleur
        if ($erreur_404) {
            // headers deja envoyés
            // header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found', true);
            if (__DEBUGABLE__) {
                $debug->err404_noSuchBlock(null, 1);
            }
            $this->trigger404(1);
        }
        if (__DEBUGABLE__) {
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
    public function trigger404($header_already_sent = false)
    {
        if (!$header_already_sent) {
            if (__INVOCATION_METHOD__ == 'URL') {
                header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found', true);
            } else {
                header('CLI' . ' 404 Not Found', true);
            }
        }
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
        // on utilise die() et pas dontGetBlock() pour simplifier la surcharge de fonctions appelant trigger404()
        die();
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
        $fromcache = null;
        $all_overrides = array();
        if (Clementine::$_register['use_apc']) {
            $apc_key = 'clementine_core-overrides_by_weight';
            if ($only_weights) {
                $apc_key = 'clementine_core-overrides_by_weight_only';
            }
            if (isset($_SERVER['HTTP_PRAGMA']) && $_SERVER['HTTP_PRAGMA'] == 'no-cache') {
                apc_delete($apc_key);
            }
            $all_overrides = apc_fetch($apc_key, $fromcache);
        }
        if (!$fromcache || !isset($all_overrides[__SERVER_HTTP_HOST__])) {
            // liste les dossiers contenus dans ../app/share
            $modules_weights = array();
            $modules_types = array();
            $scopes = array(
                'share',
                'local'
            );
            $multisite = array(
                '.',
                __SERVER_HTTP_HOST__
            );
            foreach ($multisite as $site) {
                // gestion des alias
                if ($site != '.') {
                    $aliases = glob(realpath(dirname(__FILE__) . '/../../../' . $site) . '/alias-*');
                    if (isset($aliases[0])) {
                        $site = substr(basename($aliases[0]), 6);
                    }
                }
                foreach ($scopes as $scope) {
                    $appdir = realpath(dirname(__FILE__) . '/../../..');
                    $path = $appdir . '/' . $site . '/' . $scope . '/';
                    if (!$dh = @opendir($path)) {
                        continue;
                    }
                    while (false !== ($obj = readdir($dh))) {
                        if ($obj == '.' || $obj == '..' || (isset($obj[0]) && $obj[0] == '.')) {
                            continue;
                        }
                        if (is_dir($path . '/' . $obj)) {
                            if (array_key_exists($obj, $modules_weights)) {
                                $this->debug_overrides_module_twin($obj);
                                die();
                            }
                            $paths = glob($appdir . '/*/*/' . $obj);
                            if (count($paths) > 1) {
                                $this->debug_overrides_module_should_be_common($obj, $paths);
                                die();
                            }
                            $infos = $this->getModuleInfos($obj);
                            $modules_weights[$obj] = $infos['weight'];
                            $modules_types[$obj] = array(
                                'scope' => $scope,
                                'site' => $site,
                                'version' => $infos['version'],
                                'weight' => $infos['weight']
                            );
                        }
                    }
                    closedir($dh);
                }
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
            $all_overrides[__SERVER_HTTP_HOST__] = $overrides;
            if (Clementine::$_register['use_apc']) {
                apc_store($apc_key, $all_overrides);
            }
        }
        return $all_overrides[__SERVER_HTTP_HOST__];
    }

    /**
     * getRequest : renvoie l'objet $request correspondant à la requete HTTP
     *
     * @access public
     * @return void
     */
    public function getRequest()
    {
        return Clementine::$register['request'];
    }

    /**
     * populateRequest : decompose la requete en : langue, controleur, action
     *
     * @access private
     * @return void
     */
    private function populateRequest()
    {
        if (!(isset(Clementine::$register['request']) && isset(Clementine::$register['request']->CTRL))) {
            // décompose la requête en elements
            $request = new ClementineRequest();
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
                $request->LANG = $lang_candidat;
            } else {
                $request->LANG = __DEFAULT_LANG__;
                // si code langue demande invalide, redirige vers une 404 sauf pour la page d'accueil
                if ((count($lang_dispos) > 1) && strlen($lang_candidat)) {
                    header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found', true);
                    if (__DEBUGABLE__) {
                        $this->getHelper('debug')->err404_noLanguageCode();
                    }
                    $request->CTRL = 'errors';
                    $request->ACT = 'err404';
                }
            }
            // extrait le controleur et l'action, en tenant compte du decalage si le site est multilingue
            if (!isset($request->CTRL) && !isset($request->ACT)) {
                $decalage = (__LANG_DISPOS__ != __DEFAULT_LANG__) ? 1 : 0;
                $request->CTRL = strtolower(preg_replace('/[^a-zA-Z0-9_]/S', '_', strtolower(trim((isset($request_tmp[0 + $decalage]) && strlen(trim($request_tmp[0 + $decalage]))) ? trim($request_tmp[0 + $decalage]) : ''))));
                $request->ACT = strtolower(preg_replace('/[^a-zA-Z0-9_]/S', '_', trim((isset($request_tmp[1 + $decalage]) && strlen(trim($request_tmp[1 + $decalage]))) ? trim($request_tmp[1 + $decalage]) : '')));
                if (!strlen($request->CTRL)) {
                    $request->CTRL = 'index';
                }
                if (!strlen($request->ACT)) {
                    $request->ACT = 'index';
                }
            }
            $request->ARGS = $args;
            if (count($lang_dispos) > 1) {
                define('__BASE__', __BASE_URL__ . '/' . $request->LANG);
                define('__WWW__', __WWW_ROOT__ . '/' . $request->LANG);
                // URL equivalentes dans les autres langues
                $currequest = array(
                    'CTRL' => $request->CTRL,
                    'ACT' => $request->ACT,
                    'ARGS' => $request->ARGS
                );
                $curpage = implode('/', $currequest);
                $request->EQUIV = array();
                foreach ($lang_dispos as $lang) {
                    $request->EQUIV[$lang] = __WWW_ROOT__ . '/' . $lang . '/' . $curpage;
                }
            } else {
                define('__BASE__', __BASE_URL__);
                define('__WWW__', __WWW_ROOT__);
                // URL equivalente de la page courante
                $currequest = array(
                    'CTRL' => $request->CTRL,
                    'ACT' => $request->ACT,
                    'ARGS' => $request->ARGS
                );
                $curpage = implode('/', $currequest);
                $request->EQUIV = array();
                $request->EQUIV[$request->LANG] = __WWW_ROOT__ . '/' . $curpage;
            }
            // commodité : enregistre l'URL complète
            $request->FULLURL = $request->EQUIV[$request->LANG];
            // la requete est-elle une requete en AJAX ?
            $request->AJAX = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest') ? 1 : 0;
            // demande-t-on de vider le cache ?
            $request->NOCACHE = (isset($_SERVER['HTTP_PRAGMA']) && $_SERVER['HTTP_PRAGMA'] == 'no-cache') ? 1 : 0;
            // merge request parts
            $request->GET = Clementine::$register['request']->GET;
            $request->POST = Clementine::$register['request']->POST;
            $request->COOKIE = Clementine::$register['request']->COOKIE;
            $request->SESSION = Clementine::$register['request']->SESSION;
            $request->SERVER = Clementine::$register['request']->SERVER;
            $request->REQUEST = Clementine::$register['request']->REQUEST;
            $request->METHOD = Clementine::$register['request']->METHOD;
            Clementine::$register['request'] = $request;
        }
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
            if (__INVOCATION_METHOD__ == 'URL') {
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
                        Clementine::$register['request_uri'].= '/' . $action;
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
        require $file;
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
            if (__DEBUGABLE__ && Clementine::$config['clementine_debug']['writedown_evals']) {
                if (!is_dir(__FILES_ROOT__ . '/app/evals')) {
                    mkdir(__FILES_ROOT__ . '/app/evals');
                }
            }
            foreach ($overrides as $current => $override) {
                $current_class = $current . $elementname;
                $file_path = str_replace('/./', '/', __FILES_ROOT__ . '/app/' . $override['site'] . '/' . $override['scope'] . '/' . $current . '/' . $type_short . '/' . $current_class . '.php');
                if (file_exists($file_path)) {
                    if (isset($prev)) {
                        $parent_class = $prev . $elementname;
                        $code_to_eval = 'abstract class ' . $current_class . '_Parent extends ' . $parent_class . ' {}';
                        if (__DEBUGABLE__ && Clementine::$config['clementine_debug']['writedown_evals']) {
                            file_put_contents(__FILES_ROOT__ . '/app/evals/eval_' . $current_class . '.php', '<?php ' . PHP_EOL . $code_to_eval . PHP_EOL . '?>' . PHP_EOL);
                        }
                        eval($code_to_eval);
                    }
                    if (!class_exists($current_class . '_Parent', false)) {
                        $adopter = '__CLEMENTINE_CLASS_' . strtoupper($element) . '_' . strtoupper($type) . '_EXTENDS__';
                        if (defined($adopter)) {
                            if (!class_exists(constant($adopter), false)) {
                                // strips the "Controller/Model" part
                                $this->_factory(substr(constant($adopter), 0, -strlen($type)), $type, $testonly, $params);
                            }
                            $code_to_eval = 'abstract class ' . $current_class . '_Parent extends ' . constant($adopter) . ' {}';
                        } else {
                            if ($type == 'Controller') {
                                $code_to_eval = 'abstract class ' . $current_class . '_Parent extends Clementine {}';
                            } else {
                                // desactive les appels a getController depuis Model et Helper
                                $code_to_eval = 'abstract class ' . $current_class . '_Parent extends Clementine {
                                    public function getController($ctrl, $params = null) {
                                        $this->getHelper("debug")->getControllerFromModel();
                                    }
                                }';
                            }
                        }
                        if (__DEBUGABLE__ && Clementine::$config['clementine_debug']['writedown_evals']) {
                            file_put_contents(__FILES_ROOT__ . '/app/evals/eval_' . $current_class . '.php', '<?php ' . PHP_EOL . $code_to_eval . PHP_EOL . '?>' . PHP_EOL);
                        }
                        eval($code_to_eval);
                    }
                    if (__DEBUGABLE__ && !$testonly) {
                        $this->debug_factory_register_stack($type_short, $file_path);
                    }
                    $this->_require($file_path);
                    $prev = $current;
                }
            }
            if (isset($prev) && class_exists($prev . $elementname, false)) {
                $code_to_eval = 'class ' . $elementname . ' extends ' . $prev . $elementname . ' {}';
                if (__DEBUGABLE__ && Clementine::$config['clementine_debug']['writedown_evals']) {
                    file_put_contents(__FILES_ROOT__ . '/app/evals/eval_' . $elementname . '.php', '<?php ' . PHP_EOL . $code_to_eval . PHP_EOL . '?>' . PHP_EOL);
                }
                eval($code_to_eval);
            } else {
                if ($type == 'Controller') {
                    if (__DEBUGABLE__ && !$testonly && empty($params['no_mail_if_404'])) {
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
    public function getBlock($path, $data = null, $request = null, $ignores = null, $load_parent = false, $testonly = false, $never_display_errors = false)
    {
        $conf = Clementine::$config;
        ++Clementine::$_register['_forbid_getcontroller'];
        $path = strtolower($path);
        if (__DEBUGABLE__) {
            $deb = 0;
            if (!$deb) {
                $deb = microtime(true);
            }
            $this->getHelper('debug')->debugBlock_init($path);
        }
        $tmp_path_array = explode('/', $path);
        $path_array = array(
            (isset($tmp_path_array[0]) && $tmp_path_array[0]) ? $tmp_path_array[0] : 'index',
            (isset($tmp_path_array[1]) && $tmp_path_array[1]) ? $tmp_path_array[1] : 'index'
        );
        $niveau3 = null;
        if (isset($tmp_path_array[2])) {
            $niveau3 = $tmp_path_array[2];
            $path_array[3] = $niveau3;
        }
        // prend le bloc du theme le plus haut possible dans la surcharge
        $reverses = array_reverse($this->getOverrides());
        if ($load_parent && isset(Clementine::$_register['_parent_loaded_blocks_files'][$path])) {
            $nb_shift = count(Clementine::$_register['_parent_loaded_blocks_files'][$path]);
            for (; $nb_shift; --$nb_shift) {
                array_shift($reverses);
            }
        }
        $vue_affichee = 0;
        $vue_recursive = 0;
        static $recursion_level = array();
        $module = '';
        $reverse_keys = array_keys($reverses);
        $pos = array_search($ignores, $reverse_keys);
        foreach ($reverses as $module => $reverse) {
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
            $file = str_replace('/./', '/', __FILES_ROOT__ . '/app/' . $reverse['site'] . '/' . $reverse['scope'] . '/' . $module . '/view/' . $path_array[0] . '/' . $path_array[1]);
            if ($niveau3) {
                $file.= '/' . $niveau3;
            }
            $file.= '.php';
            $block_exists = isset(Clementine::$_register['all_blocks'][$file]);
            if ($block_exists) {
                $load_block = 0;
                if (!isset(Clementine::$_register['_parent_loaded_blocks_files'][$path])) {
                    $load_block = 1;
                } else {
                    // si le block n'est pas deja charge
                    if (!isset($recursion_level[$path])) {
                        $recursion_level[$path] = 1;
                    }
                    if (!in_array($file, Clementine::$_register['_parent_loaded_blocks_files'][$path])) {
                        $load_block = 1;
                        $recursion_level[$path] = 1;
                    } else {
                        // si load parent, ce n'est pas un appel recursif
                        if ($load_parent) {
                            continue;
                        }
                        $load_block = 1;
                        $vue_recursive = 1;
                        ++$recursion_level[$path];
                        if ($recursion_level[$path] > $conf['module_core']['getblock_max_recursion']) {
                            // warning : recursive block call
                            if (__DEBUGABLE__) {
                                $this->getHelper('debug')->debugBlock_warningRecursiveCall($path, $recursion_level[$path]);
                            }
                            break;
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
                        $this->getHelper('debug')->debugBlock_registerStack($reverse, $module, $path, $file, array(
                            'ignores' => $ignores,
                            'is_ignored' => $a_ignorer
                        ), $load_parent);
                    }
                    // semaphore pour eviter les appels a getController depuis un block
                    if (!$testonly && !$a_ignorer) {
                        if (__DEBUGABLE__ && $conf['clementine_debug']['block_filename']) {
                            $depth = count(Clementine::$_register['_parent_loaded_blocks']);
                            echo "\r\n<!-- (depth " . $depth . ') begins ' . $file . " -->\r\n";
                        }
                        if (!$request) {
                            $request = $this->getRequest();
                        }
                        if ($never_display_errors) {
                            $old_display_errors = $conf['clementine_debug']['display_errors'];
                            $conf['clementine_debug']['display_errors'] = 0;
                        }
                        $this->_require($file, $data, $request);
                        if ($never_display_errors) {
                            $conf['clementine_debug']['display_errors'] = $old_display_errors;
                        }
                        if (__DEBUGABLE__ && $conf['clementine_debug']['block_filename']) {
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
                }
            }
        }
        $found = 1;
        if (!$vue_affichee && !$vue_recursive) {
            $found = 0;
        } else if ($vue_affichee && !$vue_recursive) {
            if (__DEBUGABLE__) {
                $duree = microtime(true) - $deb;
                $this->getHelper('debug')->debugBlock_dumpStack($reverse, $module, $path_array, $duree);
            }
        }
        if (!$found) {
            $adopter = '__CLEMENTINE_CLASS_' . strtoupper($path_array[0]) . '_VIEW_EXTENDS__';
            if (defined($adopter)) {
                // le block n'a pas de parent mais il est adopte
                $tuteur_path = substr(strtolower(constant($adopter)), 0, -4) . '/' . $path_array[1]; // strips de "view" part
                if ($niveau3) {
                    $tuteur_path.= '/' . $niveau3;
                }
                $found = $this->getBlock($tuteur_path, $data, $request, $ignores, $load_parent, $testonly, $never_display_errors);
            }
        }
        if (__DEBUGABLE__ && !$found && !$testonly && !$load_parent) {
            $this->getHelper('debug')->err404_noSuchBlock($path);
        }
        --Clementine::$_register['_forbid_getcontroller'];
        if ($found) {
            Clementine::$_register['_canGetBlock'][$path] = 1;
        }
        return $found;
    }

    /**
     * getParentBlock : charge le block parent du block depuis lequel est appelee cette fonction
     *
     * @param mixed $data
     * @access public
     * @return void
     */
    public function getParentBlock($data = null, $request = null, $ignores = null, $never_display_errors = false)
    {
        $parent_blocks = Clementine::$_register['_parent_loaded_blocks'];
        $last_block = array_pop($parent_blocks);
        if ($last_block) {
            return $this->getBlock($last_block, $data, $request, $ignores, true, false, $never_display_errors);
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
    public function getBlockHtml($path, $data = null, $request = null, $ignores = null, $load_parent = false, $never_display_errors = false)
    {
        ob_start();
        $this->getBlock($path, $data, $request, $ignores, $load_parent, false, $never_display_errors);
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
        return array(
            'dont_getblock' => true
        );
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
    public function canGetBlock($path, $data = null, $request = null, $ignores = null, $load_parent = false, $never_display_errors = false)
    {
        if (isset(Clementine::$_register['_canGetBlock'][$path])) {
            return 1;
        }
        return $this->getBlock($path, $data, $request, $ignores, $load_parent, true, $never_display_errors);
    }

    /**
     * getCurrentModule : renvoie le nom du module dans lequel est fait l'appel a cette fonction
     *
     * @access public
     * @return void
     */
    public function getCurrentModule()
    {
        $module = '';
        $class = get_class($this);
        $types = array(
            'Controller',
            'Model',
            'Helper'
        );
        foreach ($types as $type) {
            if (strpos($class, $type) !== false) {
                $module = strtolower(substr($class, 0, -strlen($type)));
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
        // si appel CLI
        $usage = 'Usage : /usr/bin/php index.php "http://www.site.com" "ctrl[/action]" "[id=1&query=string]"';
        if (!isset($_SERVER['HTTP_HOST']) && !isset($_SERVER['SERVER_NAME'])) {
            global $argv;
            if (isset($argv[1]) && (stripos($argv[1], 'http://') === 0 || stripos($argv[1], 'https://') === 0)) {
                define('__INVOCATION_METHOD__', 'CLI');
                $insecure_server_http_host = preg_replace('@^https?://@i', '', $argv[1]);
                $insecure_server_http_host = preg_replace('@/.*@', '', $insecure_server_http_host);
            } else {
                echo $usage;
                die();
            }
        } else {
            define('__INVOCATION_METHOD__', 'URL');
            if (isset($_SERVER['HTTP_HOST'])) {
                $insecure_server_http_host = $_SERVER['HTTP_HOST'];
            } else {
                // fallback to SERVER_NAME if HTTP_HOST is not set
                $insecure_server_http_host = $_SERVER['SERVER_NAME'];
            }
        }
        // XSS protection
        $server_http_host = preg_replace('@[^a-z0-9-\.]@i', '', $insecure_server_http_host);
        define('__SERVER_HTTP_HOST__', $server_http_host);
        // constante indentifiant le site courant
        $aliases = glob(realpath(dirname(__FILE__) . '/../../../' . __SERVER_HTTP_HOST__) . '/alias-*');
        if (isset($aliases[0])) {
            $current_site = substr(basename($aliases[0]), 6);
            define('__CLEMENTINE_HOST__', $current_site);
        } else {
            define('__CLEMENTINE_HOST__', __SERVER_HTTP_HOST__);
        }
        // charge la config
        $config = $this->_get_config();
        // qq constantes
        if (!defined('DEBUG_BACKTRACE_IGNORE_ARGS')) {
            define('DEBUG_BACKTRACE_IGNORE_ARGS', 0);
        }
        if (!defined('DEBUG_BACKTRACE_PROVIDE_OBJECT')) {
            define('DEBUG_BACKTRACE_PROVIDE_OBJECT', 0);
        }
        // definit les constantes necessaires au fonctionnement de l'adoption
        $adopters = array(
            'model',
            'view',
            'controller',
            'helper'
        );
        foreach ($adopters as $adopter) {
            if (isset($config['clementine_inherit_' . $adopter]) && is_array($config['clementine_inherit_' . $adopter])) {
                foreach ($config['clementine_inherit_' . $adopter] as $classname => $parentclassname) {
                    define('__CLEMENTINE_CLASS_' . strtoupper($classname) . '_' . strtoupper($adopter) . '_EXTENDS__', ucfirst($parentclassname) . ucfirst($adopter));
                }
            }
        }
        if (isset($config['clementine_inherit_config']) && is_array($config['clementine_inherit_config'])) {
            foreach ($config['clementine_inherit_config'] as $module => $parentmodule) {
                define('__clementine_config_' . strtoupper($module) . '_extends__', $parentmodule . '_config');
                if (!isset($config['module_' . $module])) {
                    $config['module_' . $module] = array();
                }
                $config['module_' . $module] = array_merge($config['module_' . $parentmodule], $config['module_' . $module]);
            }
        }
        // adoption sur model, view, controller, helper et config si demande dans clementine_inherit et pas déjà défini dans un clementine_inherit_*
        if (isset($config['clementine_inherit']) && is_array($config['clementine_inherit'])) {
            foreach ($config['clementine_inherit'] as $classname => $parentclassname) {
                foreach ($adopters as $adopter) {
                    if (!defined('__CLEMENTINE_CLASS_' . strtoupper($classname) . '_' . strtoupper($adopter) . '_EXTENDS__')) {
                        define('__CLEMENTINE_CLASS_' . strtoupper($classname) . '_' . strtoupper($adopter) . '_EXTENDS__', ucfirst($parentclassname) . ucfirst($adopter));
                    }
                }
                if (!isset($config['clementine_inherit_config'][$classname])) {
                    define('__clementine_config_' . strtoupper($classname) . '_extends__', $parentclassname . '_config');
                    if (!isset($config['module_' . $classname])) {
                        $config['module_' . $classname] = array();
                    }
                    $config['module_' . $classname] = array_merge($config['module_' . $parentclassname], $config['module_' . $classname]);
                }
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
        if (isset($config['clementine_global']['base_url'])) {
            define('__BASE_URL__', $config['clementine_global']['base_url']);
        } else {
            // selon appel HTTP ou CLI
            if (__INVOCATION_METHOD__ == 'URL') {
                $base_url = substr(__FILE__, strlen(preg_replace('/\/$/S', '', $_SERVER['DOCUMENT_ROOT'])));
                $base_url = substr($base_url, 0, -strlen('/app/share/core/lib/Clementine.php'));
                if (__OS__ == 'windows') {
                    $base_url = str_replace('\\', '/', $base_url);
                }
                define('__BASE_URL__', $base_url);
                $files_root = preg_replace('@//*@', '/', $_SERVER['DOCUMENT_ROOT'] . __BASE_URL__);
                if ($files_root != '/') {
                    $files_root = preg_replace('@/$@', '', $files_root);
                }
                define('__FILES_ROOT__', $files_root);
            } else {
                $base_url = preg_replace('@^https?://[^/]+@i', '', $argv[1]);
                define('__BASE_URL__', $base_url);
                define('__FILES_ROOT__', realpath(dirname(__FILE__) . '/../../../../'));
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
        define('__WWW_ROOT__', $protocol . __SERVER_HTTP_HOST__ . __BASE_URL__);
        $overrides = $this->getOverrides();
        foreach ($overrides as $module => $override) {
            $www_root = __WWW_ROOT__ . '/app/' . $override['site'] . '/' . $override['scope'] . '/' . $module;
            $files_root = __FILES_ROOT__ . '/app/' . $override['site'] . '/' . $override['scope'] . '/' . $module;
            define('__WWW_ROOT_' . strtoupper($module) . '__', str_replace('/./', '/', $www_root));
            define('__FILES_ROOT_' . strtoupper($module) . '__', str_replace('/./', '/', $files_root));
        }
        if (isset($config['clementine_debug']) &&
            !empty($config['clementine_debug']['enabled']) &&
            isset($config['clementine_debug']['allowed_ip']) &&
            ((!$config['clementine_debug']['allowed_ip']) || (
                (__INVOCATION_METHOD__ == 'CLI' && in_array('127.0.0.1', explode(',', $config['clementine_debug']['allowed_ip']))) || 
                (__INVOCATION_METHOD__ == 'URL' && in_array($_SERVER['REMOTE_ADDR'], explode(',', $config['clementine_debug']['allowed_ip'])))
            ))
        ) {
            define('__DEBUGABLE__', '1');
        } else {
            define('__DEBUGABLE__', '0');
        }
        // modifications du comportement de PHP selon la configuration choisie
        ini_set('display_errors', 0);
        ini_set('log_errors', 0);
        // error_reporting(0);
        $error_reporting = eval('return (' . Clementine::$config['clementine_debug']['error_reporting'] . ');');
        if (Clementine::$config['clementine_debug']['error_log']) {
            ini_set('error_log', Clementine::$config['clementine_debug']['error_log']);
        }
        set_error_handler(array(
            'Clementine',
            'clementine_error_handler'
        ), $error_reporting);
        register_shutdown_function(array(
            'Clementine',
            'clementine_shutdown_handler'
        ));
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
        ini_set('session.gc_divisor', Clementine::$config['clementine_global']['gc_divisor']);
        // selon appel CLI, pas de garbage collection
        if (__INVOCATION_METHOD__ == 'CLI') {
            ini_set('session.gc_probability', 0);
        } else {
            ini_set('session.gc_probability', Clementine::$config['clementine_global']['gc_probability']);
        }
        ini_set('session.gc_maxlifetime', Clementine::$config['clementine_global']['gc_maxlifetime']);
        // timezone
        ini_set('date.timezone', Clementine::$config['clementine_global']['date_timezone']);
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
            $message = '<table width="100%" style="font-size: 12px; line-height: 1.4em; text-align: left; "><thead><tr><th>poids</th><th>module</th><th>version</th><th>type</th></tr></thead><tbody>';
            //$reverses = array_reverse($overrides);
            foreach ($overrides as $module => $reverse) {
                $infos = $this->getModuleInfos($module);
                $message.= "<tr><td>" . $infos['weight'] . "</td><td>$module</td><td>" . $infos['version'] . "</td><td>{$overrides[$module]['scope']}</td></tr>";
            }
            $message.= '</tbody></table>';
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
            $site_config_filepath = realpath(dirname(__FILE__) . '/../../../local/site/etc/config.ini');
            if (!is_file($site_config_filepath)) {
                echo "<br />\n" . '<strong>Clementine fatal error</strong>: fichier de configuration manquant : /app/local/site/etc/config.ini';
                die();
            } else {
                // php < 5.3 compatibility
                if (version_compare(PHP_VERSION, '5.3.0') >= 0) {
                    $site_config = parse_ini_file($site_config_filepath, true, INI_SCANNER_RAW);
                } else {
                    $site_config = parse_ini_file($site_config_filepath, true);
                }
                // désactive use_apc si le debug est activé AVEC affichage des erreurs est activé (selon le fichier app/local/site/etc/config.ini uniquement car il est trop tot pour utiliser l'héritage ici)
                if (isset($site_config['clementine_debug']) &&
                    !empty($site_config['clementine_debug']['display_errors']) &&
                    !empty($site_config['clementine_debug']['enabled']) &&
                    isset($site_config['clementine_debug']['allowed_ip']) &&
                    ((!$site_config['clementine_debug']['allowed_ip']) || (
                        (__INVOCATION_METHOD__ == 'CLI' && in_array('127.0.0.1', explode(',', $site_config['clementine_debug']['allowed_ip']))) || 
                        (__INVOCATION_METHOD__ == 'URL' && in_array($_SERVER['REMOTE_ADDR'], explode(',', $site_config['clementine_debug']['allowed_ip'])))
                    ))
                ) {
                    Clementine::$_register['use_apc'] = false;
                }
            }
            $overrides = $this->getOverrides();
            $app_path = dirname(__FILE__) . '/../../../';
            $config = array();
            foreach ($overrides as $module => $override) {
                $filepath = $app_path . $override['site'] . '/' . $override['scope'] . '/' . $module . '/etc/config.ini';
                if (is_file($filepath)) {
                    // php < 5.3 compatibility
                    if (version_compare(PHP_VERSION, '5.3.0') >= 0) {
                        $tmp = parse_ini_file($filepath, true, INI_SCANNER_RAW);
                    } else {
                        $tmp = parse_ini_file($filepath, true);
                    }
                    if (is_array($tmp)) {
                        // surcharge : ecrase avec la derniere valeur
                        foreach ($tmp as $section_key => $section_values) {
                            if (!isset($config[$section_key])) {
                                $config[$section_key] = array();
                            }
                            foreach ($section_values as $key => $val) {
                                $config[$section_key][$key] = $val;
                            }
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
        if (__DEBUGABLE__ && !$request->AJAX && !defined('__NO_DEBUG_DIV__')) {
            $types = array(
                'hook' => 'Hooks appelés sur cette page',
                'ctrl' => 'Contrôleurs de cette page',
                'model' => 'Modèles chargés sur cette page',
                'block' => 'Blocks chargés sur cette page',
                'helper' => 'Helpers chargés sur cette page',
                'heritage' => '<span style="color: red">Sanity-check sur les héritages : pour éviter les conflits entre surcharges</span>',
                'overrides' => 'Modules chargés (et poids)',
                'sql' => 'Log des requêtes SQL exécutées',
            );
            $debug = <<<HTML
        <div id="Clementine_debug_div" style="background: rgba(128,128,128,0.1); box-shadow: 3px 3px 3px rgba(128,128,128,0.5); position: absolute; max-width: 100%; right: 0; z-index: 9999; font-family: courier; font-size: 14px; padding: 0.5em; border-radius: 5px; overflow: hidden; " >
            <div style="text-align: right; ">
            <button
                style="cursor: pointer; box-shadow: 3px 3px 3px rgba(128,128,128,0.5); "
                onclick='document.getElementById("Clementine_debug_ol").style.display = (parseInt(document.cookie.substring(parseInt("Clementine_debug_div_hide".length) + document.cookie.indexOf("Clementine_debug_div_hide") + 1, parseInt("Clementine_debug_div_hide".length) + document.cookie.indexOf("Clementine_debug_div_hide") + 2)) ? "block" : "none"); document.cookie="Clementine_debug_div_hide=" + escape(parseInt(document.cookie.substring(parseInt("Clementine_debug_div_hide".length) + document.cookie.indexOf("Clementine_debug_div_hide") + 1, parseInt("Clementine_debug_div_hide".length) + document.cookie.indexOf("Clementine_debug_div_hide") + 2)) ? "0" : "1") + "; path=
HTML;
            $debug.= __BASE_URL__ . "/";
            $debug.= <<<HTML
            "; return false; '>DEBUG</button>
            </div>
            <ol id="Clementine_debug_ol" style="text-align: left; padding: 0.5em 0; margin: 0; list-style-position: inside; 
HTML;
            $debug.= (isset($_COOKIE['Clementine_debug_div_hide']) && ($_COOKIE['Clementine_debug_div_hide'])) ? 'display: none; ' : 'display: block; ';
            $debug.= '">';
            // affiche les messages par type
            foreach ($types as $type => $libelle) {
                if (isset(Clementine::$clementine_debug[$type]) && count(Clementine::$clementine_debug[$type])) {
                    $debug.= <<<HTML
                    <li style="overflow-x: auto; margin: 3px; border: solid #AAA 3px; padding: 3px; border-radius: 5px; background-color: #DDD; font-size: 12px; line-height: 1.4em; z-index: 9998">
                        <strong>
HTML;
                    $debug.= $libelle;
                    $debug.= <<<HTML
                        </strong>
                        <table style="width: 100%; border-radius: 5px; "
HTML;
                    $debug.= ($type == 'sql') ? ' class="clementine_debug-dataTables"' : '';
                    $debug.= '>';
                    if (isset(Clementine::$clementine_debug[$type])) {
                        if (isset(Clementine::$clementine_debug[$type][0]) && is_array(Clementine::$clementine_debug[$type][0])) {
                            $titles = array_keys(Clementine::$clementine_debug[$type][0]);
                            $debug.= <<<HTML
                            <thead>
                                <tr>
HTML;
                            foreach ($titles as $title) {
                                $debug.= '<th>' . $title . '</th>';
                            }
                            $debug.= <<<HTML
                                </tr>
                            </thead>
HTML;
                        }
                        if ($type == 'block') {
                            Clementine::$clementine_debug[$type] = array_reverse(Clementine::$clementine_debug[$type]);
                        }
                        // debug sql : cumul du temps passe en *_query
                        $duree_totale_sql = 0;
                        foreach (Clementine::$clementine_debug[$type] as $msg) {
                            // debug sql : cumul du temps passe en *_query et conversion de microsecondes a millisecondes
                            if ($type == 'sql') {
                                $msg['duree']*= 1000;
                                $duree_totale_sql+= $msg['duree'];
                                $msg['duree'] = number_format($msg['duree'], 3, ',', ' ') . '&nbsp;ms';
                            }
                            $debug.= <<<HTML
                            <tr style="background-color: #DDD; border-top: solid #CCC 3px; "><td style="padding: 5px; ">
HTML;
                            if (is_array($msg)) {
                                $debug.= implode('</td><td style="padding: 5px; vertical-align: top; ">', $msg);
                            } else {
                                $debug.= $msg;
                            }
                            $debug.= <<<HTML
</td></tr>
HTML;
                        }
                        $debug.= <<<HTML
                        </table>
HTML;
                        // debug sql : cumul du temps passe en *_query
                        if ($type == 'sql') {
                            $debug.= '<tr style="background-color: #DDD; border: solid #CCC 3px; "><td colspan="3" style="padding: 5px; ">';
                            $debug.= '<strong>Durée totale passé en query (hors fetch) : </strong>';
                            $debug.= number_format($duree_totale_sql, 3, ',', ' ');
                            $debug.= ' ms </td></tr>';
                        }
                    }
                    $debug.= <<<HTML
                    </li>
HTML;
                }
            }
            $debugger = $this->getHelper('debug');
            $debugger->memoryUsage();
            $debugger->generationTime(Clementine::$_register['mvc_generation_begin'], microtime(true));
            // debug non classe dans $types
            foreach (Clementine::$clementine_debug as $type => $msg) {
                if (!in_array($type, array_keys($types), true)) {
                    $debug.= <<<HTML
                    <li style="overflow-x: auto; margin: 3px; border: solid #AAA 3px; padding: 3px; border-radius: 5px; background-color: #DDD; font-size: 12px; line-height: 1.4em; z-index: 9998">
HTML;
                    if (is_array($msg)) {
                        foreach ($msg as $message) {
                            $debug.= $message . '<br />';
                        }
                    } else {
                        $debug.= $msg;
                    }
                    $debug.= <<<HTML
                    </li>
HTML;
                }
            }
            $debug.= <<<HTML
            </ol>
        </div>
HTML;
            if ($request->INVOCATION_METHOD == 'CLI') {
                $nbsp = ' ';
                $nbsp = iconv(mb_internal_encoding(), __PHP_ENCODING__, $nbsp);
                // contourne le nbsp pour en faire un espace secable
                $debug = html_entity_decode(str_replace('&nbsp;', $nbsp, $debug), ENT_QUOTES, __PHP_ENCODING__);
                Clementine::log(preg_replace('@<[^>]*?>@', '', str_replace('<br />', PHP_EOL, $debug)), false);
            } else {
                echo $debug;
            }
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
            $elements_stack = array(
                get_class($tmp)
            );
            for (; $parent = get_parent_class($tmp); $tmp = $parent) {
                if (substr($parent, -(strlen($type . '_Parent'))) == $type . '_Parent') {
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
                        $module_name = substr($step, 0, -strlen($final_name));
                        foreach ($differences as $diff) {
                            if (strpos($diff, $module_name) !== 0) {
                                if ($type == 'Model' || ((substr($diff, -strlen('Action')) !== 'Action') && ($diff !== '__construct'))) {
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
        // __DEBUGABLE__ n'est pas encore defini, on ne peut pas l'utiliser
        $errmsg = "<br />\n" . '<strong>Clementine fatal error</strong>: directories <em>app/share/' . $module . '</em> and <em>app/local/' . $module . '</em> can not coexist';
        error_log($errmsg);
        echo $errmsg;
    }

    public function debug_overrides_module_should_be_common($module, $paths)
    {
        // __DEBUGABLE__ n'est pas encore defini, on ne peut pas l'utiliser
        $errmsg = "<br />\n" . '<strong>Clementine fatal error</strong>: directory <em>' . $module . '</em> was found in multiple paths (<em>' . implode('</em>, <em>', $paths) . '</em>). It should reside in <em>app/share</em> or <em>app/local</em>';
        error_log($errmsg);
        echo $errmsg;
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
        $scopes = array(
            'share',
            'local'
        );
        $multisite = array(
            '.',
            __SERVER_HTTP_HOST__
        );
        foreach ($multisite as $site) {
            // gestion des alias
            if ($site != '.') {
                $aliases = glob(realpath(dirname(__FILE__) . '/../../../' . $site) . '/alias-*');
                if (isset($aliases[0])) {
                    $site = substr(basename($aliases[0]), 6);
                }
            }
            foreach ($scopes as $scope) {
                $filepath = realpath(dirname(__FILE__) . '/../../../' . $site . '/' . $scope . '/' . $module . '/etc/module.ini');
                if (is_file($filepath)) {
                    $config = array();
                    // php < 5.3 compatibility
                    if (version_compare(PHP_VERSION, '5.3.0') >= 0) {
                        $infos = parse_ini_file($filepath, true, INI_SCANNER_RAW);
                    } else {
                        $infos = parse_ini_file($filepath, true);
                    }
                    if (isset($infos['weight'])) {
                        $infos['weight'] = (float)$infos['weight'];
                        return $infos;
                    }
                }
            }
        }
        return false;
    }

    /**
     * clementine_error_handler : handle php errors (display, send by mail...)
     *
     * @param mixed $errno
     * @param mixed $errstr
     * @param mixed $errfile
     * @param mixed $errline
     * @access public
     * @return void
     */
    public static function clementine_error_handler($errno, $errstr, $errfile, $errline)
    {
        if (0 == error_reporting()) {
            // error reporting is currently turned off or suppressed with @
            return false;
        }
        // TODO: rendre surchargeable l'affichage
        ++Clementine::$_register['_handled_errors'];
        $fatal = 0;
        if (is_array($errstr)) {
            $error_content = '';
            $error_content_log = '';
            $nb_ligne = 0;
            foreach ($errstr as $key => $val) {
                if ($nb_ligne) {
                    $error_content.= '<br />' . PHP_EOL;
                    $error_content_log.= PHP_EOL;
                }
                if ($val == 'html') {
                    $error_content.= $key;
                    $error_content_log.= strip_tags(str_replace('<br />', PHP_EOL, html_entity_decode($key, ENT_QUOTES, __PHP_ENCODING__)));
                } else {
                    $error_content.= htmlentities($val, ENT_QUOTES, __PHP_ENCODING__, false);
                    $error_content_log.= strip_tags(str_replace('<br />', PHP_EOL, html_entity_decode($val, ENT_QUOTES, __PHP_ENCODING__)));
                }
                ++$nb_ligne;
            }
        } else {
            $error_content = htmlentities($errstr, ENT_QUOTES, __PHP_ENCODING__, false);
            $error_content_log = strip_tags(str_replace('<br />', PHP_EOL, html_entity_decode($errstr, ENT_QUOTES, __PHP_ENCODING__)));
        }
        if ($errfile) {
            $error_content.= " <em>in</em> <code>" . htmlentities($errfile, ENT_QUOTES, __PHP_ENCODING__, false) . ":$errline</code>";
            $error_content_log.= " in $errfile:$errline";
        }
        $error_content.= PHP_EOL;
        $backtrace_flags = DEBUG_BACKTRACE_IGNORE_ARGS;
        $nomail = 0;
        $color = false;
        switch ($errno) {
        case E_ERROR:
            $error_type = 'Error';
            $backtrace_flags = 0;
            $color = "\033[31m";
            $fatal = 1;
            break;
        case E_WARNING:
            $error_type = 'Warning';
            $color = "\033[33m";
            break;
        case E_PARSE:
            $error_type = 'Parse error';
            $backtrace_flags = 0;
            $color = "\033[31m";
            $fatal = 1;
            break;
        case E_NOTICE:
            $error_type = 'Notice';
            break;
        case E_CORE_ERROR:
            $error_type = 'Core error';
            $color = "\033[31m";
            $fatal = 1;
            break;
        case E_CORE_WARNING:
            $error_type = 'Core warning';
            $color = "\033[33m";
            break;
        case E_COMPILE_ERROR:
            $error_type = 'Compile error';
            $color = "\033[31m";
            $fatal = 1;
            break;
        case E_COMPILE_WARNING:
            $error_type = 'Compile warning';
            $color = "\033[33m";
            break;
        case E_USER_ERROR:
            $error_type = 'User error';
            $color = "\033[31m";
            $fatal = 1;
            break;
        case 'E_USER_WARNING_NOMAIL':
        case E_USER_WARNING:
            $error_type = 'User warning';
            if ($errno == 'E_USER_WARNING_NOMAIL') {
                $nomail = 1;
            }
            $color = "\033[33m";
            break;
        case E_USER_NOTICE:
            $error_type = 'User notice';
            break;
        case E_STRICT:
            $error_type = 'Strict';
            break;
        case E_RECOVERABLE_ERROR:
            $error_type = 'Recoverable error';
            break;
        case E_DEPRECATED:
            $error_type = 'Deprecated';
            break;
        case E_USER_DEPRECATED:
            $error_type = 'User deprecated';
            break;
        case E_ALL:
            $error_type = 'Unspecified error';
            $color = "\033[31m";
            $fatal = 1;
            break;
        default:
            $error_type = 'Unknown error';
            $color = "\033[31m";
            $fatal = 1;
            break;
        }
        // TODO: les onclick et les styles inline c'est pas terrible mais c'est autonome... trouver une meilleure solution
        $prestyle = 'background: #EEE; border: 2px solid #333; border-radius: 5px; padding: 1em; margin: 1em; text-align: left; font-family: Courier New; font-size: 13px; line-height: 1.4em; ';
        $strongstyle = 'cursor: pointer; background: #999999; border: 1px solid #555555; border-radius: 1em 1em 1em 1em; box-shadow: 1px 3px 4px rgba(64, 64, 64, 0.3); color: #FFFFFF; font-size: 10px; font-weight: bold; padding: 0.3em 1em; text-shadow: 0 1px 1px #000000; display: inline-block; margin: 0 0 5px 5px; position: relative; z-index: 999; ';
        $togglepre = 'onclick="var elt = this.nextSibling; var current_display = (elt.currentStyle ? elt.currentStyle[\'display\'] : document.defaultView.getComputedStyle(elt,null).getPropertyValue(\'display\')); if (typeof(this.previous_display) == \'undefined\') { this.previous_display = (current_display != \'none\' ? current_display : \'block\') }; elt.style.display = (current_display != \'none\' ? \'none\' : this.previous_display); return false; "';
        $display_error = PHP_EOL . '<br />' . PHP_EOL . '<strong style="' . $strongstyle . '; background-color: #666666; margin: 0 5px 5px 0; " ' . $togglepre . '>#' . Clementine::$_register['_handled_errors'] . ' ' . $error_type . PHP_EOL . '</strong><div style="position: relative; z-index: 999; display: inline; background-color: #FFF; color: #000; font-family: serif; ">';
        $display_error_log = PHP_EOL . '#' . Clementine::$_register['_handled_errors'] . ' ' . $error_type . ': ';
        $display_error.= PHP_EOL . $error_content;
        $display_error_log.= $error_content_log;
        if ($errfile && $errline) {
            $highlighted_content = highlight_string(file_get_contents($errfile), true);
            $highlighted_content = preg_replace('@<br /></span>@', '</span><br />' . PHP_EOL, $highlighted_content);
            $content = explode('<br />', $highlighted_content);
            $from = max(0, $errline - 7);
            $content = array_slice($content, $from, 10);
            $prestyle = 'background: #DDD; border: 2px solid #333; border-radius: 5px; padding: 1em; margin: 1em; text-align: left; font-family: Courier New; font-size: 13px; line-height: 1.4em; overflow: auto; white-space: nowrap; ';
            $display_error.= '<strong style="' . $strongstyle . '" ' . $togglepre . '>' . PHP_EOL . 'Code dump' . PHP_EOL . '</strong>';
            $display_error.= '<pre class="clementine_error_handler_error" style="' . $prestyle . '">';
            $nb = ($from + 1);
            foreach ($content as $line) {
                if ($nb == $errline) {
                    $display_error.= '<strong>';
                }
                $display_error.= str_pad($nb, 2, '0', STR_PAD_LEFT) . '    ' . '<span>' . $line . '</span>';
                if ($nb == $errline) {
                    $display_error.= '</strong>';
                }
                $display_error.= '<br />' . PHP_EOL;
                ++$nb;
            }
            $display_error.= '</pre>';
        }
        $debug_message = $display_error;
        $request_dump = Clementine::dump(Clementine::$register['request'], true);
        $server_dump = Clementine::dump($_SERVER, true);
        $debug_backtrace = Clementine::dump(debug_backtrace($backtrace_flags) , true);
        $debug_message = $display_error;
        $debug_message.= PHP_EOL . '<strong style="' . $strongstyle . '" ' . $togglepre . '>' . PHP_EOL . 'Request dump' . PHP_EOL . '</strong>';
        $debug_message.= '<pre class="clementine_error_handler_error" style="' . $prestyle . '">' . $request_dump . '</pre>';
        $debug_message.= PHP_EOL . '<strong style="' . $strongstyle . '" ' . $togglepre . '>' . PHP_EOL . 'Server dump' . PHP_EOL . '</strong>';
        $debug_message.= '<pre class="clementine_error_handler_error" style="' . $prestyle . '">' . $server_dump . '</pre>';
        $debug_message.= PHP_EOL . '<strong style="' . $strongstyle . '" ' . $togglepre . '>' . PHP_EOL . 'Debug_backtrace' . PHP_EOL . '</strong>';
        $debug_message.= '<pre class="clementine_error_handler_error" style="' . $prestyle . '">' . $debug_backtrace . '</pre>';
        $debug_message.= '</div>';
        if (__DEBUGABLE__ && Clementine::$config['clementine_debug']['display_errors']) {
            // si appel non CLI
            if (__INVOCATION_METHOD__ == 'URL') {
                echo '<style type="text/css">' . PHP_EOL . '.clementine_error_handler_error {' . PHP_EOL . 'display: none;' . PHP_EOL . '}' . PHP_EOL . '</style>' . PHP_EOL . $debug_message . PHP_EOL . PHP_EOL;
            } else {
                echo html_entity_decode(strip_tags(preg_replace('@<br />' . PHP_EOL . '?@', PHP_EOL, $debug_message)) , ENT_QUOTES, __PHP_ENCODING__);
            }
        }
        if (!$nomail &&
            Clementine::$config['clementine_debug']['send_errors_by_email'] &&
            Clementine::$config['clementine_debug']['send_errors_by_email_max'] &&
            Clementine::$_register['_handled_errors'] <= Clementine::$config['clementine_debug']['send_errors_by_email_max']) {
            // BUILD MESSAGE BODY
            // MIME BOUNDARY
            $mime_boundary = "---- " . md5(time());
            // MAIL HEADERS
            $headers = 'From: "' . Clementine::$config['clementine_global']['email_exp'] . "\n";
            $headers.= "MIME-Version: 1.0\n";
            $headers.= "Content-Type: multipart/alternative; boundary=\"$mime_boundary\"\n";
            // TEXT EMAIL PART
            $message = "\n--$mime_boundary\n";
            $message.= "Content-Type: text/plain; charset=" . __PHP_ENCODING__ . "\n";
            $message.= "Content-Transfer-Encoding: 8bit\n\n";
            $message.= html_entity_decode(strip_tags($debug_message) , ENT_QUOTES, __PHP_ENCODING__);
            // HTML EMAIL PART
            $message.= "\n--$mime_boundary\n";
            $message.= "Content-Type: text/html; charset=" . __PHP_ENCODING__ . "\n";
            $message.= "Content-Transfer-Encoding: 8bit\n\n";
            $message.= "<html>\n";
            $message.= "<body>\n";
            $message.= $debug_message;
            $message.= "</body>\n";
            $message.= "</html>\n";
            // FINAL BOUNDARY
            $message.= "\n--$mime_boundary--\n\n";
            // SEND MAIL
            @mail(
                Clementine::$config['clementine_global']['email_dev'],
                Clementine::$config['clementine_global']['site_name'] . ': Error (' . $error_type . ') ',
                $message,
                $headers
            );
        }
        if (Clementine::$config['clementine_debug']['log_errors']) {
            Clementine::log($display_error_log, $color, false);
        }
        if ($fatal) {
            die();
        }
        return true;
    }

    /**
     * log : error_log colored messages
     *
     * @param mixed $message : message to log
     * @param mixed $color : custom color code, or keyword in ("info", "warn", "error", "red", "greed", "blue", "yellow")
     * @access public
     * @return void
     */
    public static function log($message, $color = "", $headline = true)
    {
        $nocolor = "\e[0m";
        switch ($color) {
        case 'info':
        case 'green':
            $color = "\033[32m";
            break;
        case 'warning':
        case 'warn':
        case 'yellow':
        case 'orange':
            $color = "\033[33m";
            break;
        case 'fatal':
        case 'error':
        case 'err':
        case 'red':
            $color = "\033[31m";
            break;
        case 'blue':
            $color = "\033[34m";
            break;
        }
        $log = $message;
        if (is_array($message) || is_object($message)) {
            $log = print_r($message, true);
        }
        if ($color) {
            $log.= $nocolor;
        }
        $entete = '';
        if ($headline) {
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            $entete = 'Clementine::log from ' . $backtrace[0]['file'] . ':' . $backtrace[0]['line'] . PHP_EOL;
        }
        error_log($color . $entete . $log);
    }

    public static function clementine_shutdown_handler()
    {
        $error = error_get_last();
        if ($error !== null && ($error['type'] & (E_ERROR | E_USER_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_RECOVERABLE_ERROR))) {
            Clementine::clementine_error_handler($error['type'], $error['message'], $error['file'], $error['line']);
        }
    }

    /**
     * dump : prints developer-readable information about a variable
     *
     * @param mixed $thing
     * @param mixed $return
     * @static
     * @access public
     * @return void
     */
    public static function dump($thing, $return = false, $highlight = true)
    {
        if (is_string($thing)) {
            $dump = $thing;
        } else {
            $dump = var_export($thing, true);
        }
        $dump = preg_replace('/' . PHP_EOL . ' *stdClass::__set_state/', 'stdClass::__set_state', $dump);
        $dump = preg_replace("/ => " . PHP_EOL . " *array *\(/S", ' => array(', $dump);
        if ($highlight) {
            $dump = highlight_string('<?php ' . $dump . PHP_EOL . '?>', true);
            $dump = preg_replace('@^<code><span style="color: #000000">' . PHP_EOL . '<span style="color: #0000BB">&lt;\?php&nbsp;@', '<code><span style="color: #0000BB">' . PHP_EOL, $dump);
            $dump = str_replace('<code>', '<code style="display: table; text-align: left; background-color: #DDD;">', $dump);
            $dump = preg_replace('@\?&gt;</span>' . PHP_EOL . '</span>' . PHP_EOL . '</code>$@', '</span></code>' . PHP_EOL, $dump);
            $dump = str_replace('<br />', '<br />' . PHP_EOL, $dump);
        } else {
            $dump = htmlentities($dump, ENT_QUOTES, __PHP_ENCODING__);
            $dump = '<pre>' . $dump . '</pre>';
        }
        if (!$return) {
            echo $dump;
        }
        return $dump;
    }

}

class ClementineRequest
{
    public $allowed_request_methods = array(
        'HEAD' => '',
        'POST' => '',
        'OPTIONS' => '',
        'CONNECT' => '',
        'TRACE' => '',
        'PUT' => '',
        'DELETE' => ''
    );

    public function __construct()
    {
        $this->METHOD = 'GET';
        $this->INVOCATION_METHOD = __INVOCATION_METHOD__;
        // si appel en CLI, on reconstruit _GET a partir de argv[3]
        if ($this->INVOCATION_METHOD == 'CLI') {
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
            // si appel en CLI, on considère qu'on est en local
            $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        }
        if (isset($_SERVER['REQUEST_METHOD']) && isset($this->allowed_request_methods[$_SERVER['REQUEST_METHOD']])) {
            $this->METHOD = $_SERVER['REQUEST_METHOD'];
        }
        $this->REMOTE_ADDR = $_SERVER['REMOTE_ADDR'];
        $this->DATE = date('Y-m-d H:i:s', $_SERVER['REQUEST_TIME']);
        // populate request
        if (!session_id()) {
            session_start();
        }
        if (get_magic_quotes_gpc()) {
            $this->GET = $this->stripslashesRecursive($_GET);
            $this->POST = $this->stripslashesRecursive($_POST);
            $this->COOKIE = $this->stripslashesRecursive($_COOKIE);
            $this->REQUEST = $this->stripslashesRecursive($_REQUEST);
        } else {
            $this->GET = $_GET;
            $this->POST = $_POST;
            $this->COOKIE = $_COOKIE;
            $this->REQUEST = $_REQUEST;
        }
        $this->SESSION = $_SESSION;
        $this->SERVER = $_SERVER;
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

    /**
     * map_url : met en place un url_rewriting dans Clementine par un preg_replace(). A utiliser depuis le hook 'before_request'
     *
     * @param mixed $from_expreg
     * @param mixed $to
     * @param mixed $redirection_http : effectue une redirection HTTP de code $redirection_http au lieu d'un mapping d'url
     * @access public
     * @return void
     */
    public function map_url($from_expreg, $to, $redirection_http = null)
    {
        if (!(isset(Clementine::$register['request']) && isset(Clementine::$register['request']->CTRL))) {
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
                            $val = '';
                        }
                        if (!array_key_exists($key, $_GET)) {
                            $_GET[$key] = $val;
                            Clementine::$register['request']->GET = $_GET;
                        }
                    }
                }
            }
            if (Clementine::$register['request_uri'] != $old_request_uri) {
                if ($redirection_http) {
                    // si appel en CLI on affiche un message à la place
                    if (__INVOCATION_METHOD__ == 'CLI') {
                        echo ('Redirects with code ' . $redirection_http . ' to: ' . __WWW_ROOT__ . '/' . Clementine::$register['request_uri']);
                    } else {
                        header('Location: ' . __WWW_ROOT__ . '/' . Clementine::$register['request_uri'], true, $redirection_http);
                    }
                    die();
                } else {
                    if (__DEBUGABLE__ && Clementine::$config['clementine_debug']['hook']) {
                        Clementine::$clementine_debug['hook'][] = '
                            <strong>$this->hook(\'before_first_getController\')</strong>
                            <a href="' . __BASE_URL__ . '/' . $from_expreg . '">' . __BASE_URL__ . '/' . $from_expreg . '</a>
                            => <a href="' . __BASE_URL__ . '/' . $to . '">' . __BASE_URL__ . '/' . $to . '</a>';
                    }
                }
            }
        } elseif (__DEBUGABLE__ && Clementine::$config['clementine_debug']['display_errors']) {
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
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
    public function canonical_url($from, $to)
    {
        $this->map_url('^' . $to . '(\?.*)*$', $from . '\\1', 301);
        $this->map_url('^' . $from . '(\?.*)*$', $to . '\\1');
    }

    public function get($type, $key, $options = array())
    {
        // overwrite options
        $params = array_merge($options, array(
            'type' => $type,
            'key' => $key,
            'array' => $this->GET,
        ));
        return $this->getFromGPC($params);
    }

    public function post($type, $key, $options = array())
    {
        // overwrite options
        $params = array_merge($options, array(
            'type' => $type,
            'key' => $key,
            'array' => $this->POST,
        ));
        return $this->getFromGPC($params);
    }

    public function cookie($type, $key, $options = array())
    {
        // overwrite options
        $params = array_merge($options, array(
            'type' => $type,
            'key' => $key,
            'array' => $this->COOKIE,
        ));
        return $this->getFromGPC($params);
    }

    public function session($type, $key, $options = array())
    {
        // overwrite options
        $params = array_merge($options, array(
            'type' => $type,
            'key' => $key,
            'array' => $this->SESSION,
        ));
        return $this->getFromGPC($params);
    }

    public function server($type, $key, $options = array())
    {
        // overwrite options
        $params = array_merge($options, array(
            'type' => $type,
            'key' => $key,
            'array' => $this->SERVER,
        ));
        return $this->getFromGPC($params);
    }

    public function request($type, $key, $options = array())
    {
        // overwrite options
        $params = array_merge($options, array(
            'type' => $type,
            'key' => $key,
            'array' => $this->REQUEST,
        ));
        return $this->getFromGPC($params);
    }

    public function getFromGPC($params)
    {
        if (empty($params['type'])) {
            if (__DEBUGABLE__ && Clementine::$config['clementine_debug']['display_errors']) {
                Clementine::$register['clementine_debug_helper']->trigger_error("Missing <em>type</em> parameter ", E_USER_ERROR, 2);
            }
            die();
        }
        // raccourci pour recuperer plus facilement des contenus HTML
        $typehtml = 0;
        if ($params['type'] == 'html') {
            $params['striptags'] = 0;
            $params['type'] = 'string';
            $typehtml = 1;
        }
        $r = $this->ifSetGet($params);
        if (!get_magic_quotes_gpc()) {
            if ($params['type'] == 'array') {
                if (is_array($r)) {
                    foreach ($r as $subkey => $val) {
                        $r[$subkey] = addslashes($r[$subkey]);
                    }
                } else {
                    $r = array();
                }
            } else {
                $r = addslashes($r);
            }
        }
        // EN AJAX, ATTENTION A L'ENCODAGE : pour s'assurer que les caractères accentués (ou le sigle euro par exemple) sont bien transmis, il FAUT encoder l'URL
        // En JAVASCRIPT on utilisera la fonction encodeURIComponent
        // En PHP on utilisera la fonction rawurlencode() pour obtenir le meme encodage (et non urlencode(), qui n'encode pas les espaces pareil)
        if ($typehtml) {
            $r = preg_replace('@<script[^>]*?>.*?</script>@si', '', $r);
        } else {
            if ($params['type'] == 'array') {
                if (is_array($r)) {
                    foreach ($r as $subkey => $val) {
                        $r[$subkey] = htmlentities(stripslashes($r[$subkey]), ENT_QUOTES, __PHP_ENCODING__);
                    }
                } else {
                    $r = array();
                }
            } else {
                $r = htmlentities(stripslashes($r), ENT_QUOTES, __PHP_ENCODING__);
            }
        }
        return $r;
    }

    /**
     * ifSetGet : fonction centrale de la recuperation de paramètres : recupere le parametre $key dans le tableau $array
     *
     * @param mixed $type : force le typage
     * @param mixed $array : Clementine::$register['request']->GET, Clementine::$register['request']->POST, ou Clementine::$register['request']->COOKIE... ou n'importe quel tableau
     * @param mixed $key : nom du parametre à recuperer
     * @param mixed $ifset : valeur a récupérer a la place du paramètre si celui si existe bien dans $array
     * @param mixed $ifnotset : valeur a récupérer a la place du paramètre si celui si n'existe pas dans $array
     * @param int $non_vide : si $array[$key] == '' et que ce parametre est positionne, on considere que !isset($array[$key]). On renvoie donc $ifnotset le cas echeant.
     * @param int $trim : 0 => pas de trim, 1 => trim normal, 2 => trim violent (vire aussi tous les retours a la ligne).
     * @param int $striptags : 0 => pas de strip_tags, 1 => strip_tags, '<p><a>' => liste des tags autorises, strip tous les autres
     * @access public
     * @return void
     */
    public function ifSetGet($params)
    {
        if (empty($params['type'])) {
            if (__DEBUGABLE__ && Clementine::$config['clementine_debug']['display_errors']) {
                Clementine::$register['clementine_debug_helper']->trigger_error("Missing <em>type</em> parameter ", E_USER_ERROR, 2);
            }
            die();
        }
        if (empty($params['key'])) {
            if (__DEBUGABLE__ && Clementine::$config['clementine_debug']['display_errors']) {
                Clementine::$register['clementine_debug_helper']->trigger_error("Missing <em>key</em> parameter ", E_USER_ERROR, 2);
            }
            die();
        }
        if (!isset($params['array'][$params['key']])) {
            // securite !
            if (isset($params['ifnotset'])) {
                @settype($params['ifnotset'], $params['type']);
                return $params['ifnotset'];
            }
            $ret = null;
            @settype($ret, $params['type']);
            return $ret;
        } else {
            if (!empty($params['striptags'])) {
                $params['striptags_tags'] = ((strlen($params['striptags']) && $params['striptags'] != 1) ? $params['striptags'] : ''); // tags a preserver
                if ($params['type'] == 'array') {
                    foreach ($params['array'][$params['key']] as $subkey => $val) {
                        $params['array'][$params['key']][$subkey] = strip_tags($params['array'][$params['key']][$subkey], $params['striptags_tags']);
                    }
                } else {
                    $params['array'][$params['key']] = strip_tags($params['array'][$params['key']], $params['striptags_tags']);
                }
            }
            if (!empty($params['trim'])) {
                $params['array'][$params['key']] = trim($params['array'][$params['key']]);
                if ($params['trim'] == 2) {
                    $params['array'][$params['key']] = trim(preg_replace("/((\r)*\n*)*/", "", $params['array'][$params['key']]));
                }
            }
            if (!(empty($params['non_vide']) || ($params['non_vide'] && strlen($params['array'][$params['key']])))) {
                if (isset($params['ifnotset'])) {
                    @settype($params['ifnotset'], $params['type']);
                    return $params['ifnotset'];
                }
                $ret = null;
                @settype($ret, $params['type']);
                return $ret;
            }
            if (isset($params['ifset']) && !is_array($params['ifset'])) {
                return $params['ifset'];
            } else {
                $ret = $params['array'][$params['key']];
                // securite !
                @settype($ret, $params['type']);
                // si ifset est un array, on renvoie la concatenation de ses elements, en concatenant $var a chacun
                if (isset($params['ifset']) && is_array($params['ifset'])) {
                    $retour = '';
                    $ifset_sz = count($params['ifset']) - 1;
                    if ($ifset_sz < 1) {
                        $ifset_sz = count($params['ifset']);
                    }
                    for ($i = 0; $i < $ifset_sz; ++$i) {
                        $retour.= $params['ifset'][$i] . $ret;
                    }
                    if ($ifset_sz == count($params['ifset']) - 1) {
                        $retour.= $params['ifset'][$ifset_sz];
                    }
                    return $retour;
                } else {
                    return $ret;
                }
            }
        }
    }
}
