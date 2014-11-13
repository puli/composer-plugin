<?php

/*
 * This file is part of the Composer Puli Plugin.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Puli\Composer\PuliPlugin\Tests\RepositoryDumper;

use Composer\Package\PackageInterface;
use Composer\Util\Filesystem;
use Puli\Composer\PuliPlugin\RepositoryDumper\RepositoryDumper;
use Puli\Repository\ResourceRepository;

/**
 * @since  1.0
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class RepositoryDumperTest extends \PHPUnit_Framework_TestCase
{
    private $tempDir;

    protected function setUp()
    {
        while (false === mkdir($this->tempDir = sys_get_temp_dir().'/puli-plugin/RepositoryDumperTest'.rand(10000, 99999), 0777, true)) {}
    }

    protected function tearDown()
    {
        $filesystem = new Filesystem();
        $filesystem->remove($this->tempDir);
    }

    public function testDumpRepository()
    {
        $projectDir = $this->tempDir.'/project';
        $vendorDir = $this->tempDir.'/vendor';

        mkdir($projectDir);
        mkdir($vendorDir);

        // Create dependencies
        $repo = new ResourceRepository();
        $repo->add('/file', __FILE__);
        $loader = $this->getMockBuilder('Puli\Composer\PuliPlugin\RepositoryLoader\RepositoryLoader')
            ->disableOriginalConstructor()
            ->getMock();
        $projectPackage = $this->getMock('Composer\Package\PackageInterface');
        $instPackage1 = $this->getMock('Composer\Package\PackageInterface');
        $instPackage2 = $this->getMock('Composer\Package\PackageInterface');

        $installationManager = $this->getMockBuilder('Composer\Installer\InstallationManager')
            ->disableOriginalConstructor()
            ->getMock();
        $installationManager->expects($this->any())
            ->method('getInstallPath')
            ->will($this->returnCallback(function (PackageInterface $package) use ($instPackage1, $instPackage2) {
                if ($package === $instPackage1) {
                    return '/inst1/dir';
                }
                if ($package === $instPackage2) {
                    return '/inst2/dir';
                }
                return '/unknown';
            }));

        // Configure
        $dumper = new RepositoryDumper();
        $dumper->setProjectDir($projectDir);
        $dumper->setVendorDir($vendorDir);
        $dumper->setProjectPackage($projectPackage);
        $dumper->setInstalledPackages(array($instPackage1, $instPackage2));
        $dumper->setInstallationManager($installationManager);
        $dumper->setRepository($repo);
        $dumper->setRepositoryLoader($loader);

        // Expectations
        $loader->expects($this->at(0))
            ->method('setRepository')
            ->with($repo);

        $loader->expects($this->at(1))
            ->method('loadPackage')
            ->with($projectPackage, $projectDir);

        $loader->expects($this->at(2))
            ->method('loadPackage')
            ->with($instPackage1, '/inst1/dir');

        $loader->expects($this->at(3))
            ->method('loadPackage')
            ->with($instPackage2, '/inst2/dir');

        $loader->expects($this->at(4))
            ->method('validateOverrides');

        $loader->expects($this->at(5))
            ->method('applyOverrides');

        $loader->expects($this->at(6))
            ->method('applyTags');

        // Go
        $dumper->dumpRepository();

        // Check that the file has been created
        $this->assertFileExists($vendorDir.'/resource-repository.php');

        // Load and test
        $generatedRepo = require ($vendorDir.'/resource-repository.php');

        $this->assertInstanceOf('Puli\Repository\ResourceRepositoryInterface', $generatedRepo);

        $this->assertTrue($generatedRepo->contains('/file'));
        $this->assertSame(__FILE__, $generatedRepo->get('/file')->getLocalPath());
        $this->assertFalse($generatedRepo->contains('/foo'));
    }
}
