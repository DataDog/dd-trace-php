# Apache + mod_php example

Example to showcase how to configure tracing with Apache and mod_php.

## Tracer installation

Refer to the [Dockerfile](Dockerfile) in this example for the one liner installation command.

## Configuration

In Apache + mod_php applications we have two different ways to set environment variables to configure the tracer.

1. Any environment variable set in the host machine is visible to the PHP process, unless you have some specific configuration. In this example the environment variable `DD_AGENT_HOST` is set at the host level in the [docker-compose.yml](docker-compose.yml) file.

2. Apache `SetEnv` directive can be used to configure additional per-virtualhost settings. See `DD_TRACE_AGENT_PORT` configured in the [virtual-host.conf](virtual-host.conf) file for this example.

## How to run this app

*Note*: You need the environment variable `DATADOG_API_KEY` set on your machine with your api key.

From this directory

```
docker compose build

docker compose up -d
```

Then you can access the sample `index.php` file at [http://localhost:8889](http://localhost:8889).

```
$ curl localhost:8889
Agent configured as HOST environment variable: agent
Port configured in Virtual-Host via SetEnv: 8126
Hi!
```

After a few seconds your traces will be visible in your dashboard: [US](https://app.datadoghq.com/apm/traces) or [EU](https://app.datadoghq.eu/apm/traces).
