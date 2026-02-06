<?php

namespace Nraa\Pillars;

final class LoadConfiguration
{

    private $_configs = [];

    /**
     * Adds a configuration to the list of configurations to be loaded.
     * 
     * The configuration is an object that has a `load` method.
     * The `load` method will be called when the application is initialized and will receive the application instance as an argument.
     * The configuration provider can then use the application instance to add their own configuration options, such as environment variables, database connections, etc.
     * @param object $conf The configuration provider.
     * @return void
     */
    public function addConfiguration($conf)
    {
        $this->_configs[] = $conf;
    }

    /**
     * Gets the first configuration added to the list.
     * This is usually the most important configuration, as it sets up the application environment.
     * @return object The configuration provider.
     */
    public function getConfig()
    {
        return $this->_configs[0];
    }
}
