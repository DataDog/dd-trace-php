FROM debian:jessie

# Sury.org no longer provides PHP packages for Jessie
# https://www.linuxcompatible.org/story/suryorg-has-discontinued-debian-8-support/
# So we just test the PHP version in the default repo (5.6)
RUN set -eux; \
    apt-get update; \
    apt-get -y install curl php5

ADD build/packages /packages
RUN set -eux; \
    dpkg -i /packages/*.deb; \
    php -m | grep ddtrace
