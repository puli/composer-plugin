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
use Puli\ComposerPlugin\PuliRunnerException;

/**
 * @since  1.0
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class PuliRunnerExceptionTest extends PHPUnit_Framework_TestCase
{
    public function testForProcessNonVerbose()
    {
        $process = $this->getMockBuilder('Symfony\Component\Process\Process')
            ->disableOriginalConstructor()
            ->getMock();

        $process->expects($this->any())
            ->method('getCommandLine')
            ->willReturn('puli do something');
        $process->expects($this->any())
            ->method('getStatus')
            ->willReturn(1);
        $process->expects($this->any())
            ->method('getErrorOutput')
            ->willReturn("fatal: SomeException: Some message\n");

        $exception = PuliRunnerException::forProcess($process);

        $this->assertSame('puli do something', $exception->getCommand());
        $this->assertSame(1, $exception->getStatus());
        $this->assertSame('SomeException: Some message', $exception->getShortError());
        $this->assertSame("fatal: SomeException: Some message\n", $exception->getFullError());
    }

    public function testForProcessVerbose()
    {
        $process = $this->getMockBuilder('Symfony\Component\Process\Process')
            ->disableOriginalConstructor()
            ->getMock();

        $process->expects($this->any())
            ->method('getCommandLine')
            ->willReturn('puli do something');
        $process->expects($this->any())
            ->method('getStatus')
            ->willReturn(1);
        $process->expects($this->any())
            ->method('getErrorOutput')
            ->willReturn($output = <<<EOF



  [ErrorException]
  preg_match(): Compilation failed



update [--prefer-source] [--prefer-dist] [--dry-run] [--dev] [--no-dev] [--lock] [--no-plugins] [--no-custom-installers] [--no-autoloader] [--no-scripts] [--no-progress] [--with-dependencies] [-v|vv|vvv|--verbose] [-o|--optimize-autoloader] [--ignore-platform-reqs] [--prefer-stable] [--prefer-lowest] [packages1] ... [packagesN]

EOF
            );

        $exception = PuliRunnerException::forProcess($process);

        $this->assertSame('puli do something', $exception->getCommand());
        $this->assertSame(1, $exception->getStatus());
        $this->assertSame('ErrorException: preg_match(): Compilation failed', $exception->getShortError());
        $this->assertSame($output, $exception->getFullError());
    }

    public function testForProcessVerboseWithTrace()
    {
        $process = $this->getMockBuilder('Symfony\Component\Process\Process')
            ->disableOriginalConstructor()
            ->getMock();

        $process->expects($this->any())
            ->method('getCommandLine')
            ->willReturn('puli do something');
        $process->expects($this->any())
            ->method('getStatus')
            ->willReturn(1);
        $process->expects($this->any())
            ->method('getErrorOutput')
            ->willReturn($output = <<<EOF



  [ErrorException]
  preg_match(): Compilation failed



Exception trace:
  ()

EOF
            );

        $exception = PuliRunnerException::forProcess($process);

        $this->assertSame('puli do something', $exception->getCommand());
        $this->assertSame(1, $exception->getStatus());
        $this->assertSame('ErrorException: preg_match(): Compilation failed', $exception->getShortError());
        $this->assertSame($output, $exception->getFullError());
    }
}
