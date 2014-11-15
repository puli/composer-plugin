<?php

/*
 * This file is part of the Composer Puli Plugin.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Puli\Extension\Composer\PackageGraph;

/**
 * A directed, acyclic graph of package names.
 *
 * Packages can be added with {@link addPackage()}. Edges between these packages
 * can then be added using {@link addEdge()}. Both ends of an edge must have
 * been defined before the edge is added.
 *
 * ```php
 * $graph = new PackageGraph();
 * $graph->addPackage('acme/core');
 * $graph->addPackage('acme/blog');
 * $graph->addPackage('acme/blog-extension1');
 * $graph->addPackage('acme/blog-extension2');
 * $graph->addEdge('acme/core', 'acme/blog');
 * $graph->addEdge('acme/blog', 'acme/blog-extension1');
 * $graph->addEdge('acme/blog', 'acme/blog-extension2');
 * $graph->addEdge('acme/blog-extension1', 'acme/blog-extension2');
 * ```
 *
 * You can use {@link getPath()} and {@link hasPath()} to check whether a path
 * exists from one package to the other:
 *
 * ```php
 * // ...
 *
 * $graph->hasPath('acme/blog', 'acme/blog-extension1');
 * // => true
 *
 * $graph->hasPath('acme/blog-extension1', 'acme/blog-extension2');
 * // => false
 *
 * $graph->getPath('acme/core', 'acme/blog-extension2');
 * // => array('acme/core', 'acme/blog', 'acme/blog-extension2')
 * ```
 *
 * With {@link getSortedPackages()}, you can sort the packages such that the
 * dependencies defined via the edges are respected:
 *
 * ```php
 * // ...
 *
 * $graph->getSortedPackages();
 * // => array('acme/core', 'acme/blog', 'acme/blog-extension1', 'acme/blog-extension2')
 * ```
 *
 * @since  1.0
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class PackageGraph
{
    /**
     * Stores the names of all packages (vertices) as keys.
     *
     * @var array
     */
    private $packages = array();

    /**
     * Stores the edges in the keys of a multi-dimensional array.
     *
     * The first dimension stores the targets, the second dimension the origins
     * of the edges.
     *
     * @var array
     */
    private $edges = array();

    /**
     * Adds a package name to the graph.
     *
     * @param string $package The package name.
     *
     * @throws \InvalidArgumentException If the package name already exists.
     */
    public function addPackage($package)
    {
        if (isset($this->packages[$package])) {
            throw new \InvalidArgumentException(sprintf(
                'The package "%s" was added to the graph twice.',
                $package
            ));
        }

        $this->packages[$package] = true;
        $this->edges[$package] = array();
    }

    /**
     * Returns whether a package name exists in the graph.
     *
     * @param string $package The package name.
     *
     * @return bool Whether the package name exists.
     */
    public function hasPackage($package)
    {
        return isset($this->packages[$package]);
    }

    /**
     * Adds a directed edge from one to another package.
     *
     * @param string $fromPackage The origin package name.
     * @param string $toPackage   The target package name.
     *
     * @throws \InvalidArgumentException If any of the packages does not exist
     *                                   in the graph. Each package must have
     *                                   been added first.
     *
     * @throws CycleException If adding the edge would create a cycle.
     */
    public function addEdge($fromPackage, $toPackage)
    {
        if (!isset($this->packages[$fromPackage])) {
            throw new \InvalidArgumentException(sprintf(
                'The package "%s" does not exist in the graph.',
                $fromPackage
            ));
        }

        if (!isset($this->packages[$toPackage])) {
            throw new \InvalidArgumentException(sprintf(
                'The package "%s" does not exist in the graph.',
                $toPackage
            ));
        }

        if (null !== ($path = $this->getPath($toPackage, $fromPackage))) {
            $last = array_pop($path);

            throw new CycleException(sprintf(
                'A cyclic dependency was discovered between the packages "%s" '.
                'and "%s". Please check the "override" keys defined in these'.
                'packages.',
                implode('", "', $path),
                $last
            ));
        }

        $this->edges[$toPackage][$fromPackage] = true;
    }

    /**
     * Returns whether an edge exists between two packages.
     *
     * @param string $fromPackage The origin package name.
     * @param string $toPackage   The target package name.
     *
     * @return bool Whether an edge exists from the origin to the target package.
     */
    public function hasEdge($fromPackage, $toPackage)
    {
        return isset($this->edges[$toPackage][$fromPackage]);
    }

    /**
     * Returns whether a path exists from one to another package.
     *
     * @param string $fromPackage The origin package name.
     * @param string $toPackage   The target package name.
     *
     * @return bool Whether a path exists from the origin to the target package.
     */
    public function hasPath($fromPackage, $toPackage)
    {
        // does not exist in the graph
        if (!isset($this->edges[$toPackage])) {
            return false;
        }

        // adjacent node
        if (isset($this->edges[$toPackage][$fromPackage])) {
            return true;
        }

        // DFS
        foreach ($this->edges[$toPackage] as $predecessor => $_) {
            if ($this->hasPath($fromPackage, $predecessor)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the path from one to another package.
     *
     * @param string $fromPackage The origin package name.
     * @param string $toPackage   The target package name.
     *
     * @return string[]|null The path of package names or `null`, if no path
     *                       was found.
     */
    public function getPath($fromPackage, $toPackage)
    {
        if ($this->getPathDFS($fromPackage, $toPackage, $reversePath)) {
            return array_reverse($reversePath);
        }

        return null;
    }

    /**
     * Returns all packages in the graph.
     *
     * @return string All package names in the graph.
     */
    public function getPackages()
    {
        return $this->packages;
    }

    /**
     * Sorts packages according to the defined edges.
     *
     * The packages are sorted such that if two packages p1 and p2 have an edge
     * (p1, p2) in the graph, then p1 comes before p2 in the sorted set.
     *
     * If no packages are passed, all packages are sorted.
     *
     * @param string[] $packagesToSort The packages which should be sorted.
     *
     * @return string[] The sorted package names.
     *
     * @throws \InvalidArgumentException If any of the passed packages does not
     *                                   exist in the graph.
     */
    public function getSortedPackages(array $packagesToSort = array())
    {
        if (count($packagesToSort) > 0) {
            $packagesToSort = array_flip($packagesToSort);

            foreach ($packagesToSort as $package => $_) {
                if (!isset($this->packages[$package])) {
                    throw new \InvalidArgumentException(sprintf(
                        'The package "%s" does not exist in the graph.',
                        $package
                    ));
                }
            }
        } else {
            $packagesToSort = $this->packages;
        }

        $sorted = array();

        // Do a topologic sort
        // Start with any package and process until no more are left
        while (false !== reset($packagesToSort)) {
            $this->sortPackagesDFS(key($packagesToSort), $packagesToSort, $sorted);
        }

        return $sorted;
    }

    /**
     * Finds a path between packages using Depth-First Search.
     *
     * @param string $fromPackage The origin package name.
     * @param string $toPackage   The target package name.
     * @param array  $reversePath The path in reverse order.
     *
     * @return bool Whether a path was found.
     */
    private function getPathDFS($fromPackage, $toPackage, &$reversePath = array())
    {
        // does not exist in the graph
        if (!isset($this->edges[$toPackage])) {
            return false;
        }

        $reversePath[] = $toPackage;

        // adjacent node
        if (isset($this->edges[$toPackage][$fromPackage])) {
            $reversePath[] = $fromPackage;

            return true;
        }

        // DFS
        foreach ($this->edges[$toPackage] as $predecessor => $_) {
            if ($this->getPathDFS($fromPackage, $predecessor, $reversePath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Topologically sorts the given package name into the output array.
     *
     * The resulting array is sorted such that all predecessors of the package
     * come before the package (and their predecessors before them, and so on).
     *
     * @param string $package        The package to sort.
     * @param array  $packagesToSort The packages yet to be sorted.
     * @param array  $output         The output array.
     */
    private function sortPackagesDFS($package, array &$packagesToSort, array &$output)
    {
        unset($packagesToSort[$package]);

        // Before adding the package itself to the path, add all predecessors.
        // Do so recursively, then we make sure that each package is visited
        // in the path before any of its successors.
        foreach ($this->edges[$package] as $predecessor => $_) {
            // The package was already processed. Either the package is on the
            // path already, then we're good. Otherwise, we have a cycle.
            // However, addEdge() guarantees that the graph is cycle-free.
            if (isset($packagesToSort[$predecessor])) {
                $this->sortPackagesDFS($predecessor, $packagesToSort, $output);
            }
        }

        $output[] = $package;
    }
}
