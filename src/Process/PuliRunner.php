<?php

/*
 * This file is part of the puli/composer-plugin package.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Puli\ComposerPlugin\Process;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Runs the "puli" command.
 *
 * @since  1.0
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class PuliRunner
{
    /**
     * @var string
     */
    private $command;

    public function __construct($binDir = null, $decorated = true)
    {
        $phpFinder = new PhpExecutableFinder();

        if (false === ($php = $phpFinder->find())) {
            throw new RuntimeException('The "php" command could not be found.');
        }

        $puliFinder = new ExecutableFinder();
        $puliFinder->setSuffixes(array('', '.phar'));

        $extraDirs = array();

        if ($binDir) {
            $extraDirs[] = $binDir;
        }

        if (false === ($puli = $puliFinder->find('puli', null, $extraDirs))) {
            throw new RuntimeException('The "puli" executable could not be found.');
        }

        // We need to force colorization in the sub-process
        // By default, the sub-process would not be colorized
        $ansi = $decorated ? '--ansi' : '--no-ansi';

        $this->command = $php.' '.$puli.' '.$ansi;
    }

    public function run($command)
    {
        exec(sprintf('%s %s', $this->command, $command), $output, $statusCode);

        if (0 !== $statusCode) {
            // Display exception and quit
            echo $output;

            exit($statusCode);
        }

        return $output;
    }
}
