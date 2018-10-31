FROM centos:6

RUN curl -s 'https://setup.ius.io/' | bash -s --
ADD build/packages /packages
ARG php_version
RUN yum install -y php${php_version}u-cli
RUN rpm -ivh /packages/*.rpm

RUN php -m | grep ddtrace
