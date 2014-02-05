<?php

/*
 * This file is part of the Composer Puli Plugin.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webmozart\Composer\PuliPlugin\Tests\RepositoryLoader;

use Webmozart\Composer\PuliPlugin\RepositoryLoader\RepositoryLoader;

/**
 * @since  1.0
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class RepositoryLoaderTest extends \PHPUnit_Framework_TestCase
{
    const PACKAGE_ROOT = '/path/to/package';

    const OTHER_PACKAGE_ROOT = '/other/package/path';

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject
     */
    private $repo;

    /**
     * @var RepositoryLoader
     */
    private $loader;

    protected function setUp()
    {
        $this->repo = $this->getMock('\Webmozart\Puli\Repository\ResourceRepositoryInterface');
        $this->loader = new RepositoryLoader($this->repo);
    }

    public function testIgnorePackageWithoutExtras()
    {
        $this->repo->expects($this->never())
            ->method('add');

        $package = $this->createPackage(array());

        $this->loader->loadPackage($package, self::PACKAGE_ROOT);
    }

    public function testIgnorePackageWithoutResources()
    {
        $this->repo->expects($this->never())
            ->method('add');

        $package = $this->createPackage(array(
            'extra' => array(
            ),
        ));

        $this->loader->loadPackage($package, self::PACKAGE_ROOT);
    }

    public function testExport()
    {
        $this->repo->expects($this->at(0))
            ->method('add')
            ->with('/acme/package', self::PACKAGE_ROOT.'/resources');

        $this->repo->expects($this->at(1))
            ->method('add')
            ->with('/acme/package/css', self::PACKAGE_ROOT.'/assets/css');

        $package = $this->createPackage(array(
            'name' => 'acme/package',
            'extra' => array(
                'resources' => array(
                    'export' => array(
                        '/acme/package' => 'resources',
                        '/acme/package/css' => 'assets/css',
                    ),
                ),
            ),
        ));

        $this->loader->loadPackage($package, self::PACKAGE_ROOT);
    }

    public function testExportIgnoresOrder()
    {
        $this->repo->expects($this->at(0))
            ->method('add')
            ->with('/acme/package', self::PACKAGE_ROOT.'/resources');

        $this->repo->expects($this->at(1))
            ->method('add')
            ->with('/acme/package/css', self::PACKAGE_ROOT.'/assets/css');

        $package = $this->createPackage(array(
            'name' => 'acme/package',
            'extra' => array(
                'resources' => array(
                    'export' => array(
                        '/acme/package/css' => 'assets/css',
                        '/acme/package' => 'resources',
                    ),
                ),
            ),
        ));

        $this->loader->loadPackage($package, self::PACKAGE_ROOT);
    }

    public function testExportMultiplePaths()
    {
        $this->repo->expects($this->at(0))
            ->method('add')
            ->with('/acme/package', self::PACKAGE_ROOT.'/resources');

        $this->repo->expects($this->at(1))
            ->method('add')
            ->with('/acme/package', self::PACKAGE_ROOT.'/assets');

        $package = $this->createPackage(array(
            'name' => 'acme/package',
            'extra' => array(
                'resources' => array(
                    'export' => array(
                        '/acme/package' => array('resources', 'assets'),
                    ),
                ),
            ),
        ));

        $this->loader->loadPackage($package, self::PACKAGE_ROOT);
    }

    /**
     * @expectedException \Webmozart\Composer\PuliPlugin\RepositoryLoader\ResourceDefinitionException
     */
    public function testExportExpectsPackageNameAsBasePath()
    {
        $package = $this->createPackage(array(
            'name' => 'acme/package',
            'extra' => array(
                'resources' => array(
                    'export' => array(
                        '/foo/bar' => 'resources',
                    ),
                ),
            ),
        ));

        $this->loader->loadPackage($package, self::PACKAGE_ROOT);
    }

    /**
     * @expectedException \Webmozart\Composer\PuliPlugin\RepositoryLoader\ResourceDefinitionException
     */
    public function testExportDoesNotAcceptStringPrefixes()
    {
        $package = $this->createPackage(array(
            'name' => 'acme/package',
            'extra' => array(
                'resources' => array(
                    'export' => array(
                        '/acme/package-but-is-it-though' => 'resources',
                    ),
                ),
            ),
        ));

        $this->loader->loadPackage($package, self::PACKAGE_ROOT);
    }

    /**
     * @expectedException \Webmozart\Composer\PuliPlugin\RepositoryLoader\ResourceDefinitionException
     */
    public function testExportExpectsPackageNameAsBasePathForRoot()
    {
        $package = $this->createRootPackage(array(
            'name' => 'acme/package',
            'extra' => array(
                'resources' => array(
                    'export' => array(
                        '/foo/bar' => 'resources',
                    ),
                ),
            ),
        ));

        $this->loader->loadPackage($package, self::PACKAGE_ROOT);
    }

    public function testExportDoesNotExpectPackageNameAsBasePathIfNameNotSetForRoot()
    {
        $package = $this->createRootPackage(array(
            'extra' => array(
                'resources' => array(
                    'export' => array(
                        '/foo/bar' => 'resources',
                    ),
                ),
            ),
        ));

        $this->loader->loadPackage($package, self::PACKAGE_ROOT);
    }

    public function testOverrideExistingPackage()
    {
        $this->repo->expects($this->at(0))
            ->method('add')
            ->with('/acme/overridden', self::PACKAGE_ROOT.'/overridden');

        $this->repo->expects($this->at(1))
            ->method('add')
            ->with('/acme/overridden/css', self::PACKAGE_ROOT.'/css');

        $this->repo->expects($this->at(2))
            ->method('add')
            ->with('/acme/overridden', self::OTHER_PACKAGE_ROOT.'/override');

        $this->repo->expects($this->at(3))
            ->method('add')
            ->with('/acme/overridden/css', self::OTHER_PACKAGE_ROOT.'/css-override');

        $overridingPackage = $this->createPackage(array(
            'name' => 'acme/package',
            'extra' => array(
                'resources' => array(
                    'override' => array(
                        '/acme/overridden' => 'override',
                        '/acme/overridden/css' => 'css-override',
                    ),
                ),
            ),
        ));

        $overriddenPackage = $this->createPackage(array(
            'name' => 'acme/overridden',
            'extra' => array(
                'resources' => array(
                    'export' => array(
                        '/acme/overridden' => 'overridden',
                        '/acme/overridden/css' => 'css',
                    ),
                ),
            ),
        ));

        // Load overridden package first
        $this->loader->loadPackage($overriddenPackage, self::PACKAGE_ROOT);
        $this->loader->loadPackage($overridingPackage, self::OTHER_PACKAGE_ROOT);
        $this->loader->validateOverrides();
        $this->loader->applyOverrides();
    }

    public function testOverrideFuturePackage()
    {
        $this->repo->expects($this->at(0))
            ->method('add')
            ->with('/acme/overridden', self::PACKAGE_ROOT.'/overridden');

        $this->repo->expects($this->at(1))
            ->method('add')
            ->with('/acme/overridden', self::OTHER_PACKAGE_ROOT.'/override');

        $overridingPackage = $this->createPackage(array(
            'name' => 'acme/package',
            'extra' => array(
                'resources' => array(
                    'override' => array(
                        '/acme/overridden' => 'override',
                    ),
                ),
            ),
        ));

        $overriddenPackage = $this->createPackage(array(
            'name' => 'acme/overridden',
            'extra' => array(
                'resources' => array(
                    'export' => array(
                        '/acme/overridden' => 'overridden',
                    ),
                ),
            ),
        ));

        // Load overridden package last
        $this->loader->loadPackage($overridingPackage, self::OTHER_PACKAGE_ROOT);
        $this->loader->loadPackage($overriddenPackage, self::PACKAGE_ROOT);
        $this->loader->validateOverrides();
        $this->loader->applyOverrides();
    }

    public function testOverrideNonExistingPackage()
    {
        $this->repo->expects($this->at(0))
            ->method('add')
            ->with('/acme/overridden', self::OTHER_PACKAGE_ROOT.'/override');

        $overridingPackage = $this->createPackage(array(
            'name' => 'acme/package',
            'extra' => array(
                'resources' => array(
                    'override' => array(
                        '/acme/overridden' => 'override',
                    ),
                ),
            ),
        ));

        $this->loader->loadPackage($overridingPackage, self::OTHER_PACKAGE_ROOT);
        $this->loader->validateOverrides();
        $this->loader->applyOverrides();
    }

    public function testOverrideWithMultipleDirectories()
    {
        $this->repo->expects($this->at(0))
            ->method('add')
            ->with('/acme/overridden', self::PACKAGE_ROOT.'/overridden');

        $this->repo->expects($this->at(1))
            ->method('add')
            ->with('/acme/overridden', self::OTHER_PACKAGE_ROOT.'/override1');

        $this->repo->expects($this->at(2))
            ->method('add')
            ->with('/acme/overridden', self::OTHER_PACKAGE_ROOT.'/override2');

        $overridingPackage = $this->createPackage(array(
            'name' => 'acme/package',
            'extra' => array(
                'resources' => array(
                    'override' => array(
                        '/acme/overridden' => array('override1', 'override2'),
                    ),
                ),
            ),
        ));

        $overriddenPackage = $this->createPackage(array(
            'name' => 'acme/overridden',
            'extra' => array(
                'resources' => array(
                    'export' => array(
                        '/acme/overridden' => 'overridden',
                    ),
                ),
            ),
        ));

        $this->loader->loadPackage($overridingPackage, self::OTHER_PACKAGE_ROOT);
        $this->loader->loadPackage($overriddenPackage, self::PACKAGE_ROOT);
        $this->loader->validateOverrides();
        $this->loader->applyOverrides();
    }

    public function testMultipleOverrides()
    {
        $this->repo->expects($this->at(0))
            ->method('add')
            ->with('/acme/overridden', self::PACKAGE_ROOT.'/override');

        $this->repo->expects($this->at(1))
            ->method('add')
            ->with('/acme/overridden/css', self::PACKAGE_ROOT.'/css');

        $overridingPackage = $this->createPackage(array(
            'name' => 'acme/package',
            'extra' => array(
                'resources' => array(
                    'override' => array(
                        '/acme/overridden' => 'override',
                        '/acme/overridden/css' => 'css',
                    ),
                ),
            ),
        ));

        $this->loader->loadPackage($overridingPackage, self::PACKAGE_ROOT);
        $this->loader->validateOverrides();
        $this->loader->applyOverrides();
    }

    public function testMultipleOverridesIgnoreOrder()
    {
        $this->repo->expects($this->at(0))
            ->method('add')
            ->with('/acme/overridden', self::PACKAGE_ROOT.'/override');

        $this->repo->expects($this->at(1))
            ->method('add')
            ->with('/acme/overridden/css', self::PACKAGE_ROOT.'/css');

        $overridingPackage = $this->createPackage(array(
            'name' => 'acme/package',
            'extra' => array(
                'resources' => array(
                    'override' => array(
                        '/acme/overridden/css' => 'css',
                        '/acme/overridden' => 'override',
                    ),
                ),
            ),
        ));

        $this->loader->loadPackage($overridingPackage, self::PACKAGE_ROOT);
        $this->loader->validateOverrides();
        $this->loader->applyOverrides();
    }

    /**
     * @expectedException \Webmozart\Composer\PuliPlugin\RepositoryLoader\OverrideConflictException
     */
    public function testMultipleOverridesConflictForSamePath()
    {
        $this->repo->expects($this->never())
            ->method('add');

        $overridingPackage1 = $this->createPackage(array(
            'name' => 'acme/package-1',
            'extra' => array(
                'resources' => array(
                    'override' => array(
                        '/acme/overridden' => 'override-1',
                    ),
                ),
            ),
        ));

        $overridingPackage2 = $this->createPackage(array(
            'name' => 'acme/package-2',
            'extra' => array(
                'resources' => array(
                    'override' => array(
                        '/acme/overridden' => 'override-2',
                    ),
                ),
            ),
        ));

        $this->loader->loadPackage($overridingPackage1, self::PACKAGE_ROOT);
        $this->loader->loadPackage($overridingPackage2, self::OTHER_PACKAGE_ROOT);
        $this->loader->validateOverrides();
    }

    /**
     * @expectedException \Webmozart\Composer\PuliPlugin\RepositoryLoader\OverrideConflictException
     */
    public function testMultipleOverridesConflictIfOverrideOrderDefinedForSubPath()
    {
        $this->repo->expects($this->never())
            ->method('add');

        $rootPackage = $this->createRootPackage(array(
            'extra' => array(
                'resources' => array(
                    'override-order' => array(
                        // Rule does not match -> ignored
                        '/acme/overridden/css' => array(
                            'acme/package-2',
                            'acme/package-1',
                        ),
                    ),
                ),
            ),
        ));

        $overridingPackage1 = $this->createPackage(array(
            'name' => 'acme/package-1',
            'extra' => array(
                'resources' => array(
                    'override' => array(
                        '/acme/overridden' => 'override-1',
                    ),
                ),
            ),
        ));

        $overridingPackage2 = $this->createPackage(array(
            'name' => 'acme/package-2',
            'extra' => array(
                'resources' => array(
                    'override' => array(
                        '/acme/overridden' => 'override-2',
                    ),
                ),
            ),
        ));

        $this->loader->loadPackage($rootPackage, '/');
        $this->loader->loadPackage($overridingPackage1, self::PACKAGE_ROOT);
        $this->loader->loadPackage($overridingPackage2, self::OTHER_PACKAGE_ROOT);
        $this->loader->validateOverrides();
    }

    /**
     * @expectedException \Webmozart\Composer\PuliPlugin\RepositoryLoader\OverrideConflictException
     */
    public function testMultipleOverridesConflictFirstNestedPath()
    {
        $this->repo->expects($this->never())
            ->method('add');

        $overridingPackage1 = $this->createPackage(array(
            'name' => 'acme/package-1',
            'extra' => array(
                'resources' => array(
                    'override' => array(
                        '/acme/overridden/css' => 'override-1',
                    ),
                ),
            ),
        ));

        $overridingPackage2 = $this->createPackage(array(
            'name' => 'acme/package-2',
            'extra' => array(
                'resources' => array(
                    'override' => array(
                        '/acme/overridden' => 'override-2',
                    ),
                ),
            ),
        ));

        $this->loader->loadPackage($overridingPackage1, self::PACKAGE_ROOT);
        $this->loader->loadPackage($overridingPackage2, self::OTHER_PACKAGE_ROOT);
        $this->loader->validateOverrides();
    }

    /**
     * @expectedException \Webmozart\Composer\PuliPlugin\RepositoryLoader\OverrideConflictException
     */
    public function testMultipleOverridesConflictSecondNestedPath()
    {
        $this->repo->expects($this->never())
            ->method('add');

        $overridingPackage1 = $this->createPackage(array(
            'name' => 'acme/package-1',
            'extra' => array(
                'resources' => array(
                    'override' => array(
                        '/acme/overridden' => 'override-1',
                    ),
                ),
            ),
        ));

        $overridingPackage2 = $this->createPackage(array(
            'name' => 'acme/package-2',
            'extra' => array(
                'resources' => array(
                    'override' => array(
                        '/acme/overridden/css' => 'override-2',
                    ),
                ),
            ),
        ));

        $this->loader->loadPackage($overridingPackage1, self::PACKAGE_ROOT);
        $this->loader->loadPackage($overridingPackage2, self::OTHER_PACKAGE_ROOT);
        $this->loader->validateOverrides();
    }

    public function testMultipleOverridesAllowedForCommonVendor()
    {
        $this->repo->expects($this->at(0))
            ->method('add')
            ->with('/acme/overridden-1', self::PACKAGE_ROOT.'/override-1');

        $this->repo->expects($this->at(1))
            ->method('add')
            ->with('/acme/overridden-2', self::OTHER_PACKAGE_ROOT.'/override-2');

        $overridingPackage1 = $this->createPackage(array(
            'name' => 'acme/package-1',
            'extra' => array(
                'resources' => array(
                    'override' => array(
                        '/acme/overridden-1' => 'override-1',
                    ),
                ),
            ),
        ));

        $overridingPackage2 = $this->createPackage(array(
            'name' => 'acme/package-2',
            'extra' => array(
                'resources' => array(
                    'override' => array(
                        '/acme/overridden-2' => 'override-2',
                    ),
                ),
            ),
        ));

        $this->loader->loadPackage($overridingPackage1, self::PACKAGE_ROOT);
        $this->loader->loadPackage($overridingPackage2, self::OTHER_PACKAGE_ROOT);
        $this->loader->validateOverrides();
        $this->loader->applyOverrides();
    }

    public function testConflictingOverridesAreNotApplied()
    {
        $this->repo->expects($this->never())
            ->method('add');

        $overridingPackage1 = $this->createPackage(array(
            'name' => 'acme/package-1',
            'extra' => array(
                'resources' => array(
                    'override' => array(
                        '/acme/overridden' => 'override-1',
                    ),
                ),
            ),
        ));

        $overridingPackage2 = $this->createPackage(array(
            'name' => 'acme/package-2',
            'extra' => array(
                'resources' => array(
                    'override' => array(
                        '/acme/overridden' => 'override-2',
                    ),
                ),
            ),
        ));

        $this->loader->loadPackage($overridingPackage1, self::PACKAGE_ROOT);
        $this->loader->loadPackage($overridingPackage2, self::OTHER_PACKAGE_ROOT);

        // none of the overrides should be applied
        $this->loader->applyOverrides();
    }

    public function testMultipleConflictingOverridesAreNotApplied()
    {
        $this->repo->expects($this->never())
            ->method('add');

        $overridingPackage1 = $this->createPackage(array(
            'name' => 'acme/package-1',
            'extra' => array(
                'resources' => array(
                    'override' => array(
                        '/acme/overridden' => 'override-1',
                        '/acme/overridden/css' => 'css-1',
                    ),
                ),
            ),
        ));

        $overridingPackage2 = $this->createPackage(array(
            'name' => 'acme/package-2',
            'extra' => array(
                'resources' => array(
                    'override' => array(
                        '/acme/overridden' => 'override-2',
                        '/acme/overridden/css' => 'css-2',
                    ),
                ),
            ),
        ));

        $this->loader->loadPackage($overridingPackage1, self::PACKAGE_ROOT);
        $this->loader->loadPackage($overridingPackage2, self::OTHER_PACKAGE_ROOT);

        // none of the overrides should be applied
        $this->loader->applyOverrides();
    }

    public function testDefineOverrideOrder()
    {
        $this->repo->expects($this->at(0))
            ->method('add')
            ->with('/acme/overridden', self::OTHER_PACKAGE_ROOT.'/override-2');

        $this->repo->expects($this->at(1))
            ->method('add')
            ->with('/acme/overridden', self::PACKAGE_ROOT.'/override-1');

        $rootPackage = $this->createRootPackage(array(
            'extra' => array(
                'resources' => array(
                    'override-order' => array(
                        '/acme/overridden' => array(
                            'acme/package-2',
                            'acme/package-1',
                        ),
                    ),
                ),
            ),
        ));

        $overridingPackage1 = $this->createPackage(array(
            'name' => 'acme/package-1',
            'extra' => array(
                'resources' => array(
                    'override' => array(
                        '/acme/overridden' => 'override-1',
                    ),
                ),
            ),
        ));

        $overridingPackage2 = $this->createPackage(array(
            'name' => 'acme/package-2',
            'extra' => array(
                'resources' => array(
                    'override' => array(
                        '/acme/overridden' => 'override-2',
                    ),
                ),
            ),
        ));

        $this->loader->loadPackage($rootPackage, '/');
        $this->loader->loadPackage($overridingPackage1, self::PACKAGE_ROOT);
        $this->loader->loadPackage($overridingPackage2, self::OTHER_PACKAGE_ROOT);
        $this->loader->validateOverrides();
        $this->loader->applyOverrides();
    }

    /**
     * @expectedException \Webmozart\Composer\PuliPlugin\RepositoryLoader\OverrideConflictException
     */
    public function testOverrideOrderInNonRootPackageIsIgnored()
    {
        $this->repo->expects($this->never())
            ->method('add');

        $pseudoRootPackage = $this->createPackage(array(
            'extra' => array(
                'resources' => array(
                    'override-order' => array(
                        '/acme/overridden' => array(
                            'acme/package-2',
                            'acme/package-1',
                        ),
                    ),
                ),
            ),
        ));

        $overridingPackage1 = $this->createPackage(array(
            'name' => 'acme/package-1',
            'extra' => array(
                'resources' => array(
                    'override' => array(
                        '/acme/overridden' => 'override-1',
                    ),
                ),
            ),
        ));

        $overridingPackage2 = $this->createPackage(array(
            'name' => 'acme/package-2',
            'extra' => array(
                'resources' => array(
                    'override' => array(
                        '/acme/overridden' => 'override-2',
                    ),
                ),
            ),
        ));

        $this->loader->loadPackage($pseudoRootPackage, '/');
        $this->loader->loadPackage($overridingPackage1, self::PACKAGE_ROOT);
        $this->loader->loadPackage($overridingPackage2, self::OTHER_PACKAGE_ROOT);
        $this->loader->validateOverrides();
    }

    public function testDefineOverrideOrderForBasePath()
    {
        $this->repo->expects($this->at(0))
            ->method('add')
            ->with('/acme/overridden', self::OTHER_PACKAGE_ROOT.'/override-2');

        $this->repo->expects($this->at(1))
            ->method('add')
            ->with('/acme/overridden/css', self::OTHER_PACKAGE_ROOT.'/css-2');

        $this->repo->expects($this->at(2))
            ->method('add')
            ->with('/acme/overridden', self::PACKAGE_ROOT.'/override-1');

        $this->repo->expects($this->at(3))
            ->method('add')
            ->with('/acme/overridden/css', self::PACKAGE_ROOT.'/css-1');

        $rootPackage = $this->createRootPackage(array(
            'extra' => array(
                'resources' => array(
                    'override-order' => array(
                        '/acme/overridden' => array(
                            'acme/package-2',
                            'acme/package-1',
                        ),
                    ),
                ),
            ),
        ));

        $overridingPackage1 = $this->createPackage(array(
            'name' => 'acme/package-1',
            'extra' => array(
                'resources' => array(
                    'override' => array(
                        '/acme/overridden' => 'override-1',
                        '/acme/overridden/css' => 'css-1',
                    ),
                ),
            ),
        ));

        $overridingPackage2 = $this->createPackage(array(
            'name' => 'acme/package-2',
            'extra' => array(
                'resources' => array(
                    'override' => array(
                        '/acme/overridden' => 'override-2',
                        '/acme/overridden/css' => 'css-2',
                    ),
                ),
            ),
        ));

        $this->loader->loadPackage($rootPackage, '/');
        $this->loader->loadPackage($overridingPackage1, self::PACKAGE_ROOT);
        $this->loader->loadPackage($overridingPackage2, self::OTHER_PACKAGE_ROOT);
        $this->loader->validateOverrides();
        $this->loader->applyOverrides();
    }

    public function testDefineOverrideOrderForSubPath()
    {
        $this->repo->expects($this->at(0))
            ->method('add')
            ->with('/acme/overridden/css', self::OTHER_PACKAGE_ROOT.'/css-2');

        $this->repo->expects($this->at(1))
            ->method('add')
            ->with('/acme/overridden/css', self::PACKAGE_ROOT.'/css-1');

        $rootPackage = $this->createRootPackage(array(
            'extra' => array(
                'resources' => array(
                    'override-order' => array(
                        '/acme/overridden/css' => array(
                            'acme/package-2',
                            'acme/package-1',
                        ),
                    ),
                ),
            ),
        ));

        $overridingPackage1 = $this->createPackage(array(
            'name' => 'acme/package-1',
            'extra' => array(
                'resources' => array(
                    'override' => array(
                        '/acme/overridden' => 'override-1',
                        '/acme/overridden/css' => 'css-1',
                    ),
                ),
            ),
        ));

        $overridingPackage2 = $this->createPackage(array(
            'name' => 'acme/package-2',
            'extra' => array(
                'resources' => array(
                    'override' => array(
                        '/acme/overridden' => 'override-2',
                        '/acme/overridden/css' => 'css-2',
                    ),
                ),
            ),
        ));

        $this->loader->loadPackage($rootPackage, '/');
        $this->loader->loadPackage($overridingPackage1, self::PACKAGE_ROOT);
        $this->loader->loadPackage($overridingPackage2, self::OTHER_PACKAGE_ROOT);

        // Skip validation, which would fail for the base path
        $this->loader->applyOverrides();
    }

    public function testDefineOverrideOrderForBothPaths()
    {
        $this->repo->expects($this->at(0))
            ->method('add')
            ->with('/acme/overridden', self::OTHER_PACKAGE_ROOT.'/override-2');

        $this->repo->expects($this->at(1))
            ->method('add')
            ->with('/acme/overridden', self::PACKAGE_ROOT.'/override-1');

        $this->repo->expects($this->at(2))
            ->method('add')
            ->with('/acme/overridden/css', self::PACKAGE_ROOT.'/css-1');

        $this->repo->expects($this->at(3))
            ->method('add')
            ->with('/acme/overridden/css', self::OTHER_PACKAGE_ROOT.'/css-2');

        $rootPackage = $this->createRootPackage(array(
            'extra' => array(
                'resources' => array(
                    'override-order' => array(
                        '/acme/overridden' => array(
                            'acme/package-2',
                            'acme/package-1',
                        ),
                        '/acme/overridden/css' => array(
                            'acme/package-1',
                            'acme/package-2',
                        ),
                    ),
                ),
            ),
        ));

        $overridingPackage1 = $this->createPackage(array(
            'name' => 'acme/package-1',
            'extra' => array(
                'resources' => array(
                    'override' => array(
                        '/acme/overridden' => 'override-1',
                        '/acme/overridden/css' => 'css-1',
                    ),
                ),
            ),
        ));

        $overridingPackage2 = $this->createPackage(array(
            'name' => 'acme/package-2',
            'extra' => array(
                'resources' => array(
                    'override' => array(
                        '/acme/overridden' => 'override-2',
                        '/acme/overridden/css' => 'css-2',
                    ),
                ),
            ),
        ));

        $this->loader->loadPackage($rootPackage, '/');
        $this->loader->loadPackage($overridingPackage1, self::PACKAGE_ROOT);
        $this->loader->loadPackage($overridingPackage2, self::OTHER_PACKAGE_ROOT);
        $this->loader->validateOverrides();
        $this->loader->applyOverrides();
    }

    public function testTag()
    {
        $this->repo->expects($this->at(0))
            ->method('add')
            ->with('/acme/package', self::PACKAGE_ROOT.'/resources');

        $this->repo->expects($this->at(1))
            ->method('tag')
            ->with('/acme/package', 'acme/tag');

        $package = $this->createPackage(array(
            'name' => 'acme/package',
            'extra' => array(
                'resources' => array(
                    'export' => array(
                        '/acme/package' => 'resources',
                    ),
                    'tag' => array(
                        '/acme/package' => 'acme/tag',
                    )
                ),
            ),
        ));

        $this->loader->loadPackage($package, self::PACKAGE_ROOT);
        $this->loader->applyTags();
    }

    public function testTagExistingResources()
    {
        $this->repo->expects($this->at(0))
            ->method('add')
            ->with('/acme/package-2', self::OTHER_PACKAGE_ROOT.'/resources');

        $this->repo->expects($this->at(1))
            ->method('tag')
            ->with('/acme/package-2', 'acme/tag');

        $package1 = $this->createPackage(array(
            'name' => 'acme/package-1',
            'extra' => array(
                'resources' => array(
                    'tag' => array(
                        '/acme/package-2' => 'acme/tag',
                    ),
                ),
            ),
        ));

        $package2 = $this->createPackage(array(
            'name' => 'acme/package-2',
            'extra' => array(
                'resources' => array(
                    'export' => array(
                        '/acme/package-2' => 'resources',
                    ),
                ),
            ),
        ));

        $this->loader->loadPackage($package2, self::OTHER_PACKAGE_ROOT);
        $this->loader->loadPackage($package1, self::PACKAGE_ROOT);
        $this->loader->applyTags();
    }

    public function testTagFutureResources()
    {
        $this->repo->expects($this->at(0))
            ->method('add')
            ->with('/acme/package-2', self::OTHER_PACKAGE_ROOT.'/resources');

        $this->repo->expects($this->at(1))
            ->method('tag')
            ->with('/acme/package-2', 'acme/tag');

        $package1 = $this->createPackage(array(
            'name' => 'acme/package-1',
            'extra' => array(
                'resources' => array(
                    'tag' => array(
                        '/acme/package-2' => 'acme/tag',
                    ),
                ),
            ),
        ));

        $package2 = $this->createPackage(array(
            'name' => 'acme/package-2',
            'extra' => array(
                'resources' => array(
                    'export' => array(
                        '/acme/package-2' => 'resources',
                    ),
                ),
            ),
        ));

        $this->loader->loadPackage($package1, self::PACKAGE_ROOT);
        $this->loader->loadPackage($package2, self::OTHER_PACKAGE_ROOT);
        $this->loader->applyTags();
    }

    public function testTagTwice()
    {
        $this->repo->expects($this->at(0))
            ->method('add')
            ->with('/acme/package-2', self::OTHER_PACKAGE_ROOT.'/resources');

        $this->repo->expects($this->at(1))
            ->method('tag')
            ->with('/acme/package-2', 'acme/tag-1');

        $this->repo->expects($this->at(2))
            ->method('tag')
            ->with('/acme/package-2', 'acme/tag-2');

        $package1 = $this->createPackage(array(
            'name' => 'acme/package-1',
            'extra' => array(
                'resources' => array(
                    'tag' => array(
                        '/acme/package-2' => 'acme/tag-2',
                    ),
                ),
            ),
        ));

        $package2 = $this->createPackage(array(
            'name' => 'acme/package-2',
            'extra' => array(
                'resources' => array(
                    'export' => array(
                        '/acme/package-2' => 'resources',
                    ),
                    'tag' => array(
                        '/acme/package-2' => 'acme/tag-1',
                    ),
                ),
            ),
        ));

        $this->loader->loadPackage($package2, self::OTHER_PACKAGE_ROOT);
        $this->loader->loadPackage($package1, self::PACKAGE_ROOT);
        $this->loader->applyTags();
    }

    public function testTagTwiceSameTag()
    {
        $this->repo->expects($this->once())
            ->method('add')
            ->with('/acme/package-2', self::OTHER_PACKAGE_ROOT.'/resources');

        $this->repo->expects($this->once())
            ->method('tag')
            ->with('/acme/package-2', 'acme/tag');

        $package1 = $this->createPackage(array(
            'name' => 'acme/package-1',
            'extra' => array(
                'resources' => array(
                    'tag' => array(
                        '/acme/package-2' => 'acme/tag',
                    ),
                ),
            ),
        ));

        $package2 = $this->createPackage(array(
            'name' => 'acme/package-2',
            'extra' => array(
                'resources' => array(
                    'export' => array(
                        '/acme/package-2' => 'resources',
                    ),
                    'tag' => array(
                        '/acme/package-2' => 'acme/tag',
                    ),
                ),
            ),
        ));

        $this->loader->loadPackage($package2, self::OTHER_PACKAGE_ROOT);
        $this->loader->loadPackage($package1, self::PACKAGE_ROOT);
        $this->loader->applyTags();
    }

    public function testMultipleTags()
    {
        $this->repo->expects($this->at(0))
            ->method('add')
            ->with('/acme/package-1', self::PACKAGE_ROOT.'/resources');

        $this->repo->expects($this->at(1))
            ->method('tag')
            ->with('/acme/package-1', 'acme/tag-1');

        $this->repo->expects($this->at(2))
            ->method('tag')
            ->with('/acme/package-1', 'acme/tag-2');

        $package = $this->createPackage(array(
            'name' => 'acme/package-1',
            'extra' => array(
                'resources' => array(
                    'export' => array(
                        '/acme/package-1' => 'resources',
                    ),
                    'tag' => array(
                        '/acme/package-1' => array('acme/tag-1', 'acme/tag-2'),
                    ),
                ),
            ),
        ));

        $this->loader->loadPackage($package, self::PACKAGE_ROOT);
        $this->loader->applyTags();
    }

    /**
     * @expectedException \Webmozart\Composer\PuliPlugin\RepositoryLoader\ResourceDefinitionException
     */
    public function testExportsMustBeArray()
    {
        $package = $this->createPackage(array(
            'name' => 'acme/package',
            'extra' => array(
                'resources' => array(
                    'export' => 'foobar',
                ),
            ),
        ));

        $this->loader->loadPackage($package, self::PACKAGE_ROOT);
    }

    /**
     * @expectedException \Webmozart\Composer\PuliPlugin\RepositoryLoader\ResourceDefinitionException
     */
    public function testOverridesMustBeArray()
    {
        $package = $this->createPackage(array(
            'name' => 'acme/package',
            'extra' => array(
                'resources' => array(
                    'override' => 'foobar',
                ),
            ),
        ));

        $this->loader->loadPackage($package, self::PACKAGE_ROOT);
    }

    /**
     * @expectedException \Webmozart\Composer\PuliPlugin\RepositoryLoader\ResourceDefinitionException
     */
    public function testOverrideOrderMustBeArray()
    {
        $package = $this->createRootPackage(array(
            'name' => 'acme/package',
            'extra' => array(
                'resources' => array(
                    'override-order' => 'foobar',
                ),
            ),
        ));

        $this->loader->loadPackage($package, '/');
    }

    /**
     * @expectedException \Webmozart\Composer\PuliPlugin\RepositoryLoader\ResourceDefinitionException
     */
    public function testTagsMustBeArray()
    {
        $package = $this->createRootPackage(array(
            'name' => 'acme/package',
            'extra' => array(
                'resources' => array(
                    'tag' => 'foobar',
                ),
            ),
        ));

        $this->loader->loadPackage($package, '/');
    }

    /**
     * @param array $config
     *
     * @return \Composer\Package\PackageInterface
     */
    private function createPackage(array $config)
    {
        $package = $this->getMock('\Composer\Package\PackageInterface');

        $package->expects($this->any())
            ->method('getName')
            ->will($this->returnValue(isset($config['name']) ? $config['name'] : ''));

        $package->expects($this->any())
            ->method('getExtra')
            ->will($this->returnValue(isset($config['extra']) ? $config['extra'] : array()));

        return $package;
    }

    /**
     * @param array $config
     *
     * @return \Composer\Package\PackageInterface
     */
    private function createRootPackage(array $config)
    {
        $package = $this->getMock('\Composer\Package\RootPackageInterface');

        $package->expects($this->any())
            ->method('getName')
            ->will($this->returnValue(isset($config['name']) ? $config['name'] : '__root__'));

        $package->expects($this->any())
            ->method('getExtra')
            ->will($this->returnValue(isset($config['extra']) ? $config['extra'] : array()));

        return $package;
    }
}
