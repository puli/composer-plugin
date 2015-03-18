<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Puli\ComposerPlugin\Process;

use RuntimeException;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Launches a PHP process.
 *
 * @since  1.0
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class PhpProcessLauncher
{
    /**
     * @var string
     */
    private $php;

    public function __construct()
    {
        $executableFinder = new PhpExecutableFinder();

        $this->php = $executableFinder->find();
    }

    public function isSupported()
    {
        return (bool) $this->php;
    }

    public function launchProcess($command)
    {
        if (!$this->php) {
            throw new RuntimeException('The "php" binary could not be found.');
        }

        system(sprintf('%s %s', $this->php, $command), $statusCode);

        // Exception was shown, quit
        if (0 !== $statusCode) {
            exit($statusCode);
        }
    }
}
