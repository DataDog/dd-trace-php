# datadog/dd-trace-ci

The older images can be found in the [DataDog/dd-trace-ci](https://github.com/DataDog/dd-trace-ci/tree/master/php) repo.

Build and push a specific image:

```
docker-compose build --no-cache --pull <image_name> && docker-compose push <image_name>
```

Build and push all images:

```
docker-compose build --no-cache --pull && docker-compose push
```
