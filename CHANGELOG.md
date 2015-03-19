Changelog
=========

* 1.0.0-beta3 (2015-03-19)

 * fixed error: Constant PULI_FACTORY_CLASS already defined
 * disabled plugins during Composer hook to fix error "PluginClass not found"
 * the Puli factory is now automatically regenerated after composer update/install
 * enabled plugins during "puli build" in the Composer hook

* 1.0.0-beta2 (2015-01-27)

 * fixed: packages with a moved install path are reinstalled now
 * added `IOLogger`
 * errors happening during package installation are logged to the screen now 
   instead of causing Composer to abort
 * errors happening during package loading are logged to the screen now instead
   of being silently ignored
 * fixed: packages installed by the user are not overwritten if a package with
   the same name but a different path is loaded through Composer

* 1.0.0-beta (2015-01-13)

 * removed `ComposerPlugin`. You need to remove the plugin from your puli.json
   file, otherwise you'll have an exception. The package names are now set
   during installation by `PuliPlugin`.
 * the generated `PuliFactory` is now added to the class-map autoloader
 * the class name of the generated `PuliFactory` is now declared in the
   `PULI_FACTORY_CLASS` constant in the autoloader
 * the package name defined in composer.json is now copied to puli.json
 * moved code to `Puli\ComposerPlugin` namespace

* 1.0.0-alpha2 (2014-12-03)

 * removed `PathMatcher`; its logic was moved to "webmozart/path-util"
 * moved `RepositoryLoader`, `OverrideConflictException` and 
   `ResourceDefinitionException` to "puli/repository-manager"
 * moved code to `Puli\Extension\Composer` namespace
 * added `ComposerPlugin` for Puli

* 1.0.0-alpha1 (2014-02-05)

 * first alpha release
