# Autoloading mechanism

The autoloading mechanism of the tracing library is pretty complex and has to support different use cases:

- applications using or not using composer;
- applications using or not using manual instrumentation;
- applications using `DDTrace\*` classes in the `opcache.preload` script;
- applications defining 'terminal' autoloaders that trigger an error if no class was found.

Most of the complexity comes from the following facts:

- some `DDTrace` classes are defined internally by the extension, some are defined in userland but not autoloaded by composer and meant for internal use, while some others represent the public api and are both required by the internal loaded and by composer;
- automatic instrumentation runs before it is known that composer exists, and after any `opcache.preload` script;

## How it works

At request's `RINIT` time, the file `bridge/autoload.php` is loaded which is in charge of loading all the required classes.

The autoloading procedure works as described below:

1. Check if class `DDTrace\ComposerBootstrap` is declared. Do not trigger the autoloading mechanism if the class is not defined, so 'terminal' autoloaders - that trigger errors if a class was not found - are supported.
   - Class `DDTrace\ComposerBootstrap` is declared in `src/api/bootstrap.composer.php` and it is loaded when the Composer's `vendor/autoload.php` file is required by the user;
   - It is declared in the `files` section of the composer file, not as a `psr4` autoloader, so it is loaded always, even when not explicitly required by the user;
   - The existence of this class during `RINIT` means that all the following conditions are met:
     1. `opcache.preload` script was executed;
     2. `opcache.preload` script used composer;
     3. composer requires `datadog/dd-trace`;
   - A class definition is used, instead of a constant, because constants defined in the `opcache.preload` script are not visible while the autoload script is loaded during `RINIT`.
2. If the above condition is met, then register an autoloader for the `src/api` classes that follows the [`psr4` specification](https://www.php-fig.org/psr/psr-4/)
   - a `psr4` class loader is used in place of loading the `_generated_api.php` file described below because some classes from `src/api` might have already be loaded during the execution of the `opcache.preload`.
3. If the condition at point 1 is not met, the file `_generated_api.php` is loaded which contains all the classes that would be provided by the composer package;
   - this approach is used in place of registering a `psr4` autoloader for performance reasons. As a matter of facts, composer never kicks in in this condition, since all the classes are already defined.
4. File `_generated_internal.php` is loaded, which declares all the classes and functions meant only for internal use, and not meant to be used by users for manual instrumentation.
