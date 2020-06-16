7.3_VERSION = 7.3.15

build: 7.3.build

publish: 7.3.publish

%.build:
	@docker build --build-arg PHP_VERSION="${${*}_VERSION}" -t registry.ddbuild.io/apm-integrations-testing/handmade/php-laravel:$* .

%.publish: %.build
	@docker push registry.ddbuild.io/apm-integrations-testing/handmade/php-laravel:$*
