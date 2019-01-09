# Datadog PHP integration

This is the API documentation for the Datadog PHP Tracer. If you are just looking to get started, check out the [tracing setup documentation](https://docs.datadoghq.com/tracing/setup/php/).

## Overview

Datadog's PHP APM integration provides the ability to trace critical parts of your PHP applications by automatically instrumenting code and submitting data to Datadog’s APM service using the OpenTracing API.

To provide a great out-of-the-box experience that automatically instruments common libraries without requiring intrusive code changes or wrapping every object, we provide a PHP extension that allows introspecting any PHP function or method.

## Datadog PHP extension installation

Datadog’s PHP extension (`ddtrace`) allows introspection of arbitrary PHP code.

The easiest way to install the extension is from [PECL](https://pecl.php.net/package/datadog_trace).

```bash
$ sudo pecl install datadog_trace-beta
```

If you don't have `pecl` installed, you can install the extension from a package download. First [download the appropriate package](https://github.com/DataDog/dd-trace-php/releases) from the releases page. Then install the package with one of the commands below.

```bash
# using RPM package (RHEL/Centos 6+, Fedora 20+)
$ rpm -ivh datadog-php-tracer.rpm

# using DEB package (Debian Jessie+ , Ubuntu 14.04+)
$ deb -i datadog-php-tracer.deb

# using APK package (Alpine)
$ apk add datadog-php-tracer.apk --allow-untrusted

# using tar.gz archive (Other distributions using libc6)
$ tar -xf datadog-php-tracer.tar.gz -C /
  /opt/datadog-php/bin/post-install.sh
```

### Compiling and installing the extension manually

```bash
mkdir dd-trace
cd dd-trace
curl -L https://github.com/DataDog/dd-trace-php/archive/v0.2.5.tar.gz | tar x --strip-components=1
phpize # generate files needed to build PHP extension
./configure
make
sudo make install
```

#### Bash one-liner

```bash
(cd $(mktemp -d); curl -L https://github.com/DataDog/dd-trace-php/archive/v0.2.5.tar.gz | tar x --strip-components=1 && phpize && ./configure && make && sudo make install )
```

### Enabling the extension

After the extension has been installed, the PHP runtime needs to be configured to use it.

To do that we need to modify `php.ini`. This file’s location depends on how PHP has been built and configured on your system. To find out where yours is, run the following command:

```bash
$ php --ini

Configuration File (php.ini) Path: /usr/local/etc/php/7.2
Loaded Configuration File:         /usr/local/etc/php/7.2/php.ini
Scan for additional .ini files in: /usr/local/etc/php/7.2/conf.d
Additional .ini files parsed:      /usr/local/etc/php/7.2/conf.d/ext-opcache.ini
```

Now the only remaining thing is to add following line to the `.ini` file found using the above command:

```ini
extension=ddtrace.so
```

After restarting the PHP application (eg. `apachectl restart`) the extension should now be enabled. To confirm that it’s being loaded, run:

```bash
php -m | grep ddtrace
```

Some systems use different `php.ini` files for command line PHP vs. the web server module, so you may need to confirm both. If you look at the output of `phpinfo()` in a web page, you will see `ddtrace` listed if it’s loaded.

## Enabling automatic tracing

Once the C extension is installed, we need to install the PHP package that provides the actual integrations and framework for sending traces to Datadog.

### Install `datadog/dd-trace` package

```bash

composer config minimum-stability beta # required to install opentracing 1.0.0-beta5
composer require opentracing/opentracing
composer require datadog/dd-trace
```

#### Alternative: Install `datadog/dd-trace` package without changing `minimum-stability`

```bash
composer require datadog/dd-trace # first add dd-trace require
# then manually add following entry to your `composer.json` ”require” entry
#  "opentracing/opentracing": "@dev"

#  Example end result should look like:
#  "require": {
#   "datadog/dd-trace": "^0.2.2",
#    "opentracing/opentracing": "@dev"
#  }
# Next run:
composer update
```

### Enabling tracing

#### Laravel integration

To enable [Laravel](https://laravel.com/) integration we need to configure a new provider in `config/app.php`

```php
    'providers' => [
# .....
      # Laravel 5
      'DDTrace\Integrations\Laravel\V5\LaravelProvider',
      # Laravel 4
      'DDTrace\Integrations\Laravel\V4\LaravelProvider',
```

Now your Laravel application should start sending traces to the Datadog agent running on localhost (in default configuration). The Datadog agent must have APM enabled; see https://docs.datadoghq.com/tracing/setup/ for instructions on installing and configuring the agent.

#### Lumen integration

To enable [Lumen](https://lumen.laravel.com/) integration we need to add a new provider in `bootstrap/app.php`.

```php
# Lumen 5
$app->register('DDTrace\Integrations\Laravel\V5\LaravelProvider');
```

#### Symfony integration

For Symfony 3.x applications, add the bundle in `app/AppKernel.php`

```php
public function registerBundles()
{
    $bundles = array(
        // ...
        new DDTrace\Integrations\Symfony\V3\SymfonyBundle(),
        // ...
    );
    
    ...

    return $bundles;
}
```

For Symfony 4.x applications, add the bundle in `config/bundles.php`

```php
public function registerBundles()
{
    return [
        // ...
        DDTrace\Integrations\Symfony\V4\SymfonyBundle::class => ['all' => true],
        // ...
    ];
}
```

## Flex

For Symfony Flex applications, add the bundle in `config/bundles.php`:


```php
    return [
        // ...
        DDTrace\Integrations\Symfony\V4\SymfonyBundle::class => ['all' => true],
        // ...
    ];
```

#### Manual instrumentation

If you are using another framework or CMS that is not listed above, you can manually instrument the tracer with one line in early script execution.

```php
\DDTrace\Tracer::init('my_base_trace');
```

Amongst other things, this method sets a global singleton instance of the tracer which can be accessed at any time via `\DDTrace\GlobalTracer::get()`.

```php
$tracer = \DDTrace\GlobalTracer::get();
```

##### Configuration settings

The `DDTrace\Tracer::init()` method takes an array as the second parameter which accepts a number of config values.

| Name           | Type                      | Value                                       | Default
| -------------- | ------------------------- | ------------------------------------------- | -------------------------------------------------------
| `service_name` | `string`                  | The name of the application                 | `PHP_SAPI`
| `global_tags`  | `array`                   | Tags that will be added to every span       | `[]`
| `debug`        | `bool`                    | Toggle debug mode                           | `false`
| `logger`       | `Psr\Log\LoggerInterface` | An instance of a [PSR-3] logger.            | `null`
| `enabled`      | `bool`                    | If `false`, use a no-op tracer              | `true`
| `transport`    | `DDTrace\Transport`       | The `Transport` instance to use             | `new DDTrace\Http(new DDTrace\Json())`
| `propagators`  | `DDTrace\Propagator[]`    | An array of `Propagator` instances          | The text-map, HTTP-headers, and curl-headers propagators

[PSR-3]: https://www.php-fig.org/psr/psr-3/

```php
\DDTrace\Tracer::init(
    'my_base_trace',
    [
        'service_name' => 'My Application',
        'global_tags' => ['foo' => 'bar'],
        'transport' => new DDTrace\Stream(new DDTrace\Json()),
    ]
);
```

#### Manual instrumentation (wrapping)

If you need more flexibility, you can manually instantiate a tracer and wrap your application code with a [root span](https://docs.datadoghq.com/tracing/visualization/#spans) from the [tracer](https://docs.datadoghq.com/tracing/visualization/#trace).

```php
use DDTrace\Tracer;
use OpenTracing\GlobalTracer;
use DDTrace\Integrations\IntegrationsLoader;

// Creates a tracer with default transport and propagators
$tracer = new Tracer();

// Sets a global tracer (singleton)
GlobalTracer::set($tracer);
// Flushes traces to agent on script shutdown
register_shutdown_function(function() {
    GlobalTracer::get()->flush();
});

// Enable the built-in integrations
IntegrationsLoader::load();

// Start a root span
$span = $tracer->startSpan('my_base_trace');

// Run your application here
// $myApplication->run();

// Close the root span after the application code has finished
$span->finish();
```

#### Adding tracing to a custom function or method

To add spans for another function or method, you can use `dd_trace` to open a span before the code executes, close it when it’s done, set additional tags or errors on the span, and for more advanced usage, modify either the arguments or the return value.

Here’s an example of tracing the `CustomDriver` class’s `doWork` method, including reporting any exceptions as errors on the span and re-throwing them:

```php
dd_trace("CustomDriver", "doWork", function (...$args) {
    $scope = GlobalTracer::get()->startActiveSpan('CustomDriver.doWork’);
    $span = $scope->getSpan();

    // we can access object data via $this, and also execute 
    // the original method the same way 
    $span->setTag(Tags\RESOURCE_NAME, $this->workToDo);
    
    try {
        $result = $this->doWork(...$args);
        $span->setTag(‘doWork.size’, count($result));
        return $result;
    } catch (Exception $e) {
        $span->setError($e);
        throw $e
    } finally {
        $span->finish();
    }
});
```

## Configuration

It is possible to configure the agent connections parameters by means of env variables.

| Env variable               | Default     | Note                                                                   |
|----------------------------|-------------|------------------------------------------------------------------------|
| `DD_TRACE_ENABLED`         | `true`      | Globally enables the tracer                                            |
| `DD_INTEGRATIONS_DISABLED` |             | CSV list of disabled extensions, e.g. `curl,mysqli`                    |
| `DD_AGENT_HOST`            | `localhost` | The agent host name                                                    |
| `DD_TRACE_AGENT_PORT`      | `8126`      | The trace agent port number                                            |
| `DD_AUTOFINISH_SPANS`      | `false`     | Whether or not spans are automatically finished when tracer is flushed |
