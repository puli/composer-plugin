Changelog
=========

* 1.0.0-alpha3 (@release_date@)

 * removed `ComposerPlugin`. You need to remove the plugin from your puli.json
   file, otherwise you'll have an exception. The package names are now set
   during installation by `PuliPlugin`.

* 1.0.0-alpha2 (2014-12-03)

 * removed `PathMatcher`; its logic was moved to "webmozart/path-util"
 * moved `RepositoryLoader`, `OverrideConflictException` and 
   `ResourceDefinitionException` to "puli/repository-manager"
 * moved code to `Puli\Extension\Composer` namespace
 * added `ComposerPlugin` for Puli

* 1.0.0-alpha1 (2014-02-05)

 * first alpha release
