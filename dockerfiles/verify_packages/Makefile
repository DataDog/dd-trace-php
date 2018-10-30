DEB_PHP_VERSIONS:=7.0.deb 7.1.deb 7.2.deb
CENTOS6_PHP_VERSIONS=70.centos6 71.centos6
CENTOS7_PHP_VERSIONS=70.centos7 71.centos7 72.centos7
ALPINE_PHP_VERSIONS=7.0.alpine 7.1.alpine 7.2.alpine 7.0-zts.alpine 7.1-zts.alpine 7.2-zts.alpine

.PHONY: $(DEB_PHP_VERSIONS) $(CENTOS7_PHP_VERSIONS) $(ALPINE_PHP_VERSIONS) all

all: $(DEB_PHP_VERSIONS) $(CENTOS6_PHP_VERSIONS) $(CENTOS7_PHP_VERSIONS) $(ALPINE_PHP_VERSIONS)

$(DEB_PHP_VERSIONS): %.deb:
	@echo Building Debian Jessie - PHP $*
	@docker build -t deb_jessie:$* --build-arg php_version=$* -f dockerfiles/verify_packages/debian_jessie/Dockerfile .

$(CENTOS7_PHP_VERSIONS): %.centos7:
	@echo Building Centos 7 - PHP $*
	@docker build -t centos_7:$* --build-arg php_version=$* -f dockerfiles/verify_packages/centos7/Dockerfile .

$(CENTOS6_PHP_VERSIONS): %.centos6:
	@echo Building Centos 6 - PHP $*
	@docker build -t centos_6:$* --build-arg php_version=$* -f dockerfiles/verify_packages/centos6/Dockerfile .


$(ALPINE_PHP_VERSIONS): %.alpine:
	@echo Building Alpine - PHP $*
	@test -f dockerfiles/verify_packages/alpine/Dockerfile && rm dockerfiles/verify_packages/alpine/Dockerfile || true
	@sed -e "s/%%php_version%%/$*-alpine/g" dockerfiles/verify_packages/alpine/Dockerfile.template > dockerfiles/verify_packages/alpine/Dockerfile
	@docker build -t alpine_php:$* -f dockerfiles/verify_packages/alpine/Dockerfile .
	@test -f dockerfiles/verify_packages/alpine/Dockerfile && rm dockerfiles/verify_packages/alpine/Dockerfile

