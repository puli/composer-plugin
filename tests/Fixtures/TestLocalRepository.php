<?php

/*
 * This file is part of the Puli Composer Plugin.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Puli\Extension\Composer\Tests\Fixtures;

use Composer\Package\PackageInterface;
use Composer\Repository\WritableRepositoryInterface;

/**
 * @since  1.0
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class TestLocalRepository implements WritableRepositoryInterface
{
    private $packages = array();

    public function __construct(array $packages = array())
    {
        $this->packages = $packages;
    }

    public function setPackages(array $packages)
    {
        $this->packages = $packages;
    }

    public function hasPackage(PackageInterface $package)
    {

    }

    public function findPackage($name, $version)
    {

    }

    public function findPackages($name, $version = null)
    {

    }

    public function getPackages()
    {
        return $this->packages;
    }

    public function search($query, $mode = 0)
    {

    }

    public function count()
    {

    }

    public function write()
    {

    }

    public function addPackage(PackageInterface $package)
    {

    }

    public function removePackage(PackageInterface $package)
    {

    }

    public function getCanonicalPackages()
    {

    }

    public function reload()
    {

    }
}
