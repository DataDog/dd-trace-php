JESSIE_PHP_VERSIONS:=5.6.jessie 7.0.jessie 7.1.jessie 7.2.jessie
STRETCH_PHP_VERSIONS:=5.6.stretch 7.0.stretch 7.1.stretch 7.2.stretch
CENTOS6_PHP_VERSIONS:=5.6.centos6 7.0.centos6 7.1.centos6 7.2.centos6
CENTOS7_PHP_VERSIONS:=5.6.centos7 7.0.centos7 7.1.centos7 7.2.centos7 #7.3.centos7
CENTOS7_MANUAL_PHP_VERSIONS:=71.centos7-compiled
ALPINE_PHP_VERSIONS:=5.6.alpine 7.0.alpine 7.1.alpine 7.2.alpine  #7.3.alpine # No ZTS tests - it fails on alpine due to relying on pthread and libc 5.6-zts.alpine 7.0-zts.alpine 7.1-zts.alpine 7.2-zts.alpine 7.3-zts.alpine

ROOT_DIR:=$(shell dirname $(realpath $(lastword $(MAKEFILE_LIST))))

.PHONY: $(JESSIE_PHP_VERSIONS) $(STRETCH_PHP_VERSIONS) $(CENTOS7_PHP_VERSIONS) $(ALPINE_PHP_VERSIONS) $(CENTOS7_MANUAL_PHP_VERSIONS) all all_centos all_alpine docker_compose_pull

ALL_VERSIONS_TO_TEST := $(CENTOS7_PHP_VERSIONS) $(ALPINE_PHP_VERSIONS) $(CENTOS6_PHP_VERSIONS) $(JESSIE_PHP_VERSIONS)

all: $(ALL_VERSIONS_TO_TEST)

all_centos: $(CENTOS6_PHP_VERSIONS) $(CENTOS7_PHP_VERSIONS)
all_alpine: $(ALPINE_PHP_VERSIONS)
all_5.6: 56.centos6 56.centos7 5.6.stretch 5.6.jessie 5.6.alpine 5.6-zts.alpine

docker_compose_pull:
	@docker-compose -f $(ROOT_DIR)/docker-compose.yml pull --parallel

$(JESSIE_PHP_VERSIONS): %.jessie:
	@echo Building Debian Jessie - PHP $*
	@docker build --no-cache -t deb_jessie:$* --build-arg php_version=$* -f dockerfiles/verify_packages/debian_jessie/Dockerfile .

$(STRETCH_PHP_VERSIONS): %.stretch:
	@echo Building Debian Stretch - PHP $*
	@docker build --no-cache -t deb_stretch:$* --build-arg php_version=$* -f dockerfiles/verify_packages/debian_stretch/Dockerfile .

$(CENTOS6_PHP_VERSIONS): %.centos6:
	@echo Verifying Centos 6 - PHP $*
	@docker-compose -f $(ROOT_DIR)/docker-compose.yml run -T --rm ${*}-centos6 sh /build_src/dockerfiles/verify_packages/verify_rpm.sh

$(CENTOS7_PHP_VERSIONS): %.centos7:
	@echo Verifying Centos 7 - PHP $*
	@docker-compose -f $(ROOT_DIR)/docker-compose.yml run -T --rm ${*}-centos7 sh /build_src/dockerfiles/verify_packages/verify_rpm.sh

$(CENTOS7_MANUAL_PHP_VERSIONS): %.centos7-compiled:
	@echo Building Centos 7 - PHP $*
	@docker build --no-cache -t centos_7_compiled:$* -f dockerfiles/verify_packages/centos7-compiled/Dockerfile .

$(ALPINE_PHP_VERSIONS): %.alpine:
	@echo Verifying Alpine - PHP $*
	@docker-compose -f $(ROOT_DIR)/docker-compose.yml run -T --rm ${*}-alpine sh /build_src/dockerfiles/verify_packages/verify_apk.sh
