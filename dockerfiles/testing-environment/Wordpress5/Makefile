build: 5.6.build 7.3.build

publish: 5.6.publish 7.3.publish

5.6.build:
	@docker build --build-arg PHP_VERSION=5.6 -t registry.ddbuild.io/apm-integrations-testing/handmade/php-wp5:5.6 .

5.6.shell: 5.6.build
	@docker run --rm -ti registry.ddbuild.io/apm-integrations-testing/handmade/php-wp5:5.6 bash

5.6.publish: 5.6.build
	@docker push registry.ddbuild.io/apm-integrations-testing/handmade/php-wp5:5.6

7.3.build:
	@docker build --build-arg PHP_VERSION=7.3 -t registry.ddbuild.io/apm-integrations-testing/handmade/php-wp5:7.3 .

7.3.shell: 7.3.build
	@docker run --rm -ti registry.ddbuild.io/apm-integrations-testing/handmade/php-wp5:7.3 bash

7.3.publish: 7.3.build
	@docker push registry.ddbuild.io/apm-integrations-testing/handmade/php-wp5:7.3
