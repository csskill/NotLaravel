<?php

namespace Nraa\Pillars\Console;

use Nraa\Helpers\IndexManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use ReflectionClass;

#[AsCommand(
    name: 'update-indexes',
    description: 'Update MongoDB indexes for all registered Model classes',
)]
class UpdateIndexesCommand extends Command
{
    /**
     * Execute the command.
     *
     * This command ensures indexes for all registered Model classes and
     * automatically discovers and registers any missing Model classes.
     *
     * @param InputInterface $input The input interface
     * @param OutputInterface $output The output interface
     *
     * @return int The exit status of the command
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $io->title('Updating MongoDB Indexes');
        
        // Discover all Model classes
        $io->section('Discovering Model classes...');
        $allModelClasses = $this->discoverModelClasses();
        
        // Get currently registered models
        $registeredModels = IndexManager::getRegisteredModels();
        
        // Find missing models
        $missingModels = array_diff($allModelClasses, $registeredModels);
        
        if (!empty($missingModels)) {
            $io->warning('Found ' . count($missingModels) . ' unregistered Model class(es)');
            $io->listing($missingModels);
            
            // Register missing models
            foreach ($missingModels as $modelClass) {
                IndexManager::registerModel($modelClass);
                $io->text("  ✓ Registered: {$modelClass}");
            }
            
            $io->newLine();
        } else {
            $io->success('All Model classes are already registered');
            $io->newLine();
        }
        
        // Ensure indexes for all models
        $io->section('Ensuring indexes for all models...');
        $io->progressStart(count($allModelClasses));
        
        $successCount = 0;
        $errorCount = 0;
        $errors = [];
        
        foreach ($allModelClasses as $modelClass) {
            try {
                if (class_exists($modelClass) && method_exists($modelClass, 'ensureIndexes')) {
                    $instance = new $modelClass();
                    $instance->ensureIndexes();
                    $successCount++;
                } else {
                    $errors[] = "{$modelClass}: Class not found or does not have ensureIndexes() method";
                    $errorCount++;
                }
            } catch (\Exception $e) {
                $errors[] = "{$modelClass}: " . $e->getMessage();
                $errorCount++;
            }
            
            $io->progressAdvance();
        }
        
        $io->progressFinish();
        $io->newLine();
        
        // Report results
        if ($errorCount === 0) {
            $io->success("Successfully ensured indexes for {$successCount} model(s)");
        } else {
            $io->warning("Completed with {$errorCount} error(s)");
            $io->text("Errors:");
            $io->listing($errors);
        }
        
        return $errorCount === 0 ? Command::SUCCESS : Command::FAILURE;
    }
    
    /**
     * Discover all Model classes in the application.
     *
     * Scans the Models directory for classes that extend Model or \Nraa\Database\Model
     *
     * @return array Array of fully qualified class names
     */
    private function discoverModelClasses(): array
    {
        $modelsDir = dirname(__DIR__, 3) . '/app/Models';
        $modelClasses = [];
        
        if (!is_dir($modelsDir)) {
            return $modelClasses;
        }
        
        // Get all PHP files recursively
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($modelsDir)
        );
        
        $files = new \RegexIterator($iterator, '/^.+\.php$/i', \RecursiveRegexIterator::GET_MATCH);
        
        foreach ($files as $file) {
            $filePath = $file[0];
            
            // Skip non-PHP files and vendor directories
            if (strpos($filePath, '/vendor/') !== false) {
                continue;
            }
            
            // Get namespace and class name from file
            $content = file_get_contents($filePath);
            
            // Extract namespace
            if (!preg_match('/namespace\s+([^;]+);/', $content, $namespaceMatch)) {
                continue;
            }
            $namespace = $namespaceMatch[1];
            
            // Extract class name - match both patterns:
            // 1. "extends \Nraa\Database\Model" (fully qualified)
            // 2. "extends Model" when Model is imported via "use Nraa\Database\Model"
            $classMatch = null;
            if (preg_match('/class\s+(\w+)\s+extends\s+\\\?Nraa\\\\Database\\\\Model/', $content, $matches)) {
                $classMatch = $matches;
            } elseif (preg_match('/use\s+(?:Nraa\\\\Database\\\\)?Model\s*;/', $content) && 
                      preg_match('/class\s+(\w+)\s+extends\s+Model\b/', $content, $matches)) {
                $classMatch = $matches;
            }
            
            if (!$classMatch) {
                continue;
            }
            $className = $classMatch[1];
            
            $fullClassName = $namespace . '\\' . $className;
            
            // Verify class exists and extends Model
            if (class_exists($fullClassName)) {
                try {
                    $reflection = new ReflectionClass($fullClassName);
                    if ($reflection->isInstantiable() && $reflection->hasMethod('ensureIndexes')) {
                        $modelClasses[] = $fullClassName;
                    }
                } catch (\ReflectionException $e) {
                    // Skip if reflection fails
                    continue;
                }
            }
        }
        
        // Sort alphabetically for consistent output
        sort($modelClasses);
        
        return $modelClasses;
    }
}
