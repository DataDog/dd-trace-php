BUILD_OPTS :=
DURATION := 60s
UBUNTU_IMAGE_PREFIX := random-testing:ubuntu-php

build: 8.0.ubuntu.build

# PHP 8.0 on ubuntu
8.0.ubuntu.build:
	docker build $(BUILD_OPTS) --build-arg PHP_VERSION="8.0" -t $(UBUNTU_IMAGE_PREFIX)8.0 .
8.0.ubuntu.up: 8.0.ubuntu.build
	docker-compose up -d 8.0.ubuntu
8.0.ubuntu.load:
	echo "GET http://localhost:8880" | vegeta attack -format=http -duration=${DURATION} -keepalive=false -max-workers=30 -rate=0 | tee results.bin | vegeta report
