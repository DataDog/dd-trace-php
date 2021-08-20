The reason why we have this complex `composer.json` rather than a simpler

```
{
    "repositories": [
        {
            "type": "path",
            "url": "../../.."
        }
    ],
    "require": {
        "datadog/dd-trace": "@dev"
    }
}
```

Is that composer 1 does not handle declaring repositories in the current project hierarchy.

Composer 2, on the contrary, does via symlinks, but we [need composer 1 on PHP 5](https://github.com/DataDog/dd-trace-php/blob/d6174818f77832dfedf61cbe8e7b25a093d0f495/dockerfiles/ci/buster/php-5.6/Dockerfile#L110) dev images because of an issue with composer 2 and openssl/certificates on those images.

The reason why we copy into `/tmp/datadog/dd-trace` [instead of symlinking](https://github.com/composer/composer/issues/6085#issuecomment-287925698) is because we want to use [other users' previous experience in the real world](https://github.com/composer/composer/issues/6085#issuecomment-287924206) and avoid similar problems.

The reason why we are not just manually declaring entries in `autoload` like in

```
{
    "autoload": {
        "psr-4": {
            "DDTrace\\": "../../../src/api/"
        }
    }
}
```

is because we have to keep it is easy to have have them out of sync.
