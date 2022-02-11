

target "package" {
    dockerfile = "dockerfiles/packaging/Dockerfile"
    target = "export"
    output = ["build/package"]
}

target "package-circleci" {
    dockerfile = "dockerfiles/packaging/Dockerfile"
    target = "export"
    cache-from = ["type=local,src=build/cache"]
    cache-to = ["type=local,dest=build/cache"]
    output = ["build/packages"]
}

target "package-github" {
    dockerfile = "dockerfiles/packaging/Dockerfile"
    target = "export"
    // TODO remove or implement build caching in dd-trace-php repo
    /* cache-from = ["type=registry,ref=ghcr.io/pawelchcki/dd-trace-php"]  */
    /* cache-to = ["type=registry,ref=ghcr.io/pawelchcki/dd-trace-php"] */
    output = ["build/package"]
}

target "extensions" {
    inherits = ["package"]
    output = ["build/exts/"]
    target = "extensions"
}

target "build-5-4" {
    inherits = ["package"]
    target = "php-5.4-debug"
    output = []
}
