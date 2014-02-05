<?php

/*
 * This file is part of the Composer Puli Plugin.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webmozart\Composer\PuliPlugin\Util;

/**
 * @since  1.0
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
class BasePathCalculator
{
    public function calculateCommonBasePath($path1, $path2)
    {
        $path1 = strtr($path1, array('\\' => '/'));
        $path2 = strtr($path2, array('\\' => '/'));

        $previousBasePath = null;
        $basePath = rtrim($path1, '/');

        // Once we reach the root directory, dirname($path) === $path, so we
        // need to abort the loop
        while ($previousBasePath !== $basePath) {
            // Append slashes to prevent false positives when two paths have
            // a common prefix, for example /base/foo and /base/foobar.
            // Don't append a slash for the root "/", because then that root
            // won't be discovered as common prefix ("//" is not a prefix of
            // "/foobar/").
            if (0 === strpos($path2.'/', '/' === $basePath ? '/' : $basePath.'/')) {
                return $basePath;
            }

            $previousBasePath = $basePath;
            $basePath = dirname($path1);
        }

        // No common base path found
        return null;
    }
}
