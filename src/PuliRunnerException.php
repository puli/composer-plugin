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
use Symfony\Component\Process\Process;

/**
 * Thrown when an error occurs while running Puli.
 *
 * @since  1.0
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class PuliRunnerException extends RuntimeException
{
    /**
     * @var string
     */
    private $command;

    /**
     * @var int
     */
    private $exitCode;

    /**
     * @var string
     */
    private $shortError;

    /**
     * @var string
     */
    private $fullError;

    public static function forProcess(Process $process)
    {
        $shortError = $fullError = $process->getErrorOutput();

        if (preg_match('~^fatal: (.+)$~', $shortError, $matches)) {
            $shortError = trim($matches[1]);
        } elseif (preg_match('~^\s+\[([\w\\\\]+\\\\)?(\w+)\]\s+(.+)\n\n\S~s', $shortError, $matches)) {
            $shortError = trim($matches[2]).': '.trim($matches[3]);
        }

        return new static($process->getCommandLine(), $process->getExitCode(), $shortError, $fullError);
    }

    /**
     * @param string $command
     * @param int    $exitCode
     * @param string $shortError
     * @param string $fullError
     */
    public function __construct($command, $exitCode, $shortError, $fullError)
    {
        parent::__construct(sprintf(
            "An error occurred while running: %s (status %s): %s",
            $command,
            $exitCode,
            $shortError
        ));

        $this->command = $command;
        $this->exitCode = $exitCode;
        $this->shortError = $shortError;
        $this->fullError = $fullError;
    }

    /**
     * @return string
     */
    public function getCommand()
    {
        return $this->command;
    }

    /**
     * @return int
     */
    public function getExitCode()
    {
        return $this->exitCode;
    }

    /**
     * @return string
     */
    public function getShortError()
    {
        return $this->shortError;
    }

    /**
     * @return string
     */
    public function getFullError()
    {
        return $this->fullError;
    }
}
