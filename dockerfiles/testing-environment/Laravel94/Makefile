build: 8.2.build

publish: 8.2.publish

%.build:
	@docker build --build-arg PHP_VERSION="$*" -t registry.ddbuild.io/apm-integrations-testing/handmade/php-laravel:$* .

%.shell: %.build
	@docker-compose run --rm $* bash

%.publish: %.build
	@docker push registry.ddbuild.io/apm-integrations-testing/handmade/php-laravel:$*
