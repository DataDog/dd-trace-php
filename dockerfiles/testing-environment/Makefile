BUILD_OPTS :=
DURATION := 60s

# PHP 7.4 on ubuntu
7.4.ubuntu.build:
	docker build $(BUILD_OPTS) --build-arg PHP_VERSION="7.4" -t testing-environment:ubuntu-php7.4 -f dockerfiles/ubuntu/Dockerfile .
7.4.ubuntu.random.build: 7.4.ubuntu.build
	docker build $(BUILD_OPTS) --build-arg PHP_VERSION="7.4" --build-arg APP_PATH="./RandomTesting" -t registry.ddbuild.io/apm-integrations-testing/handmade/php-randomtests:7.4 -f dockerfiles/ubuntu.random/Dockerfile .
7.4.ubuntu.random.up: 7.4.ubuntu.random.build
	docker-compose up -d 7.4.ubuntu.random
7.4.ubuntu.random.load:
	echo "GET http://localhost:6666?seed=460" | vegeta attack -format=http -duration=${DURATION} -keepalive=false -max-workers=30 -rate=0 | tee results.bin | vegeta report

# PHP 8.0 on ubuntu
8.0.ubuntu.build:
	docker build $(BUILD_OPTS) --build-arg PHP_VERSION="8.0" -t testing-environment:ubuntu-php8.0 -f dockerfiles/ubuntu/Dockerfile .
8.0.ubuntu.random.build: 8.0.ubuntu.build
	echo "Hi\n"
8.0.ubuntu.shell: 8.0.ubuntu.build
	docker run --rm -ti testing-environment:ubuntu-php8.0 bash
