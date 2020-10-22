JESSIE_PHP_VERSIONS:=5.6.jessie # We don't test PHP 7 on Jessie; PHP packages on sury.org went EOL June 30, 2020
STRETCH_PHP_VERSIONS:=5.6.stretch 7.0.stretch 7.1.stretch 7.2.stretch 8.0.stretch
CENTOS6_PHP_VERSIONS:=5.4.centos6 5.6.centos6 7.0.centos6 7.1.centos6 7.2.centos6 8.0.centos6
CENTOS7_PHP_VERSIONS:=5.4.centos7 5.6.centos7 7.0.centos7 7.1.centos7 7.2.centos7 #7.3.centos7
CENTOS7_MANUAL_PHP_VERSIONS:=71.centos7-compiled

ROOT_DIR:=$(shell dirname $(realpath $(lastword $(MAKEFILE_LIST))))

.PHONY: $(JESSIE_PHP_VERSIONS) $(STRETCH_PHP_VERSIONS) $(CENTOS7_PHP_VERSIONS) $(ALPINE_PHP_VERSIONS) $(CENTOS7_MANUAL_PHP_VERSIONS) all all_centos all_alpine docker_compose_pull

ALL_VERSIONS_TO_TEST := $(CENTOS7_PHP_VERSIONS) $(ALPINE_PHP_VERSIONS) $(CENTOS6_PHP_VERSIONS) $(JESSIE_PHP_VERSIONS) 5.5_centos-6

all: $(ALL_VERSIONS_TO_TEST)

all_centos: $(CENTOS6_PHP_VERSIONS) $(CENTOS7_PHP_VERSIONS)
all_5.6: 56.centos6 56.centos7 5.6.stretch 5.6.jessie 5.6.alpine 5.6-zts.alpine

docker_compose_pull:
	@docker-compose -f $(ROOT_DIR)/docker-compose.yml pull --parallel

$(JESSIE_PHP_VERSIONS): %.jessie:
	@echo Building Debian Jessie - PHP $*
	@docker build --no-cache -t deb_jessie:$* --build-arg php_version=$* -f dockerfiles/verify_packages/debian_jessie/Dockerfile .

$(STRETCH_PHP_VERSIONS): %.stretch:
	@echo Building Debian Stretch - PHP $*
	@docker build --no-cache -t deb_stretch:$* --build-arg php_version=$* -f dockerfiles/verify_packages/debian_stretch/Dockerfile .

5.5_centos-6:
	@echo Verifying Centos 6 - PHP 5.5
	@docker-compose -f $(ROOT_DIR)/docker-compose.yml run -T --rm 5.5-centos6 bash /build_src/dockerfiles/verify_packages/verify_rpm_5.5.sh

$(CENTOS6_PHP_VERSIONS): %.centos6:
	@echo Verifying Centos 6 - PHP $*
	@docker-compose -f $(ROOT_DIR)/docker-compose.yml run -T --rm ${*}-centos6 sh /build_src/dockerfiles/verify_packages/verify_rpm.sh

$(CENTOS7_PHP_VERSIONS): %.centos7:
	@echo Verifying Centos 7 - PHP $*
	@docker-compose -f $(ROOT_DIR)/docker-compose.yml run -T --rm ${*}-centos7 sh /build_src/dockerfiles/verify_packages/verify_rpm.sh

$(CENTOS7_MANUAL_PHP_VERSIONS): %.centos7-compiled:
	@echo Building Centos 7 - PHP $*
	@docker build --no-cache -t centos_7_compiled:$* -f dockerfiles/verify_packages/centos7-compiled/Dockerfile .

# The list of validated alpine images are in CI config. In case a package has to be validated locally, you can:
#  1. copy the .apk into '<root>/build/packages' (it must be the only .apk in that directory)
#  2. from this folder: make verify_alpine ALPINE_IMAGE=<alpine image> PHP_PACKAGE=<optional apk php package to install>
#         - make verify_alpine ALPINE_IMAGE=alpine:3.10 PHP_PACKAGE=php7
#         - make verify_alpine ALPINE_IMAGE=php:7.3-fpm-alpine
verify_alpine:
	docker-compose run --rm -e PHP_PACKAGE="$(PHP_PACKAGE)" -e ALPINE_IMAGE="$(ALPINE_IMAGE)" -e OS_NAME=alpine alpine dockerfiles/verify_packages/verify.sh
