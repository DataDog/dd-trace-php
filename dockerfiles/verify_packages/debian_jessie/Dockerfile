FROM debian:jessie

RUN apt-get update && apt-get -y install apt-transport-https lsb-release ca-certificates
RUN apt-get install curl -y
RUN curl -o /etc/apt/trusted.gpg.d/php.gpg https://packages.sury.org/php/apt.gpg
RUN echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" | tee /etc/apt/sources.list.d/php.list
RUN apt-get update
ADD build/packages /packages

ARG php_version
RUN apt-get -y --force-yes install php${php_version}

RUN dpkg -i /packages/*.deb
RUN php -m | grep ddtrace
