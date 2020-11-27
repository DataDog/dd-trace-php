BUILD_OPTS :=

8.0.ubuntu.build:
	docker build $(BUILD_OPTS) --build-arg PHP_VERSION="8.0" -t testing-environment:ubuntu-php8.0 -f dockerfiles/ubuntu/Dockerfile .

8.0.ubuntu.shell: 8.0.ubuntu.build
	docker run --rm -ti testing-environment:ubuntu-php8.0 bash
