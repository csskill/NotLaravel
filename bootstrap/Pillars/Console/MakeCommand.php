<?php

namespace Nraa\Pillars\Console;

use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command;

abstract class MakeCommand extends Command
{
    protected $type;
    /**
     * The filesystem instance.
     *
     * @var \Illuminate\Filesystem\Filesystem
     */
    protected $files;

    abstract protected function getStub();

    protected $arguments = [];



    /**
     * Parse the class name and format according to the root namespace.
     *
     * @param  string  $name
     * @return string
     */
    protected function qualifyClass($name)
    {
        $name = ltrim($name, '\\/');

        $name = str_replace('/', '\\', $name);

        $rootNamespace = $this->rootNamespace();

        if (Str::startsWith($name, $rootNamespace)) {
            return $name;
        }

        return $this->qualifyClass(
            $this->getDefaultNamespace(trim($rootNamespace, '\\')) . '\\' . $name . ''
        );
    }

    /**
     * Returns the default namespace for the given root namespace.
     *
     * @param string $rootNamespace the root namespace
     *
     * @return string the default namespace
     */
    protected function getDefaultNamespace($rootNamespace)
    {
        return $rootNamespace . '\Controllers';
    }


    /**
     * Replaces the placeholders in the given stub with the actual values.
     * The placeholders are:
     * - DummyNamespace
     * - DummyRootNamespace
     * - NamespacedDummyUserModel
     * - {{ namespace }}
     * - {{ rootNamespace }}
     * - {{ namespacedUserModel }}
     * - {{namespace}}
     * - {{rootNamespace}}
     * - {{namespacedUserModel}}
     *
     * @param string &$stub the stub to replace the placeholders in
     * @param string $name the name of the class
     *
     * @return $this
     */
    protected function replaceNamespace(&$stub, $name)
    {
        $searches = [
            ['DummyNamespace', 'DummyRootNamespace', 'NamespacedDummyUserModel'],
            ['{{ namespace }}', '{{ rootNamespace }}', '{{ namespacedUserModel }}'],
            ['{{namespace}}', '{{rootNamespace}}', '{{namespacedUserModel}}'],
        ];

        foreach ($searches as $search) {
            $stub = str_replace(
                $search,
                [$this->getNamespace($name), $this->rootNamespace()],
                $stub
            );
        }

        return $this;
    }


    /**
     * Get the root namespace for the class.
     *
     * @return string
     */
    protected function rootNamespace()
    {
        return 'Nraa';
    }

    /**
     * Get the full namespace for a given class, without the class name.
     *
     * @param  string  $name
     * @return string
     */
    protected function getNamespace($name)
    {
        return trim(implode('\\', array_slice(explode('\\', $name), 0, -1)), '\\');
    }

    /**
     * Get the name input of the command without .php extension if exists
     *
     * @return string
     */
    protected function getNameInput()
    {

        $name = trim($this->arguments['name']);

        if (Str::endsWith($name, '.php')) {
            return Str::substr($name, 0, -4);
        }

        return $name;
    }


    /**
     * Determine if the class already exists.
     *
     * @param  string  $rawName
     * @return bool
     */
    protected function alreadyExists($rawName)
    {
        return false; // TODO
    }

    /**
     * Get the destination class path.
     *
     * @param  string  $name
     * @return string
     */
    protected function getPath($name)
    {
        $name = Str::replaceFirst($this->rootNamespace(), '', $name);

        return __DIR__ . '/../../../app/' . str_replace('\\', '/', $name) . 'Controller.php';
    }

    /**
     * Replace the class name for the given stub.
     *
     * @param  string  $stub
     * @param  string  $name
     * @return string
     */
    protected function replaceClass($stub, $name)
    {
        $class = str_replace($this->getNamespace($name) . '\\', '', $name);

        return str_replace(['DummyClass', '{{ class }}', '{{class}}'], $class, $stub);
    }

    /**
     * Save the given content to the given file path.
     *
     * @param string $path
     * @param string $content
     * @return bool
     */
    protected function saveFile($path, $content)
    {
        return file_put_contents($path, $content);
    }

    /**
     * Returns the content of the stub file.
     *
     * @return string
     */
    protected function getStubContent()
    {
        return file_get_contents($this->getStub());
    }

    /**
     * Build the class file from the stub.
     *
     * @param string $name the name of the class
     *
     * @return string the contents of the class file
     */
    protected function buildClass($name)
    {
        $stub = $this->getStubContent();

        return $this->replaceNamespace($stub, $name)->replaceClass($stub, $name);
    }

    /**
     * Build the directory for the class if necessary.
     *
     * @param  string  $path
     * @return string
     */
    protected function makeDirectory($path)
    {
        $directory = dirname($path);

        if (!file_exists($directory)) {
            mkdir($directory, 0775, true);
        }

        return $path;
    }
}
