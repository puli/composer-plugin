<?php

/*
 * This file is part of the puli/composer-plugin package.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Puli\ComposerPlugin\Tests;

use PHPUnit_Framework_TestCase;
use Puli\ComposerPlugin\PuliRunner;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\ProcessUtils;
use Webmozart\PathUtil\Path;

/**
 * @since  1.0
 *
 * @author Titouan Galopin <galopintitouan@gmail.com>
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class PuliRunnerTest extends PHPUnit_Framework_TestCase
{
    /**
     * @var string
     */
    private $fixturesDir;

    /**
     * @var string
     */
    private $previousWd;

    /**
     * @var string
     */
    private $php;

    protected function setUp()
    {
        $phpFinder = new PhpExecutableFinder();

        $this->fixturesDir = Path::normalize(__DIR__.'/Fixtures/scripts');
        $this->previousWd = getcwd();
        $this->php = strtr($phpFinder->find(), '\\', '/');
    }

    protected function tearDown()
    {
        chdir($this->previousWd);
    }

    public function testRunnerBash()
    {
        chdir($this->fixturesDir.'/bash');

        $runner = new PuliRunner();

        $expected = ProcessUtils::escapeArgument($this->fixturesDir.'/bash/puli');

        $this->assertSame($expected, $runner->getPuliCommand());
    }

    public function testRunnerBat()
    {
        if ('\\' !== DIRECTORY_SEPARATOR) {
            $this->markTestSkipped('Requires Windows to run');
        }

        chdir($this->fixturesDir.'/bat');

        $runner = new PuliRunner();

        $expected = ProcessUtils::escapeArgument($this->fixturesDir.'/bat/puli.bat');

        $this->assertSame($expected, $runner->getPuliCommand());
    }

    public function testRunnerPhpClassical()
    {
        chdir($this->fixturesDir.'/php_classical');

        $runner = new PuliRunner();

        $expected = ProcessUtils::escapeArgument($this->php).' '.ProcessUtils::escapeArgument($this->fixturesDir.'/php_classical/puli');

        $this->assertSame($expected, $runner->getPuliCommand());
    }

    public function testRunnerPhpHashbang()
    {
        chdir($this->fixturesDir.'/php_hashbang');

        $runner = new PuliRunner();

        $expected = ProcessUtils::escapeArgument($this->php).' '.ProcessUtils::escapeArgument($this->fixturesDir.'/php_hashbang/puli');

        $this->assertSame($expected, $runner->getPuliCommand());
    }
}
