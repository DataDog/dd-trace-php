
FROM ubuntu:18.04

RUN apt-get update && apt-get install -y vim valgrind software-properties-common
RUN LC_ALL=C.UTF-8 add-apt-repository ppa:ondrej/php \
  && echo deb http://ppa.launchpad.net/ondrej/php/ubuntu bionic main/debug | \
    tee -a /etc/apt/sources.list.d/ondrej-ubuntu-php-bionic.list \
  && apt-get update
ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get install php7.2-cli-dbgsym php7.2-dev -y
RUN apt-get install build-essential -y
RUN apt-get install php7.2-curl-dbgsym php7.2-opcache-dbgsym php7.2-xml-dbgsym \
    php7.2-xmlrpc-dbgsym php7.2-phpdbg-dbgsym php7.2-json-dbgsym php7.2-gd-dbgsym -y
RUN apt-get install wget -y
RUN wget https://github.com/DataDog/dd-trace-php/releases/download/0.7.0/datadog-php-tracer_0.7.0-beta_amd64.deb
RUN dpkg -i datadog-php-tracer_0.7.0-beta_amd64.deb
RUN apt-get install -y php7.2-bcmath-dbgsym php7.2-bz2-dbgsym php7.2-cgi-dbgsym \
    php7.2-cli-dbgsym php7.2-common-dbgsym php7.2-curl-dbgsym php7.2-dba-dbgsym \
    php7.2-enchant-dbgsym php7.2-fpm-dbgsym php7.2-gd-dbgsym php7.2-gmp-dbgsym \
    php7.2-imap-dbgsym php7.2-interbase-dbgsym php7.2-intl-dbgsym php7.2-json-dbgsym \
    php7.2-ldap-dbgsym php7.2-mbstring-dbgsym php7.2-mysql-dbgsym php7.2-odbc-dbgsym \
    php7.2-opcache-dbgsym php7.2-pgsql-dbgsym php7.2-phpdbg-dbgsym php7.2-pspell-dbgsym \
    php7.2-readline-dbgsym php7.2-recode-dbgsym php7.2-snmp-dbgsym php7.2-soap-dbgsym \
    php7.2-sqlite3-dbgsym php7.2-sybase-dbgsym php7.2-tidy-dbgsym php7.2-xml-dbgsym \
    php7.2-xmlrpc-dbgsym php7.2-xsl php7.2-zip-dbgsym
RUN apt-get install -y php-gearman php-raphf php-propro php-http php-msgpack
RUN apt-get install -y php-apcu
RUN pecl install pcs-beta
RUN echo extension=pcs.so | tee /etc/php/7.2/cli/conf.d/20-pcs.ini

CMD ["bash"]

ENTRYPOINT ["/bin/bash", "-c"]
