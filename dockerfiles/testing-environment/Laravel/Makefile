build: 7.3.build 8.0.build

publish: 7.3.publish 8.0.publish

7.3.build:
	docker build --build-arg PHP_VERSION="7.3" --build-arg APP_PATH="./57" -t registry.ddbuild.io/apm-integrations-testing/handmade/php-laravel:7.3 .

8.0.build:
	docker build --build-arg PHP_VERSION="8.0" --build-arg APP_PATH="./8x" -t registry.ddbuild.io/apm-integrations-testing/handmade/php-laravel:8.0 .

%.shell: %.build
	docker-compose run --rm $* bash

%.publish: %.build
	docker push registry.ddbuild.io/apm-integrations-testing/handmade/php-laravel:$*
