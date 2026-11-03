## Build for x86_64

Push a new `-N` tag for each rebuild and add it to ddoghq/images `mirror.yaml`:
CI pulls `registry.ddbuild.io/images/mirror/datadog/dd-trace-ci:<tag>`.

```
docker buildx build --platform=linux/amd64 -t datadog/dd-trace-ci:php_fpm_packaging-2 --push .
```
