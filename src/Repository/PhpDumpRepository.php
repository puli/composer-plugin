<?php

/*
 * This file is part of the Composer Resource Plugin package.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webmozart\Composer\ResourcePlugin\Repository;

use Webmozart\Composer\ResourcePlugin\Resource\DirectoryResource;
use Webmozart\Composer\ResourcePlugin\Resource\FileResource;
use Webmozart\Composer\ResourcePlugin\Resource\ResourceInterface;

/**
 * @since  %%NextVersion%%
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class PhpDumpRepository implements ResourceRepositoryInterface
{
    private $dumpLocation;

    private $config;

    private $paths;

    private $tags;

    public function __construct($dumpLocation)
    {
        if (!file_exists($dumpLocation.'/resources_paths.php') ||
            !file_exists($dumpLocation.'/resources_tags.php') ||
            !file_exists($dumpLocation.'/resources_config.php')) {
            throw new \InvalidArgumentException(sprintf(
                'The dump at "%s" is invalid. Please try to recreate it.',
                $dumpLocation
            ));
        }

        $this->dumpLocation = $dumpLocation;
    }

    public function getResource($repositoryPath)
    {
        if (null === $this->config) {
            $this->config = require ($this->dumpLocation.'/resources_config.php');
        }

        if (null === $this->paths) {
            $this->paths = require ($this->dumpLocation.'/resources_paths.php');
        }

        if (!isset($this->paths[$repositoryPath])) {
            throw new ResourceNotFoundException(sprintf(
                'The resource "%s" was not found.',
                $repositoryPath
            ));
        }

        if ($this->paths[$repositoryPath] instanceof ResourceInterface) {
            return $this->paths[$repositoryPath];
        }

        foreach ($this->paths[$repositoryPath] as $key => $path) {
            $this->paths[$repositoryPath][$key] = $this->config['root'].$path;
        }

        if (is_dir($this->paths[$repositoryPath][0])) {
            $this->paths[$repositoryPath] = new DirectoryResource(
                $repositoryPath,
                $this->paths[$repositoryPath]
            );
        } else {
            $this->paths[$repositoryPath] = new FileResource(
                $repositoryPath,
                array_pop($this->paths[$repositoryPath]),
                $this->paths[$repositoryPath]
            );
        }

        return $this->paths[$repositoryPath];
    }

    public function getResources($pattern)
    {

    }

    public function getTaggedResources($tag)
    {

    }
}
