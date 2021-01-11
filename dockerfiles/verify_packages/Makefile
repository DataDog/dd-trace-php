ROOT_DIR:=$(shell dirname $(realpath $(lastword $(MAKEFILE_LIST))))
CENTOS6_PHP_VERSIONS:=5.4.centos6 5.5.centos6 5.6.centos6 7.0.centos6 7.1.centos6 7.2.centos6 7.3.centos6 7.4.centos6 8.0.centos6

.PHONY: $(CENTOS6_PHP_VERSIONS)

docker_compose_pull:
	@docker-compose -f $(ROOT_DIR)/docker-compose.yml pull --parallel

$(CENTOS6_PHP_VERSIONS): %.centos6:
	@echo Verifying Centos 6 - PHP $*
	@docker-compose -f $(ROOT_DIR)/docker-compose.yml run -T --rm ${*}-centos6 sh /build_src/dockerfiles/verify_packages/verify_rpm_centos6.sh

# The list of validated alpine images are in CI config. In case a package has to be validated locally, you can:
#  1. copy the .apk|.deb|.rpm into '<root>/build/packages'
#  2. From this folder:
#       - Example alpine: make verify_alpine DOCKER_IMAGE=alpine:3.10 PHP_PACKAGE="php7 php7-fpm"
#       - Example centos: make verify_centos DOCKER_IMAGE=centos:7 PHP_MAJOR=7 PHP_MINOR=4
#       - Example debian: make verify_debian DOCKER_IMAGE=debian:buster INSTALL_MODE=sury PHP_VERSION=7.4
verify_alpine:
	docker-compose run --rm \
		-e VERIFY_APACHE=no \
		-e PHP_PACKAGE="$(PHP_PACKAGE)" \
		-e DOCKER_IMAGE="$(DOCKER_IMAGE)" \
		-e OS_NAME=alpine \
		alpine \
		dockerfiles/verify_packages/verify.sh

verify_debian:
	docker-compose run --rm \
		-e INSTALL_MODE="$(INSTALL_MODE)" \
		-e PHP_PACKAGE="$(PHP_PACKAGE)" \
		-e PHP_VERSION="$(PHP_VERSION)" \
		-e DOCKER_IMAGE="$(DOCKER_IMAGE)" \
		-e VERIFY_APACHE="$(VERIFY_APACHE)" \
		-e PHP_FPM_BIN="$(PHP_FPM_BIN)" \
		debian \
		bash dockerfiles/verify_packages/verify.sh

verify_centos:
	docker-compose run --rm \
		-e PHP_MAJOR="$(PHP_MAJOR)" \
		-e PHP_MINOR="$(PHP_MINOR)" \
		-e DOCKER_IMAGE="$(DOCKER_IMAGE)" \
		centos \
		dockerfiles/verify_packages/verify.sh

# Centos 6 is verified using the existing images due to difficulties building it after the repos have been disabled.
verify_centos_6: $(CENTOS6_PHP_VERSIONS)
