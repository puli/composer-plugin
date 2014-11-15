Puli Plugin for Composer
========================

[![Build Status](https://travis-ci.org/puli/composer-puli-plugin.png?branch=master)](https://travis-ci.org/puli/composer-puli-plugin)
[![Scrutinizer Quality Score](https://scrutinizer-ci.com/g/puli/composer-puli-plugin/badges/quality-score.png?s=f1fbf1884aed7f896c18fc237d3eed5823ac85eb)](https://scrutinizer-ci.com/g/puli/composer-puli-plugin/)
[![Code Coverage](https://scrutinizer-ci.com/g/puli/composer-puli-plugin/badges/coverage.png?s=5d83649f6fc3a9754297da9dc0d997be212c9145)](https://scrutinizer-ci.com/g/puli/composer-puli-plugin/)
[![SensioLabsInsight](https://insight.sensiolabs.com/projects/c519f170-f530-4f3a-83e9-0516583ddc92/mini.png)](https://insight.sensiolabs.com/projects/c519f170-f530-4f3a-83e9-0516583ddc92)
[![Latest Stable Version](https://poser.pugx.org/puli/composer-puli-plugin/v/stable.png)](https://packagist.org/packages/puli/composer-puli-plugin)
[![Total Downloads](https://poser.pugx.org/puli/composer-puli-plugin/downloads.png)](https://packagist.org/packages/puli/composer-puli-plugin)
[![Dependency Status](https://www.versioneye.com/php/puli:composer-puli-plugin/1.0.0/badge.png)](https://www.versioneye.com/php/puli:composer-puli-plugin/1.0.0)

Latest release: [1.0.0-alpha1](https://packagist.org/packages/puli/composer-puli-plugin#1.0.0-alpha1)

PHP >= 5.3.9

This plugin integrates the [Puli library] into [Composer]. Whenever you install
or update your Composer dependencies, a Puli repository is generated from the
composer.json files of the installed packages:

```json
{
    "name": "acme/blog",
    "extra": {
        "resources": {
            "/acme/blog": "resources"
        }
    }
}
```

You can include the generated repository in your code and access all exported
resources by their Puli paths:

```php
$repo = require __DIR__.'/vendor/resource-repository.php';

// /path/to/project/vendor/acme/blog/resources/config/config.yml
echo $repo->get('/acme/blog/config/config.yml')->getContents();
```

Installation
------------

Follow the [Getting Started] guide to install the Puli plugin in your project.

Documentation
-------------

Read the [Plugin Documentation] if you want to learn more about configuring
repositories with the Composer plugin.

Contribute
----------

Contributions to are very welcome!

* Report any bugs or issues you find on the [issue tracker].
* You can grab the source code at Puli’s [Git repository].

Support
-------

If you are having problems, send a mail to bschussek@gmail.com or shout out to
[@webmozart] on Twitter.

License
-------

Puli and its documentation are licensed under the [MIT license].

[Puli library]: https://github.com/puli/puli
[Composer]: https://getcomposer.org
[Getting Started]: http://puli.readthedocs.org/en/latest/getting-started/application-devs.html
[Plugin Documentation]: http://puli.readthedocs.org/en/latest/repository-management/composer.html
[issue tracker]: https://github.com/puli/puli/issues
[Git repository]: https://github.com/puli/composer-puli-plugin
[@webmozart]: https://twitter.com/webmozart
[MIT license]: LICENSE
