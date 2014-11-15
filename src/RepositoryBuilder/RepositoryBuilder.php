<?php

/*
 * This file is part of the Composer Puli Plugin.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Puli\Extension\Composer\RepositoryBuilder;

use Composer\Package\AliasPackage;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Puli\Extension\Composer\PackageGraph\PackageGraph;
use Puli\Filesystem\Resource\LocalDirectoryResource;
use Puli\Filesystem\Resource\LocalFileResource;
use Puli\Filesystem\Resource\LocalResourceInterface;
use Puli\Repository\ManageableRepositoryInterface;
use Puli\Resource\DirectoryResourceInterface;

/**
 * @since  1.0
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class RepositoryBuilder
{
    /**
     * @var PackageGraph
     */
    private $packageGraph;

    /**
     * @var array[]
     */
    private $packageOverrides = array();

    /**
     * @var array[]
     */
    private $resources = array();

    /**
     * @var array[]
     */
    private $knownPaths = array();

    /**
     * @var array[]
     */
    private $tags = array();

    public function __construct()
    {
        $this->packageGraph = new PackageGraph();
    }

    public function loadPackage(PackageInterface $package, $packageRoot)
    {
        // We don't care about aliases, only "the real deal"
        if ($package instanceof AliasPackage) {
            return;
        }

        $packageName = $package->getName();
        $config = $package->getExtra();

        $this->packageGraph->addPackage($packageName);

        if (isset($config['resources'])) {
            if (!is_array($config['resources'])) {
                throw new ResourceDefinitionException(sprintf(
                    'The "export" key in the composer.json of the "%s" '.
                    'package should contain an array.',
                    $packageName
                ));
            }

            $this->processResources($config['resources'], $packageName, $packageRoot);
        }

        if (isset($config['override'])) {
            if (!is_array($config['override']) && !is_string($config['override'])) {
                throw new ResourceDefinitionException(sprintf(
                    'The "override" key in the composer.json of the "%s" '.
                    'package should contain a string or an array.',
                    $packageName
                ));
            }

            $this->processOverrides((array) $config['override'], $packageName);
        }

        if (isset($config['package-order']) && $package instanceof RootPackageInterface) {
            if (!is_array($config['package-order'])) {
                throw new ResourceDefinitionException(sprintf(
                    'The "package-order" key in the composer.json of the "%s" '.
                    'package should contain an array.',
                    $packageName
                ));
            }

            $this->processPackageOrder($config['package-order']);
        }

        if (isset($config['resource-tags'])) {
            if (!is_array($config['resource-tags'])) {
                throw new ResourceDefinitionException(sprintf(
                    'The "resource-tags" key in the composer.json of the "%s" '.
                    'package should contain an array.',
                    $packageName
                ));
            }

            $this->processTags($config['resource-tags']);
        }
    }

    public function buildRepository(ManageableRepositoryInterface $repo)
    {
        $this->buildPackageGraph();
        $this->detectConflicts();
        $this->addResources($repo);
        $this->tagResources($repo);

        return $repo;
    }

    /**
     * @param array $resources
     * @param       $currentPackageName
     *
     * @param       $packageRoot
     */
    private function processResources(array $resources, $currentPackageName, $packageRoot)
    {
        // Export shorter paths before longer paths
        ksort($resources);

        if (!isset($this->resources[$currentPackageName])) {
            $this->resources[$currentPackageName] = array();
        }

        foreach ($resources as $path => $relativePaths) {
            if (!isset($this->resources[$currentPackageName][$path])) {
                $this->resources[$currentPackageName][$path] = array();
            }

            foreach ((array) $relativePaths as $relativePath) {
                $absolutePath = $packageRoot.'/'.$relativePath;

                $resource = is_dir($absolutePath)
                    ? new LocalDirectoryResource($absolutePath)
                    : new LocalFileResource($absolutePath);

                // Packages can set a repository path to multiple local paths
                $this->resources[$currentPackageName][$path][] = $resource;

                // Store information necessary to detect conflicts later
                $this->prepareConflictDetection($path, $resource, $currentPackageName);
            }
        }
    }

    /**
     * @param                        $path
     * @param LocalResourceInterface $resource
     * @param                        $currentPackageName
     */
    private function prepareConflictDetection($path, LocalResourceInterface $resource, $currentPackageName)
    {
        if (!isset($this->knownPaths[$path])) {
            $this->knownPaths[$path] = array();
        }

        $this->knownPaths[$path][$currentPackageName] = true;

        // Detect conflicts in sub-directories
        if ($resource instanceof DirectoryResourceInterface) {
            $basePath = rtrim($path, '/').'/';
            foreach ($resource->listEntries() as $entry) {
                $this->prepareConflictDetection($basePath.basename($entry->getLocalPath()), $entry, $currentPackageName);
            }
        }
    }

    private function processOverrides(array $overrides, $packageName)
    {
        if (!isset($this->packageOverrides[$packageName])) {
            $this->packageOverrides[$packageName] = array();
        }

        foreach ($overrides as $override) {
            $this->packageOverrides[$packageName][] = $override;
        }
    }

    /**
     * @param array $packageOrder
     */
    private function processPackageOrder(array $packageOrder)
    {
        // Make sure we have numeric, ascending keys here
        $packageOrder = array_values($packageOrder);

        // Each package overrides the previous one in the list
        for ($i = 1, $l = count($packageOrder); $i < $l; ++$i) {
            if (!isset($this->packageOverrides[$packageOrder[$i]])) {
                $this->packageOverrides[$packageOrder[$i]] = array();
            }

            $this->packageOverrides[$packageOrder[$i]][] = $packageOrder[$i - 1];
        }
    }

    private function processTags(array $tags)
    {
        foreach ($tags as $repositoryPath => $pathTags) {
            if (!isset($this->tags[$repositoryPath])) {
                $this->tags[$repositoryPath] = array();
            }

            foreach ((array) $pathTags as $tag) {
                // Store tags as keys to prevent duplicates
                $this->tags[$repositoryPath][$tag] = true;
            }
        }
    }

    private function buildPackageGraph()
    {
        foreach ($this->packageOverrides as $overridingPackage => $overriddenPackages) {
            foreach ($overriddenPackages as $overriddenPackage) {
                // The overridden package must be processed before the
                // overriding package
                // Check that the overridden package is actually loaded TODO test
                if ($this->packageGraph->hasPackage($overriddenPackage)) {
                    $this->packageGraph->addEdge($overriddenPackage, $overridingPackage);
                }
            }
        }

        // Free unneeded space
        unset($this->packageOverrides);
    }

    private function detectConflicts()
    {
        // Check whether any of the paths were registered by more than one
        // package and if yes, check if the order between the packages is
        // defined
        foreach ($this->knownPaths as $path => $packageNames) {
            // Attention, the package names are stored in the keys
            if (1 === count($packageNames)) {
                continue;
            }

            $orderedPackages = $this->packageGraph->getSortedPackages(array_keys($packageNames));

            // An edge must exist between each package pair in the sorted set,
            // otherwise the dependencies are not sufficiently defined
            for ($i = 1, $l = count($orderedPackages); $i < $l; ++$i) {
                if (!$this->packageGraph->hasEdge($orderedPackages[$i - 1], $orderedPackages[$i])) {
                    throw new ResourceConflictException(sprintf(
                        'The packages "%s" and "%s" add resources for the same '.
                        'path "%s", but have no override order defined '.
                        "between them.\n\nResolutions:\n\n(1) Add the key ".
                        '"override" to the composer.json of one package and '.
                        "set its value to the other package name.\n(2) Add the ".
                        'key "override-order" to the composer.json of the root '.
                        'package and define the order of the packages there.',
                        $orderedPackages[$i - 1],
                        $orderedPackages[$i],
                        $path
                    ));
                }
            }
        }
    }

    private function addResources(ManageableRepositoryInterface $repo)
    {
        $packageOrder = $this->packageGraph->getSortedPackages();

        foreach ($packageOrder as $packageName) {
            if (!isset($this->resources[$packageName])) {
                continue;
            }

            foreach ($this->resources[$packageName] as $path => $resources) {
                foreach ($resources as $resource) {
                    $repo->add($path, $resource);
                }
            }
        }
    }

    private function tagResources(ManageableRepositoryInterface $repo)
    {
        foreach ($this->tags as $path => $tags) {
            foreach ($tags as $tag => $_) {
                $repo->tag($path, $tag);
            }
        }
    }
}
