<?php

/*
 * This file is part of the Composer Resource Plugin package.
 *
 * (c) Bernhard Schussek <bschussek@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Webmozart\Composer\ResourcePlugin;

/**
 * @since  %%NextVersion%%
 * @author Bernhard Schussek <bschussek@gmail.com>
 */
interface ResourceRepositoryInterface
{
    public function getPath($resourcePath);

    public function getPaths($resourcePath);

    public function getPublicPath($resourcePath);

    public function listDirectory($resourcePath);

    public function globPaths($glob);
}
