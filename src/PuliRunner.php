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
use Symfony\Component\Process\ProcessUtils;

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

        $finder = new ExecutableFinder();

        // Search:
        // 1. in the current working directory
        // 2. in Composer's "bin-dir"
        // 3. in the system path
        $searchPath = array_merge(array(getcwd()), (array) $binDir);

        // Search "puli.phar" in the PATH and the current directory
        if (!($puli = $this->find('puli.phar', $searchPath, $finder))) {
            // Search "puli" in the PATH and Composer's "bin-dir"
            if (!($puli = $this->find('puli', $searchPath, $finder))) {
                throw new RuntimeException('The "puli"/"puli.phar" command could not be found.');
            }
        }

        // Fix slashes
        $php = strtr($php, '\\', '/');
        $puli = strtr($puli, '\\', '/');

        $content = file_get_contents($puli, null, null, -1, 18);

        if ($content === '#!/usr/bin/env php' || 0 === strpos($content, '<?php')) {
            $this->puli = ProcessUtils::escapeArgument($php).' '.ProcessUtils::escapeArgument($puli);
        } else {
            $this->puli = ProcessUtils::escapeArgument($puli);
        }
    }

    /**
     * Returns the command used to execute Puli.
     *
     * @return string The "puli" command.
     */
    public function getPuliCommand()
    {
        return $this->puli;
    }

    /**
     * Runs a Puli command.
     *
     * @param string   $command The Puli command to run.
     * @param string[] $args    Arguments to quote and insert into the command.
     *                          For each key "key" in this array, the placeholder
     *                          "%key%" should be present in the command string.
     *
     * @return string The lines of the output.
     */
    public function run($command, array $args = array())
    {
        $replacements = array();

        foreach ($args as $key => $arg) {
            $replacements['%'.$key.'%'] = ProcessUtils::escapeArgument($arg);
        }

        // Disable colorization so that we can process the output
        // Enable exception traces by using the "-vv" switch
        $fullCommand = sprintf('%s %s --no-ansi -vv', $this->puli, strtr($command, $replacements));

        $process = new Process($fullCommand);
        $process->setTimeout( 180 );
        $process->run();

        if (!$process->isSuccessful()) {
            throw PuliRunnerException::forProcess($process);
        }

        // Normalize line endings across systems
        return str_replace("\r\n", "\n", $process->getOutput());
    }

    private function find($name, array $dirs, ExecutableFinder $finder)
    {
        $suffixes = array('');

        if ('\\' === DIRECTORY_SEPARATOR) {
            $suffixes[] = '.bat';
        }

        // The finder first looks in the system directories and then in the
        // user-defined ones. We want to check the user-defined ones first.
        foreach ($dirs as $dir) {
            foreach ($suffixes as $suffix) {
                $file = $dir.DIRECTORY_SEPARATOR.$name.$suffix;

                if (is_file($file) && ('\\' === DIRECTORY_SEPARATOR || is_executable($file))) {
                    return $file;
                }
            }
        }

        return $finder->find($name);
    }
}
