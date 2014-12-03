Composer Plugin for Puli
========================

[![Build Status](https://travis-ci.org/puli/composer-plugin.png?branch=master)](https://travis-ci.org/puli/composer-plugin)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/puli/composer-plugin/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/puli/composer-plugin/?branch=master)
[![SensioLabsInsight](https://insight.sensiolabs.com/projects/2c283cc0-acfd-4761-99d1-6b503f8b152f/mini.png)](https://insight.sensiolabs.com/projects/2c283cc0-acfd-4761-99d1-6b503f8b152f)
[![Latest Stable Version](https://poser.pugx.org/puli/composer-plugin/v/stable.png)](https://packagist.org/packages/puli/composer-plugin)
[![Total Downloads](https://poser.pugx.org/puli/composer-plugin/downloads.png)](https://packagist.org/packages/puli/composer-plugin)
[![Dependency Status](https://www.versioneye.com/php/puli:composer-plugin/1.0.0/badge.png)](https://www.versioneye.com/php/puli:composer-plugin/1.0.0)

Latest release: [1.0.0-alpha1](https://packagist.org/packages/puli/composer-plugin#1.0.0-alpha1)

PHP >= 5.3.9

This plugin integrates [Composer] into the [Puli Repository Manager]. Whenever you
install or update your Composer dependencies, a Puli repository is generated 
from the puli.json files of the installed packages:

```json
{
    "resources": {
        "/acme/blog": "resources"
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

Read [Puli at a Glance] if you want to learn more about Puli.

Authors
-------

* [Bernhard Schussek] a.k.a. [@webmozart]
* [The Community Contributors]

Installation
------------

Follow the [Getting Started] guide to install Puli in your project.

Documentation
-------------

Read the [Puli Documentation] if you want to learn more about Puli.

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

All contents of this package are licensed under the [MIT license].

[Bernhard Schussek]: http://webmozarts.com
[The Community Contributors]: https://github.com/puli/composer-plugin/graphs/contributors
[Puli Repository Manager]: https://github.com/puli/repository-manager
[Composer]: https://getcomposer.org
[Getting Started]: http://puli.readthedocs.org/en/latest/getting-started.html
[Puli Documentation]: http://puli.readthedocs.org/en/latest/index.html
[Puli at a Glance]: http://puli.readthedocs.org/en/latest/at-a-glance.html
[issue tracker]: https://github.com/puli/puli/issues
[Git repository]: https://github.com/puli/composer-plugin
[@webmozart]: https://twitter.com/webmozart
[MIT license]: LICENSE
