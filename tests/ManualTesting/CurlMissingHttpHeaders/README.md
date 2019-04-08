From the `ManualTesting` directory execute

```
docker-compose run --rm curl_missing_http_headers
```

The expected result should be

```
Sent headers: Array
(
    [headers] => Array
        (
            [Accept] => application/json
            [Host] => payments.api.com
            [X-Datadog-Parent-Id] => 6628485844311341885
            [X-Datadog-Sampling-Priority] => 1
            [X-Datadog-Trace-Id] => 9026557315404395210
        )

)
```

Instead it is

```
Sent headers: Array
(
    [headers] => Array
        (
            [Accept] => */*
            [Host] => httpbin
            [X-Datadog-Parent-Id] => 6628485844311341885
            [X-Datadog-Sampling-Priority] => 1
            [X-Datadog-Trace-Id] => 9026557315404395210
        )

)
```
