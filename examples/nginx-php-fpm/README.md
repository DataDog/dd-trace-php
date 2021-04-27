# Nginx + php-fpm example

Example of how to configure tracing with nginx and php-fpm.

## Nginx configuration

Nothing specific has to change here. [default.conf](default.conf) file you see in this example has no changes required specific to the tracing library.

*Note*: if you come from an older version you might have used `fastcgi_param`s to configure  the tracer. This approach is now deprecated in favor of `env` directive in the fpm pool configuration. See next section.

## PHP-FPM configuration

You can configure the Datadog PHP tracing library via [environment variables](https://docs.datadoghq.com/tracing/setup/php/#environment-variable-configuration).

By default environment variables set in the host are not visible to PHP-FPM process.

If `clear_env` is not set or `clear_env=1` then `env` directive can be used in the `www.conf` file to set a specific setting. See this app's [www.conf](./www.conf) file for an example.

If `clear_env=0` then environment variables values from the host are visibile to the PHP process and using the `env` directive is not required.

See PHP-FPM's [configuration page](https://www.php.net/manual/en/install.fpm.configuration.php) for more details.

## How to run this app

*Note*: You need the environment variable `DATADOG_API_KEY` set on your machine with your api key.

From this directory

```
docker-compose build

docker-compose up -d
```

Then you can access the sample `index.php` file at [http://localhost:8888](http://localhost:8888).

```
$ curl localhost:8888
Hi
```

After a few seconds your traces will be visible in your dashboard: [US](https://app.datadoghq.com/apm/traces) or [EU](https://app.datadoghq.eu/apm/traces).
