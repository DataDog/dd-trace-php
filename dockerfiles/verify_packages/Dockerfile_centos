ARG CENTOS_VERSION=6
FROM centos:${CENTOS_VERSION}

ARG PHP_VERSION=54
ARG CENTOS_VERSION=6
RUN rpm -Uvh https://dl.fedoraproject.org/pub/epel/epel-release-latest-${CENTOS_VERSION}.noarch.rpm \
    && rpm -Uvh http://rpms.remirepo.net/enterprise/remi-release-${CENTOS_VERSION}.rpm \
    && yum install -y yum-utils
RUN yum-config-manager --enable remi-php${PHP_VERSION} \
    && yum install -y php php-cli php-xml \
    && yum clean all \
    && curl -q https://raw.githubusercontent.com/composer/getcomposer.org/1b137f8bf6db3e79a38a5bc45324414a6b1f9df2/web/installer | php -- php -- --filename=composer --install-dir=/usr/local/bin
