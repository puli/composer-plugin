The Puli Plugin for Composer
============================

[![Build Status](https://travis-ci.org/puli/composer-plugin.svg?branch=1.0.0-beta)](https://travis-ci.org/puli/composer-plugin)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/puli/composer-plugin/badges/quality-score.png?b=1.0.0-beta)](https://scrutinizer-ci.com/g/puli/composer-plugin/?branch=1.0.0-beta)
[![SensioLabsInsight](https://insight.sensiolabs.com/projects/2c283cc0-acfd-4761-99d1-6b503f8b152f/mini.png)](https://insight.sensiolabs.com/projects/2c283cc0-acfd-4761-99d1-6b503f8b152f)
[![Latest Stable Version](https://poser.pugx.org/puli/composer-plugin/v/stable.svg)](https://packagist.org/packages/puli/composer-plugin)
[![Total Downloads](https://poser.pugx.org/puli/composer-plugin/downloads.svg)](https://packagist.org/packages/puli/composer-plugin)
[![Dependency Status](https://www.versioneye.com/php/puli:composer-plugin/1.0.0/badge.svg)](https://www.versioneye.com/php/puli:composer-plugin/1.0.0)

Latest release: [1.0.0-beta](https://packagist.org/packages/puli/composer-plugin#1.0.0-beta)

PHP >= 5.3.9

This plugin integrates [Composer] with the [Puli Repository Manager]. Whenever
you install or update your Composer dependencies, a [Puli resource repository] 
and [discovery] are built from the puli.json files of all installed packages:

```json
{
    "resources": {
        "/acme/blog": "resources"
    }
}
```

You can load the built repository/discovery in your code:

```php
$factoryClass = PULI_FACTORY_CLASS;
$factory = new $factoryClass();

// Fetch resources from the repository
$repo = $factory->createRepository();

echo $repo->get('/acme/blog/config/config.yml')->getBody();

// Find resources by binding type
$discovery = $factory->createFactory($repo);

foreach ($discovery->find('doctrine/xml-mapping') as $binding) {
    foreach ($binding->getResources() as $resource) {
        // do something...
    }
}
```

Read [Puli at a Glance] to learn more about Puli.

Authors
-------

* [Bernhard Schussek] a.k.a. [@webmozart]
* [The Community Contributors]

Installation
------------

Follow the [Getting Started] guide to install Puli in your project.

Documentation
-------------

Read the [Puli Documentation] to learn more about Puli.

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
[Puli resource repository]: https://github.com/puli/repository
[discovery]: https://github.com/puli/discovery
[Composer]: https://getcomposer.org
[Getting Started]: http://docs.puli.io/en/latest/getting-started.html
[Puli Documentation]: http://docs.puli.io/en/latest/index.html
[Puli at a Glance]: http://docs.puli.io/en/latest/at-a-glance.html
[issue tracker]: https://github.com/puli/issues/issues
[Git repository]: https://github.com/puli/composer-plugin
[@webmozart]: https://twitter.com/webmozart
[MIT license]: LICENSE
