<?php // vim:set ts=4 sw=4 et:
// Default to only smarty support for now
// The autoload fails to handle this because its smarty naming

/**
 * The Aether web framework
 *
 * Aether is a rule driven modularized web framework for PHP.
 * Instead of following a more traditional MVC pattern
 * it helps you connect a resource (an url) to a set of modules
 * that each provide a part of the page
 *
 * A "module" can be thought of as a Controller, except you can, and will,
 * have multiple of them for each page as normaly a page requires
 * functionality that doesnt logicaly connect to just one component.
 * Viewing an article? Then you probably need links to more articles,
 * maybe some forum integration, maybe a gallery etc etc
 * All these things should in Aether be handled as separate modules
 * instead of trying to jam it into one controller.
 *
 * Instead of bringing in a huge package of thousands of thousands of lines of code
 * containing everything from the framework, to models (orm) to templating to helpers
 * Aether focusing merely on the issue of delegating urls/resources to code
 * and in their communication in between.
 *
 *
 * Created: 2007-01-31
 * @author Raymond Julin
 * @package aether
 */

class Aether {

    /**
     * Hold service locator
     * @var AetherServiceLocator
     */
    private $sl = null;

    /**
     * Section
     * @var AetherSection
     */
    private $section = null;

    /**
     * Root folder for this project
     * @var string
     */
    private $projectRoot;

    public static $aetherPath;

    /**
     * Start Aether.
     * On start it will parse the projects configuration file,
     * it will try to match the presented http request to a rule
     * in the project configuration and create some overview
     * over which modules it will need to render once
     * a request to render them comes
     *
     * @access public
     * @return Aether
     * @param string $configPath Optional path to the configuration file for the project
     */
    public function __construct($configPath=false) {
        self::$aetherPath = pathinfo(__FILE__, PATHINFO_DIRNAME) . "/";
        $this->sl = new AetherServiceLocator;

        $this->sl->set('aetherPath', self::$aetherPath);

        // Initiate all required helper objects
        $parsedUrl = new AetherUrlParser;
        $parsedUrl->parseServerArray($_SERVER);
        $this->sl->set('parsedUrl', $parsedUrl);

        /**
         * Find config folder for project
         * By convention the config folder is always placed at
         * $project/config, while using getcwd() MUST return the
         * $project/public/ folder
         */
        $projectPath = preg_replace("/public\/?$/", "", getcwd());

        $this->sl->set("projectRoot", $projectPath);
        if (!defined("PROJECT_PATH"))
            define("PROJECT_PATH", $projectPath);

        $paths = array(
            $configPath,
            $projectPath . 'config/autogenerated.config.xml',
            $projectPath . 'config/aether.config.xml'
        );
        foreach ($paths as $configPath) {
            if (file_exists($configPath))
                break;
        }
        try {
            $config = new AetherConfig($configPath);
            $config->matchUrl($parsedUrl);
            $this->sl->set('aetherConfig', $config);
        }
        catch (AetherMissingFileException $e) {
            /**
             * This means that someone forgot to ensure the config
             * file actually exists
             */
            $msg = "No configuration file for project found: " . $e->getMessage();
            throw new Exception($msg);
        }
        catch (AetherNoUrlRuleMatchException $e) {
            /**
             * This means parsing of configuration file failed
             * by the simple fact that no rules matches
             * the url. This is due to a bad developer
             */
            $msg = "No rule matched url in config file: " . $e->getMessage();
            throw new Exception($msg);
        }

        $options = $config->getOptions(array(
            'AetherRunningMode' => 'prod',
            'cache' => 'off'
        ));
        if ($options['cache'] == 'on') {
            $cacheClass = isset($options['cacheClass']) ? $options['cacheClass'] : 'AetherCache';
            $cacheOptions = isset($options['cacheOptions']) ? $options['cacheOptions'] : [];
            $cache = $this->getCacheObject($cacheClass, $cacheOptions);
            $this->sl->set("cache", $cache);
        }

        /**
         * Make sure base and root for this request is stored
         * in the service locator so it can be made available
         * to the magical $aether array in templates
         */
        $magic = $this->sl->getVector('templateGlobals');
        $magic['base'] = $config->getBase();
        $magic['root'] = $config->getRoot();
        $magic['urlVars'] = $config->getUrlVars();
        $magic['runningMode'] = $options['AetherRunningMode'];
        $magic['requestUri'] = $_SERVER['REQUEST_URI'];
        $magic['domain'] = $_SERVER['HTTP_HOST'];
        if (isset($_SERVER['HTTP_REFERER']))
            $magic['referer'] = $_SERVER['HTTP_REFERER'];
        $magic['options'] = $options;

        // Load and bootstrap any Aether Bundles.
        $this->sl->bundle()->bootstrap();

        /**
         * If we are in TEST mode we should prepare a timer object
         * and time everything that happens
         */
        if ($options['AetherRunningMode'] == 'test') {
            // Prepare timer
            $timer = new AetherTimer;
            $timer->start('aether_main');
            $this->sl->set('timer', $timer);
        }

        // Initiate section
        try {
            $this->section = AetherSectionFactory::create(
                $config->getSection(),
                $this->sl
            );
            $this->sl->set('section', $this->section);
            if (isset($timer))
                $timer->tick('aether_main', 'section_initiate');
        }
        catch (Exception $e) {
            // Failed to load section, what to do?
            throw new Exception('Failed horribly: ' . $e->getMessage());
        }
    }

