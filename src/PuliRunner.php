<?php

/*
 * This file is part of the puli/composer-plugin package.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Puli\ComposerPlugin;

use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Webmozart\PathUtil\Path;

/**
 * Executes the "puli" command.
 *
 * @since  1.0
 *
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class PuliRunner
{
    /**
     * @var string
     */
    private $puli;

    /**
     * Creates a new runner.
     *
     * @param string|null $binDir The path to Composer's "bin-dir".
     */
    public function __construct($binDir = null)
    {
        $phpFinder = new PhpExecutableFinder();

        if (!($php = $phpFinder->find())) {
            throw new RuntimeException('The "php" command could not be found.');
        }

        $puliFinder = new ExecutableFinder();

        // Search:
        // 1. in the current working directory
        // 2. in Composer's "bin-dir"
        // 3. in the system path
        $searchPath = array_merge(array(getcwd()), (array) $binDir);

        // Search "puli.phar" in the PATH and the current directory
        if (!($puli = $puliFinder->find('puli.phar', null, $searchPath))) {
            // Search "puli" in the PATH and Composer's "bin-dir"
            if (!($puli = $puliFinder->find('puli', null, $searchPath))) {
                throw new RuntimeException('The "puli"/"puli.phar" command could not be found.');
            }
        }

        if (Path::hasExtension($puli, '.bat')) {
            $this->puli = $puli;
        } else {
            $this->puli = $php.' '.$puli;
        }
    }

    /**
     * Runs a Puli command.
     *
     * @param string $command The Puli command to run.
     *
     * @return string The lines of the output.
     */
    public function run($command)
    {
        // Disable colorization so that we can process the output
        // Enable exception traces by using the "-vv" switch
        $fullCommand = sprintf('%s %s --no-ansi -vv', $this->puli, $command);

        $process = new Process($fullCommand);
        $process->run();

        if (!$process->isSuccessful()) {
            throw PuliRunnerException::forProcess($process);
        }

        // Normalize line endings across systems
        return str_replace("\r\n", "\n", $process->getOutput());
    }
}
