<?php

/*
 * This file is part of the Composer Puli Plugin.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webmozart\Composer\PuliPlugin\RepositoryLoader;

use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Webmozart\Composer\PuliPlugin\Util\PathMatcher;
use Webmozart\Puli\Repository\ResourceRepository;
use Webmozart\Puli\Repository\ResourceRepositoryInterface;

/**
 * @since  1.0
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class RepositoryLoader
{
    /**
     * @var ResourceRepository
     */
    private $repository;

    /**
     * @var PathMatcher
     */
    private $pathMatcher;

    /**
     * @var array[]
     */
    private $overrides = array();

    /**
     * @var array[]
     */
    private $overrideOrder = array();

    /**
     * @var array[]
     */
    private $conflictingPackages = array();

    /**
     * @var array[]
     */
    private $conflictingPaths = array();

    public function __construct(ResourceRepositoryInterface $repository)
    {
        $this->repository = $repository;
        $this->pathMatcher = new PathMatcher();
    }

    public function loadPackage(PackageInterface $package, $packageRoot)
    {
        $packageName = $package->getName();
        $extra = $package->getExtra();

        if (!isset($extra['resources'])) {
            return;
        }

        $config = $extra['resources'];

        if (isset($config['export'])) {
            if (!is_array($config['export'])) {
                throw new ResourceDefinitionException(sprintf(
                    'The "export" key in the composer.json of the "%s" '.
                    'package should contain an array.',
                    $packageName
                ));
            }

            $this->processExports($config['export'], $packageName, $packageRoot);
        }

        if (isset($config['override'])) {
            if (!is_array($config['override'])) {
                throw new ResourceDefinitionException(sprintf(
                    'The "override" key in the composer.json of the "%s" '.
                    'package should contain an array.',
                    $packageName
                ));
            }

            $this->processOverrides($config['override'], $packageName, $packageRoot);
        }

        if (isset($config['override-order']) && $package instanceof RootPackageInterface) {
            if (!is_array($config['override-order'])) {
                throw new ResourceDefinitionException(
                    'The "override-order" key in the composer.json of the root '.
                    'package should contain an array.'
                );
            }

            $this->processOverrideOrder($config['override-order']);
        }
    }

    public function validateOverrides()
    {
        foreach ($this->conflictingPackages as $repositoryPath => $packageNames) {
            // Check whether the override order was defined for this or a base
            // path
            foreach ($this->overrideOrder as $rulePath => $packageOrder) {
                // If the override order was defined for that path, suppress the
                // exception
                if ($this->pathMatcher->isBasePath($rulePath, $repositoryPath)) {
                    // Suppress conflict exception
                    continue 2;
                }
            }

            // If the override order was defined, apply the overrides in that
            // order
            // The package names are stored in the keys to prevent duplicates
            $packageNames = array_keys($packageNames);

            // Normalize the package order
            sort($packageNames);

            // Prepare for proper wording
            $lastPackageName = array_pop($packageNames);

            throw new OverrideConflictException(sprintf(
                'The packages "%s" and "%s" tried to override the '.
                'same path "%s". Both overrides were disabled. You '.
                'can fix this problem by adding that path to '.
                'the "override-order" key in your root '.
                'composer.json.',
                implode('", "', $packageNames),
                $lastPackageName,
                $repositoryPath
            ));
        }
    }

    public function applyOverrides()
    {
        // Override shorter paths before more specific paths
        ksort($this->overrides);

        // Apply paths without conflicts first
        foreach ($this->overrides as $repositoryPath => $pathsByPackage) {
            if (isset($this->conflictingPaths[$repositoryPath])) {
                continue;
            }

            foreach ($pathsByPackage as $absolutePaths) {
                foreach ($absolutePaths as $absolutePath) {
                    $this->repository->add($repositoryPath, $absolutePath);
                }
            }
        }

        // Check conflicting paths and map them to an override order rule, if
        // possible. The resulting array will have paths of the
        // "override-order" definition as keys and paths of the "override"
        // definitions in the various packages as values, for example:
        //
        // array(
        //     '/acme/demo' => array(
        //         '/acme/demo',
        //         '/acme/demo/js',
        //     ),
        //     '/acme/demo/css' => array(
        //         '/acme/demo/css',
        //     ),
        // )
        //
        // Later on, this mapping is used to add all the overrides in the
        // correct order. For example, assume that the override order is
        // defined as:
        //
        // array(
        //     '/acme/demo' => array(
        //         'vendor1/package1',
        //         'vendor2/package2',
        //     ),
        //     '/acme/demo/css' => array(
        //         'vendor2/package2',
        //         'vendor1/package1',
        //     ),
        // )
        //
        // Then:
        //
        // 1. Rules with shorter paths ("/acme/demo") are applied before rules
        //    with longer paths ("/acme/demo/css")
        // 2. For each rule, all packages are traversed
        // 3. For each package, all conflicting paths are added, again shorter
        //    paths before longer paths.
        //
        // In the above example, the overrides will be applied in this order:
        //
        // 1. Overrides for "/acme/demo" in "vendor1/package1"
        // 2. Overrides for "/acme/demo/js" in "vendor1/package1"
        // 3. Overrides for "/acme/demo" in "vendor2/package2"
        // 4. Overrides for "/acme/demo/js" in "vendor2/package2"
        // 5. Overrides for "/acme/demo/css" in "vendor2/package2"
        // 6. Overrides for "/acme/demo/css" in "vendor1/package1"

        $conflictingPathsByRulePaths = array();

        // Initialize array
        foreach ($this->overrideOrder as $rulePath => $packageOrder) {
            $conflictingPathsByRulePaths[$rulePath] = array();
        }

        // Compare rules with longer paths before rules with shorter paths
        // For example, if order rules exist for both "/acme/demo" and
        // "/acme/demo/css", the conflicting path "/acme/demo/css/style.css"
        // should use the rule for the longer (more specific) path
        // "/acme/demo/css".
        krsort($this->overrideOrder);

        // Add conflicting paths to the most specific rule path
        foreach ($this->conflictingPaths as $conflictingPath => $_) {
            foreach ($this->overrideOrder as $rulePath => $packageOrder) {
                if (!$this->pathMatcher->isBasePath($rulePath, $conflictingPath)) {
                    continue;
                }

                $conflictingPathsByRulePaths[$rulePath][] = $conflictingPath;

                break;
            }
        }

        // Sort the map so that the overrides for shorter rules are applied
        // before those for longer rules. For example, if order rules exist
        // for both "/acme/demo" and "/acme/demo/css", all paths matching
        // "/acme/demo" should be applied first in the defined order, then the
        // paths matching "/acme/demo/css".
        ksort($conflictingPathsByRulePaths);

        foreach ($conflictingPathsByRulePaths as $rulePath => $conflictingPaths) {
            // Traverse the defined packages in the specified order
            foreach ($this->overrideOrder[$rulePath] as $packageName) {
                // Add all matching conflicting paths for that package at once
                foreach ($conflictingPaths as $conflictingPath) {
                    if (!isset($this->overrides[$conflictingPath][$packageName])) {
                        // error, package does not define resources for this
                        // path
                        continue;
                    }

                    foreach ($this->overrides[$conflictingPath][$packageName] as $absolutePath) {
                        $this->repository->add($conflictingPath, $absolutePath);
                    }
                }
            }
        }
    }

    /**
     * @param array $exports
     * @param       $packageName
     *
     * @param       $packageRoot
     *
     * @throws ResourceDefinitionException
     */
    private function processExports(array $exports, $packageName, $packageRoot)
    {
        // Export shorter paths before longer paths
        ksort($exports);

        foreach ($exports as $repositoryPath => $relativePaths) {
            foreach ((array)$relativePaths as $relativePath) {
                if ('__root__' !== $packageName && 0 !== strpos($repositoryPath, '/'.$packageName)) {
                    throw new ResourceDefinitionException(sprintf(
                        'Resources exported by the "%s" plugin must have the '.
                        'prefix "/%s". This is not the case for the resource '.
                        '"%s".',
                        $packageName,
                        $packageName,
                        $repositoryPath
                    ));
                }

                $absolutePath = $packageRoot.'/'.$relativePath;

                $this->repository->add($repositoryPath, $absolutePath);
            }
        }
    }

    /**
     * @param array $overrides
     * @param $packageName
     * @param $packageRoot
     */
    private function processOverrides(array $overrides, $packageName, $packageRoot)
    {
        foreach ($overrides as $repositoryPath => $relativePaths) {
            // Detect override conflicts
            $this->detectConflicts($repositoryPath, $packageName);

            $absolutePaths = array();

            foreach ((array)$relativePaths as $relativePath) {
                $absolutePaths[] = $packageRoot . '/' . $relativePath;
            }

            if (!isset($this->overrides[$repositoryPath])) {
                $this->overrides[$repositoryPath] = array();
            }

            $this->overrides[$repositoryPath][$packageName] = $absolutePaths;
        }
    }

    /**
     * @param array $overrideOrder
     */
    private function processOverrideOrder(array $overrideOrder)
    {
        foreach ($overrideOrder as $repositoryPath => $packageOrder) {
            if (!is_array($packageOrder)) {
                // error
            }

            $this->overrideOrder[$repositoryPath] = $packageOrder;
        }
    }

    /**
     * @param $repositoryPath
     * @param $packageName
     *
     * @return array
     */
    private function detectConflicts($repositoryPath, $packageName)
    {
        // Check whether that repository path was already overridden
        // by another package
        foreach ($this->overrides as $overriddenPath => $pathsByPackage) {
            foreach ($pathsByPackage as $overridingPackage => $absolutePaths) {
                // Ignore the entries created by this package
                if ($overridingPackage === $packageName) {
                    continue;
                }

                $commonBasePath = $this->pathMatcher->getCommonBasePath(
                    $overriddenPath,
                    $repositoryPath
                );

                // If the common base path is "/" or "/<vendor-name>",
                // proceed. Otherwise throw exception.
                if ('/' !== dirname($commonBasePath)) {
                    // Remember the conflict for the common base path
                    if (!isset($this->conflictingPackages[$commonBasePath])) {
                        $this->conflictingPackages[$commonBasePath] = array();
                    }

                    $this->conflictingPackages[$commonBasePath][$packageName] = true;
                    $this->conflictingPackages[$commonBasePath][$overridingPackage] = true;

                    // Remember the key of $this->overrides in order to skip
                    // it, unless an order has been defined for the common
                    // base path
                    $this->conflictingPaths[$overriddenPath] = true;
                }
            }
        }
    }
}