    /**
     * Ask the AetherSection to render itself,
     * or if a service is requested it will try to load that service
     *
     * @access public
     * @return string
     */
    public function render() {
        $config = $this->sl->get('aetherConfig');
        $options = $config->getOptions();

        /**
         * If a service is requested simply render the service
         */
        if (isset($_GET['module']) && isset($_GET['service'])) {
            $response = $this->section->service($_GET['module'], $_GET['service']);
            if (!is_object($response) || !($response instanceof AetherResponse)) {
                trigger_error("Expected " . preg_replace("/[^A-z0-9]+/", "", $_GET['module']) . "::service() to return an AetherResponse object." . (isset($_SERVER['HTTP_REFERER']) ? " Referer: " . $_SERVER['HTTP_REFERER'] : ""), E_USER_WARNING);
            }
            else {
                $response->draw($this->sl);
            }
        }
        else if (isset($_GET['fragment']) && isset($_GET['service'])) {
            $response = $this->section->service($_GET['fragment'], ($_GET['service'] !== "_esi" ? $_GET['service'] : null), 'fragment');
            $response->draw($this->sl);
        }
        else if (isset($_GET['_esi'])) {
            /**
             * ESI support and rendering of only one module by provider name
             * # _esi to list
             * # _esi=<providerName> to render one module with settings of the url path
             */
            if (strlen($_GET['_esi']) > 0) {
                $locale = (isset($options['locale'])) ? $options['locale'] : "nb_NO.UTF-8";
                setlocale(LC_ALL, $locale);

                $lc_numeric = (isset($options['lc_numeric'])) ? $options['lc_numeric'] : 'C';
                setlocale(LC_NUMERIC, $lc_numeric);

                if (isset($options['lc_messages'])) {
                    $localeDomain = "messages";
                    setlocale(LC_MESSAGES, $options['lc_messages']);
                    bindtextdomain($localeDomain, self::$aetherPath . "/locales");
                    bind_textdomain_codeset($localeDomain, 'UTF-8');
                    textdomain($localeDomain);
                }
                $this->section->renderProviderWithCacheHeaders($_GET['_esi']);
            }
            else {
                $modules = $config->getModules();
                $fragments = $config->getFragments();
                $providers = array();
                foreach ($modules + $fragments as $m) {
                    $provider = [
                        'provides' => isset($m['provides']) ? $m['provides'] : null,
                        'cache' => isset($m['cache']) ? $m['cache'] : false
                    ];
                    if (isset($m['module'])) {
                        $provider['providers'] = array_map(function ($m) {
                            return [
                                'provides' => $m['provides'],
                                'cache' => isset($m['cache']) ? $m['cache'] : false
                            ];
                        }, array_values($m['module']));
                    }
                    $providers[] = $provider;
                }
                $response = new AetherJSONResponse(array('providers' => $providers));
                $response->draw($this->sl);
            }
        }
        else {
            /**
             * Start session if session switch is turned on in
             * configuration file
             */
            if (array_key_exists('session', $options)
                    AND $options['session'] == 'on') {
                session_start();
            }

            $response = $this->section->response();
            $response->draw($this->sl);
        }
    }

    private function getCacheObject($class, $options) {
        if (class_exists($class)) {
            $obj = new $class($options);
            if ($obj instanceof AetherCache)
                return $obj;
        }
        return false;
    }
}
