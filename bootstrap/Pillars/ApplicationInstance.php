<?php

namespace Nraa\Pillars;

trait ApplicationInstance
{
    /**
     * Returns the singleton instance of the Application class.
     *
     * This method is used to get the single instance of the Application class.
     * It creates a new instance of the class if no instance exists, and returns the existing instance if one exists.
     *
     * @return Application The singleton instance of the Application class.
     */
    public static function getApp()
    {
        return Application::getInstance();
    }
}
