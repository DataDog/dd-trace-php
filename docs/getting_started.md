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

After the installation is complete, you'll need to [enable the extension](#enabling-the-extension).

### Installing the extension (manually)

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

### Compiling the extension from source

The extension can also be installed by compiling it from source. First [download the source code](https://github.com/DataDog/dd-trace-php/releases) from the releases page. Then compile and install the extension with the commands below.

```bash
$ cd /path/to/dd-trace-php
$ phpize
$ ./configure --enable-ddtrace
$ make
$ sudo make install
```

#### Bash one-liner

```bash
$ (cd $(mktemp -d); curl -L https://github.com/DataDog/dd-trace-php/archive/master.tar.gz | tar x --strip-components=1 && phpize && ./configure && make && sudo make install )
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
composer require datadog/dd-trace
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

Now your Laravel application should start sending traces to the Datadog agent running on localhost (in default configuration). The Datadog agent must have APM enabled; see [the APM documentation](https://docs.datadoghq.com/tracing/setup/) for instructions on installing and configuring the agent.

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

#### Zend Framework 1 integration

To enable the tracer in Zend Framework 1, [download the latest source code from the releases page](https://github.com/DataDog/dd-trace-php/releases). Extract the zip file and copy the `src/DDTrace` folder to your application's `library` folder. Then add the following to your `application/configs/application.ini` file.

```ini
autoloaderNamespaces[] = "DDTrace_"
pluginPaths.DDTrace = APPLICATION_PATH "/../library/DDTrace/Integrations/ZendFramework/V1"
resources.ddtrace = true
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
