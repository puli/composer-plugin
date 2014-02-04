<?php

/*
 * This file is part of the Composer Puli Plugin package.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webmozart\Composer\PuliPlugin;

use Composer\Package\PackageInterface;
use Webmozart\Puli\LocatorDumper\PhpResourceLocatorDumper;
use Webmozart\Puli\Repository\ResourceRepository;

/**
 * @since  %%NextVersion%%
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class ComposerLocatorDumper
{
    /**
     * @var string
     */
    private $basePath;

    /**
     * @var string
     */
    private $vendorPath;

    /**
     * @var ResourceRepository
     */
    private $repository;

    public function __construct($basePath, $vendorPath)
    {
        $this->basePath = $basePath;
        $this->vendorPath = $vendorPath;
        $this->repository = new ResourceRepository();
    }

    public function addResources(PackageInterface $package, $packageRoot)
    {
        $name = $package->getName();
        $extra = $package->getExtra();

        if (!isset($extra['resources'])) {
            return;
        }

        $config = $extra['resources'];

        if (!isset($config['export'])) {
            return;
        }

        foreach ($config['export'] as $repositoryPath => $relativePaths) {
            foreach ((array) $relativePaths as $relativePath) {
                $absolutePath = $packageRoot.'/'.$relativePath;

                $this->repository->add($repositoryPath, $absolutePath);
            }
        }
    }

    public function dumpLocator($targetDir)
    {
        $dumper = new PhpResourceLocatorDumper();
        $dumper->dumpLocator($this->repository, $targetDir);
    }
}
