# Datadog PHP integration

Datadog's PHP APM integration provides the ability to trace critical parts of your PHP applications by automatically instrumenting code and submitting data to Datadog’s APM service using the OpenTracing API.

To provide a great out-of-the-box experience that automatically instruments common libraries without requiring intrusive code changes or wrapping every object, we provide a PHP extension that allows introspecting any PHP function or method.

## Datadog PHP extension installation

Datadog’s PHP extension (`ddtrace`) allows introspection of arbitrary PHP code.

At this moment it is only distributed in source code form, and requires manual compilation.

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

To enable Laravel integration we need to configure a new Provider in `config/app.php`

```php
    'providers' => [
# .....
      'DDTrace\Integrations\LaravelProvider',
```

Now your Laravel application should start sending traces to the Datadog agent running on localhost (in default configuration). The Datadog agent must have APM enabled; see https://docs.datadoghq.com/tracing/setup/ for instructions on installing and configuring the agent.

#### Symfony integration

For Symfony applications, add the bundle in `config/bundles.php`:


```php
    return [
        // ...
        DDTrace\Integrations\SymfonyBundle::class => ['all' => true],
        // ...
    ];
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
    $span->setResource($this->workToDo);
    
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
