<?php

namespace Nraa\Bootstrap;

class Autoloader
{
    /**
     * File extension as a string. Defaults to ".php".
     */
    protected static $fileExt = '.php';

    /**
     * The top level directory where recursion will begin. Defaults to the current
     * directory.
     */
    protected static $pathTop = [];

    /**
     * A placeholder to hold the file iterator so that directory traversal is only
     * performed once.
     */
    protected static $fileIterator = null;

    protected static $loadedClasses = [];

    /**
     * Sanitizes a fully qualified class name by replacing namespace separators with
     * directory separators and then extracting the final component of the resulting path.
     *
     * @param string $className The fully qualified class name to sanitize
     * @return string The sanitized class name
     */
    protected static function sanitizeClassName($className): string
    {
        return basename(str_replace('\\', '/', $className));
    }

    /**
     * Autoload function for registration with spl_autoload_register
     *
     * Looks recursively through project directory and loads class files based on
     * filename match.
     *
     * @param string $className
     */
    public static function loader($className)
    {
        foreach (static::$pathTop as $path) {
            $directory = new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS);

            static::$fileIterator = new \RecursiveIteratorIterator($directory, \RecursiveIteratorIterator::SELF_FIRST);

            $filename = static::sanitizeClassName($className) . static::$fileExt;
            foreach (static::$fileIterator as $file) {
                if (strtolower($file->getFilename()) === strtolower($filename)) {
                    if ($file->isReadable()) {
                        //echo $file->getPathname() . "<br />\n";
                        require_once $file->getPathname();
                    }
                    continue;
                }
            }
        }
    }

    /**
     * Returns an array of loaded class names. This can be useful for debugging purposes.
     *
     * @return array<string> An array of loaded class names
     */
    public static function getLoadedClasses()
    {
        return static::$loadedClasses;
    }

    /**
     * Sets the $fileExt property
     *
     * @param string $fileExt The file extension used for class files.  Default is "php".
     */
    public static function setFileExt($fileExt)
    {
        static::$fileExt = $fileExt;
    }

    /**
     * Sets the $path property
     *
     * @param string $path The path representing the top level where recursion should
     *                     begin. Defaults to the current directory.
     */
    public static function setPath($path)
    {
        array_push(static::$pathTop, $path);
    }
}
