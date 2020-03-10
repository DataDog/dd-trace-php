7.3_VERSION = 7.3.15

ALL_VERSIONS = 7.3

all: $(addsuffix .build,ALL_VERSIONS)
	@:

%.build:
	@docker build --build-arg PHP_VERSION="${${*}_VERSION}" -t registry.ddbuild.io/apm-integrations-testing/handmade/php-laravel:$* .

%.publish: %.build
	@docker push registry.ddbuild.io/apm-integrations-testing/handmade/php-laravel:$*
