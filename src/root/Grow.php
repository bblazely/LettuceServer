<?php

/**
 * Auto loader for LettuceCore
 */

Class LettuceGrow {

    private $root;
    private static $singleton_storage = [];

    const DEBUG             = true;
    const DEBUG_PATH        = '/tmp/lettuce_grow.log';

    const TYPE_EXTENSION    = '_extension';
    //const TYPE_MODEL        = '_model';
    const TYPE_CONTROLLER   = '_controller';
    const TYPE_EXTENSION_CONFIG       = '.config';

    const DEFAULT_LEAF      = 'default';

    const EXCEPTION_EXTENSION_NOT_FOUND         = 'LettuceGrow::ExtensionNotFound';
    const EXCEPTION_MODEL_NOT_FOUND             = 'LettuceGrow::ModelNotFound';
    const EXCEPTION_CONTROLLER_NOT_FOUND        = 'LettuceGrow::ControllerNotFound';
    const EXCEPTION_CONFIG_KEY_NOT_FOUND        = 'LettuceGrow::ConfigKeyNotFound';
    const EXCEPTION_VIEW_TYPE_INCORRECT         = 'LettuceGrow::ViewTypeIncorrect';
    const EXCEPTION_VIEW_LOAD_FAILED            = 'LettuceGrow::ViewLoadFailed';
    const EXCEPTION_CLASS_NOT_FOUND             = 'LettuceGrow::FileLoadedButClassNotFound';
    const EXCEPTION_FILE_NOT_FOUND              = 'LettuceGrow::FileNotFound';
    const EXCEPTION_UNKNOWN_TYPE                = 'LettuceGrow::UnknownModuleType';

    public function __construct(LettuceRoot $di_root) {
        $this->root = $di_root;
/*        spl_autoload_register(function ($class_name) {
            // Mainly used for resolving constants used prior to their classes being used.
            error_log('!!!ERROR!!! Auto Load Triggered: '.$class_name.' '.print_r(array_shift(debug_backtrace()), true));
            die();
        });*/
    }

    // TODO make this return the actual file name, for controller/model, search without suffix and then with if nothing found!
    private function pathDiscovery($name, $type, $in_sub_folder = false, $search_path = null) {
        if ($search_path == null) {
            $search_path = LETTUCE_SERVER_PATH;

            if ($type == LettuceGrow::TYPE_EXTENSION || $type == LettuceGrow::TYPE_EXTENSION_CONFIG) {
                $search_path .= '/extension/';
            } else if ($type == LettuceGrow::TYPE_CONTROLLER) { // || $type == LettuceGrow::TYPE_MODEL) {
                $search_path .= '/leaf/';
            } else {
                throw new CodedException(self::EXCEPTION_UNKNOWN_TYPE, 0, $type);
            }
        }

        if ($in_sub_folder) {
            $search_path .= $name . '/';
        }

        if (file_exists($search_path . $name . ($type == self::TYPE_EXTENSION_CONFIG ? self::TYPE_EXTENSION_CONFIG : '') . '.php')) {
            self::debug("{$name} found in path: {$search_path}");
            return $search_path;
        } else if ($in_sub_folder == false) {
            return self::pathDiscovery($name, $type, true, $search_path);
        } else {
            throw new CodedException(self::EXCEPTION_FILE_NOT_FOUND, null, $name.$type);
        }
    }

    /*private function loadClass($name, $path) {
        if (!class_exists($class_name, false)) {
            $search_path = $path . $name . '.php';
            if ($type != self::TYPE_MODEL && file_exists($search_path)) {
                print "Load file: {$search_path}\n";
                require($search_path);
            } else {                            // Check for a class within a extension directory ie: Core/Module/Module.php
                $search_path = $path . $class_name . '.php';
                if (file_exists($search_path)) {
                    print "Load file: {$search_path}\n";
                    require($search_path);
                } else {
                    if ($in_sub_folder == false) {
                        return self::loadClass($name, $type, true);
                    } else {
                        throw new CodedException(self::EXCEPTION_FILE_NOT_FOUND, null, $name);
                    }
                }
            }

            // Class file loaded, check that the required class is actually in there.
            if (!class_exists($class_name)) {
                throw new CodedException(self::EXCEPTION_CLASS_NOT_FOUND, null, $class_name);
            } else {
                return $class_name;            // Class File loaded, class found.
            }
        } else {
            return $class_name;                // Class previously loaded ok.
        }
    }*/


    /**
     * @param $name "Name of the core extension to load"
     * @param $param "Optional parameters to pass to the FIRST instantiation of the core extension"
     *
     * @throws CodedException
     *
     * @returns mixed
     */
    /*public function extension($name, $param = null) {
        if (isset($this->root->{$name})) {  // Return previously instantiated instance
            return $this->root->{$name};
        } else if (class_exists($name, false)) {   // Return that the class has been loaded and is ok to use.
            return true;
        } else if ($this->loadClass($name, LettuceGrow::TYPE_CORE )) {
            $core_interface = $name.'Core';
            if (class_exists($core_interface, false)) {
                $interface = new $core_interface($this->root, $param);
                if ($interface instanceof iLettuceCore) {
                    $this->root->{$name} = $interface;
                    return $this->root->{$name};
                } else {
                    throw new CodedException(self::EXCEPTION_CORE_MODULE_NO_INTERFACE, null, $name, null);
                }
            } else {
                return true;    // Class loaded, return true;
            }
        } else {
            throw new CodedException(self::EXCEPTION_CORE_MODULE_NOT_FOUND, null, $name);
        }
    }*/

    private function debug($msg) {
        if (self::DEBUG) {
            error_log(strftime('%T')." ". $msg ."\n", 3, self::DEBUG_PATH);
        }
    }

    static function extension($name, $params = [], $options = [], $config = null) {

        if ($params == null) {
            $params = [];
        }

        self::debug("Extension Requested: {$name}");

        if (!class_exists($name, false)) {
            self::debug("Class {$name} isn't loaded...");
            $path = self::pathDiscovery($name, self::TYPE_EXTENSION, false, Common::getIfSet($options[iLettuceExtension::OPTION_BASE_PATH]));
            self::debug("   +++ Load File: {$path}{$name}.php");
            require($path . $name . '.php');
        }

        // TODO Check the order of operations here... are dependencies necessary if not instantiating, or returning a singleton?
        if (isset(class_implements($name)['iLettuceExtension'])) {
            self::debug("*** {$name} is extension");

            $ext_default_options = $name::ExtGetOptions();
            $ext_config = Common::getIfSet($options[iLettuceExtension::OPTION_HAS_CONFIG_FILE], false) || Common::getIfSet($ext_default_options[iLettuceExtension::OPTION_HAS_CONFIG_FILE], false);        // If no config file passed in, ask the extension if it needs one or has a base path to look in

            if ($ext_config) {
                $ext_config_path = self::pathDiscovery($name, self::TYPE_EXTENSION_CONFIG, false, Common::getIfSet($options[iLettuceExtension::OPTION_BASE_PATH])) . $name . self::TYPE_EXTENSION_CONFIG . '.php';
                self::debug("{$name} has a config path at {$ext_config_path}");
                $ext_config = require($ext_config_path);
                if ($config) {
                    $ext_config = array_merge($ext_config, $config);
                }
            } else {
                $ext_config = $config;
            }

            // If Locked, Use Default. If not, use option if set, otherwise default to instance
            $instantiate_as = Common::getIfSet($ext_default_options[iLettuceExtension::OPTION_INSTANTIATE_AS_LOCK], false) ? Common::getIfSet($ext_default_options[iLettuceExtension::OPTION_INSTANTIATE_AS]) : Common::getIfSet($options[iLettuceExtension::OPTION_INSTANTIATE_AS], Common::getIfSet($ext_default_options[iLettuceExtension::OPTION_INSTANTIATE_AS], iLettuceExtension::INSTANTIATE_INSTANCE));

            switch($instantiate_as) {
                case iLettuceExtension::INSTANTIATE_SINGLETON:
                    if (!isset(self::$singleton_storage[$name])) {
                        self::debug("   @@@ Store Singleton: {$name}");
                        self::$singleton_storage[$name] = new $name($params, $ext_config);
                    }

                    self::debug("Return <Singleton> {$name}");
                    return self::$singleton_storage[$name];

                case iLettuceExtension::INSTANTIATE_NONE:
                    self::debug("Return <NULL> {$name}");
                    return null;

                default:
                    self::debug("Return <Instance> {$name}");
                    return new $name($params, $ext_config);
            }
        } else {
            switch (Common::getIfSet($options[iLettuceExtension::OPTION_INSTANTIATE_AS])) {
                case iLettuceExtension::INSTANTIATE_SINGLETON:
                    // Return the stored instance of the class
                    if (!isset(self::$singleton_storage[$name])) {
                        self::$singleton_storage[$name] = new $name(...$params);
                    }
                    return self::$singleton_storage[$name]; // Ignore params if the singleton has already been instantiated. Careful.

                case iLettuceExtension::INSTANTIATE_NONE:
                    return null;

                case iLettuceExtension::INSTANTIATE_INSTANCE:
                    return new $name(...$params);
            }
        }
    }

   /* public function model($name) {
        // TODO revise this at some point, it's pretty broken - ie: looks for the model in a file without _model on it...
        // Perhaps enforce that models must be in the controller file along side it.
        $class_name = $name . self::TYPE_MODEL;
        if (!class_exists($class_name)) {
            try {
                $path = self::pathDiscovery($class_name, self::TYPE_MODEL);
                require($path . $class_name . '.php');
                return new $class_name($this->root);
            } catch (Exception $e) {
                throw new CodedException(self::EXCEPTION_MODEL_NOT_FOUND, null, $name);
            }
        } else {
            return new $class_name($this->root);
        }
    }*/
    
    public function controller($name) {
        $class_name = $name . self::TYPE_CONTROLLER;
        if (!class_exists($class_name)) {
            try {
                $path = self::pathDiscovery($name, self::TYPE_CONTROLLER);
                require($path . $name . '.php');
            } catch (Exception $e) {
                return $this->controller(self::DEFAULT_LEAF);
            }
        }

        // Pop the controller id off the request stack because we found it.
        array_shift($this->root->request['path']);

        // Instantiate the new controller
        $controller = new $class_name($this->root);
        if (count($this->root->request['path']) != 0 && method_exists($controller, Common::getIfSet($this->root->request['path'][0]))) {
            call_user_func_array ( Array($controller, array_shift($this->root->request['path'])), $this->root->request['path']);
        } else {
            call_user_func_array ( Array($controller, 'root'), $this->root->request['path']);
        }
    }
}

