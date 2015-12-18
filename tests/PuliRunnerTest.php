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
use ReflectionObject;
use Webmozart\PathUtil\Path;

/**
 * @since  1.0
 *
 * @author Titouan Galopin <galopintitouan@gmail.com>
 */
class PuliRunnerTest extends PHPUnit_Framework_TestCase
{
    public function testRunnerBash()
    {
        $fixturesDir = Path::normalize(__DIR__.'/Fixtures/scripts/bash');
        $runner = new PuliRunner($fixturesDir);

        $this->assertEquals($fixturesDir.'/puli', $this->extractScriptPathFromRunner($runner));
    }

    public function testRunnerPhpClassical()
    {
        $fixturesDir = Path::normalize(__DIR__.'/Fixtures/scripts/php_classical');
        $runner = new PuliRunner($fixturesDir);

        $this->assertContains(' \''.$fixturesDir.'/puli\'', $this->extractScriptPathFromRunner($runner));
    }

    public function testRunnerPhpHashbang()
    {
        $fixturesDir = Path::normalize(__DIR__.'/Fixtures/scripts/php_hashbang');
        $runner = new PuliRunner($fixturesDir);

        $this->assertContains(' \''.$fixturesDir.'/puli\'', $this->extractScriptPathFromRunner($runner));
    }

    /**
     * @param PuliRunner $runner
     *
     * @return string
     */
    private function extractScriptPathFromRunner(PuliRunner $runner)
    {
        $reflection = new ReflectionObject($runner);

        $property = $reflection->getProperty('puli');
        $property->setAccessible(true);

        return Path::normalize($property->getValue($runner));
    }
}
