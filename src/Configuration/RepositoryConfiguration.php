<?php

/*
 * This file is part of the Composer Resource Plugin package.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webmozart\Composer\ResourcePlugin\Configuration;

/**
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class RepositoryConfiguration
{
    private $exportedFiles = array();

    private $exportedDirs = array();

    private $tags = array();

    private $pathMap = array();

    private $rootDirectory;

    public function setRootDirectory($rootDirectory)
    {
        $this->rootDirectory = $rootDirectory;
    }

    public function getRootDirectory()
    {
        return $this->rootDirectory;
    }

    public function export($pattern, $repositoryPath)
    {
        $paths = glob($pattern);

        if (0 === count($paths)) {
            throw new UnmatchedPatternException(sprintf(
                'The pattern "%s" did not match any file.',
                $pattern
            ));
        }

        // If exactly one directory is matched, let the repository path point
        // to that directory
        if (1 === count($paths) && is_dir($paths[0])) {
            $this->exportDirectory(rtrim($paths[0], '/'), $repositoryPath);

            return;
        }

        // If multiple paths are matched, create sub-paths for each entry
        foreach ($paths as $path) {
            $nestedRepositoryPath = $repositoryPath.'/'.basename($path);

            if (is_dir($path)) {
                $this->exportDirectory(rtrim($path, '/'), $nestedRepositoryPath);
            } else {
                $this->exportFile($path, $nestedRepositoryPath);
            }
        }
    }

    public function getExportedFiles()
    {
        return $this->exportedFiles;
    }

    public function getExportedDirectories()
    {
        return $this->exportedDirs;
    }

    public function tag($pattern, $tag)
    {
        $paths = glob($pattern);

        if (0 === count($paths)) {
            throw new UnmatchedPatternException(sprintf(
                'The pattern "%s" did not match any file.',
                $pattern
            ));
        }

        if (!isset($this->tags[$tag])) {
            $this->tags[$tag] = array();
        }

        foreach ($paths as $path) {
            $path = rtrim($path, '/');

            // Check whether the path was exported directly
            $isExported = isset($this->pathMap[$path]);

            // Else check whether one of its parent directories was exported
            if (!$isExported) {
                foreach ($this->exportedDirs as $dirPaths) {
                    foreach ($dirPaths as $dirPath) {
                        if (0 === strpos($path, $dirPath.'/')) {
                            $isExported = true;

                            break 2;
                        }
                    }
                }
            }

            // Else report an error
            if (!$isExported) {
                throw new PathNotExportedException(sprintf(
                    'The path "%s" was not exported.',
                    $path
                ));
            }

            $this->tags[$tag][] = $path;
        }
    }

    public function getTaggedPaths()
    {
        return $this->tags;
    }

    private function exportFile($path, $repositoryPath)
    {
        if (!isset($this->exportedFiles[$repositoryPath])) {
            $this->exportedFiles[$repositoryPath][] = array();
        }

        $this->exportedFiles[$repositoryPath][] = $path;
        $this->pathMap[$path] = true;
    }

    private function exportDirectory($path, $repositoryPath)
    {
        if (!isset($this->exportedDirs[$repositoryPath])) {
            $this->exportedDirs[$repositoryPath] = array();
        }

        $this->exportedDirs[$repositoryPath][] = $path;
        $this->pathMap[$path] = true;
    }
}
