
FROM ubuntu:18.04

RUN apt-get update && apt-get install -y vim valgrind software-properties-common
RUN LC_ALL=C.UTF-8 add-apt-repository ppa:ondrej/php && apt-get update
ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get install php7.2-cli php7.2-dev -y
RUN apt-get install build-essential -y
RUN apt-get install php7.2-curl php7.2-opcache php7.2-xml php7.2-xmlrpc php7.2-phpdbg php7.2-json php7.2-gd -y
RUN apt-get install wget -y
RUN wget https://github.com/DataDog/dd-trace-php/releases/download/0.7.0/datadog-php-tracer_0.7.0-beta_amd64.deb
RUN dpkg -i datadog-php-tracer_0.7.0-beta_amd64.deb
RUN apt-get install -y php7.2-bcmath php7.2-bz2 php7.2-cgi php7.2-cli php7.2-common php7.2-curl php7.2-dba php7.2-enchant php7.2-fpm php7.2-gd php7.2-gmp php7.2-imap php7.2-interbase php7.2-intl php7.2-json php7.2-ldap php7.2-mbstring php7.2-mysql php7.2-odbc php7.2-opcache php7.2-pgsql php7.2-phpdbg php7.2-pspell php7.2-readline php7.2-recode php7.2-snmp php7.2-soap php7.2-sqlite3 php7.2-sybase php7.2-tidy php7.2-xml php7.2-xmlrpc php7.2-xsl php7.2-zip
RUN apt-get install -y php-gearman php-raphf php-propro php-http php-msgpack
RUN apt-get install -y php-apcu
RUN pecl install pcs-beta
RUN echo extension=pcs.so | tee /etc/php/7.2/cli/conf.d/20-pcs.ini

CMD ["bash"]

ENTRYPOINT ["/bin/bash", "-c"]
