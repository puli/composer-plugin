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
    private $status;

    /**
     * @var string
     */
    private $output;

    /**
     * @var string
     */
    private $shortError;

    /**
     * @var string
     */
    private $longError;

    public static function forProcess(Process $process)
    {
        $shortError = $longError = trim($process->getErrorOutput());

        if (preg_match('~^fatal: (.+)$~', $shortError, $matches)) {
            $shortError = trim($matches[1]);
        } elseif (preg_match('~^\s+\[([\w\\]+)?(\w+)\]\s+(.+)\nException trace:\n~', $shortError, $matches)) {
            $shortError = trim($matches[2]).': '.trim($matches[3]);
        }

        return new static($process->getCommandLine(), $process->getStatus(), $shortError, $longError);
    }

    /**
     * @param string $command
     * @param int    $status
     * @param string $shortError
     * @param string $longError
     */
    public function __construct($command, $status, $shortError, $longError)
    {
        parent::__construct(sprintf(
            "An error occurred while running: %s (status %s): %s",
            $command,
            $status,
            $shortError
        ));

        $this->command = $command;
        $this->status = $status;
        $this->shortError = $shortError;
        $this->longError = $longError;
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
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * @return \Exception
     */
    public function getOutput()
    {
        return $this->output;
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
    public function getLongError()
    {
        return $this->longError;
    }
}
