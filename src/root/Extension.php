<?php

interface iLettuceExtension {
    const
            INSTANTIATE_INSTANCE                = 0,                        // Instantiate the extension as an instance
            INSTANTIATE_SINGLETON               = 1,                        // Instantiate the extension as a singleton
            INSTANTIATE_NONE                    = 2,                        // Do not instantiate the class (used for abstract classes etc)

            OPTION_HAS_CONFIG_FILE              = 'o_has_config_file',      // A Configuration file is present. True = search, string = path to specific file
            OPTION_INSTANTIATE_AS               = 'o_inst_as',              // Specifies the instantiation method. Default: INSTANTIATE_INSTANCE
            OPTION_INSTANTIATE_AS_LOCK          = 'o_inst_as_lock',         // Forces instantiation to honour the default instantiation method specified by the extension
            OPTION_LOAD_EXTERNAL_CONFIG         = 'o_load_external_config', // Tells the extension loader that there is an external config file that needs to be imported
            OPTION_BASE_PATH                    = 'o_base_path';            // Base path to prepend to the module path (ignored by DI)

    static function ExtGetOptions();
    public function __construct($params, $config);
}
