# datadog/dd-trace-ci

The older images can be found in the [DataDog/dd-trace-ci](https://github.com/DataDog/dd-trace-ci/tree/master/php) repo.

Build and push a specific image:

```
docker-compose build <image_name> && docker-compose push <image_name>
```

Build and push all images:

```
docker-compose build && docker-compose push
```
