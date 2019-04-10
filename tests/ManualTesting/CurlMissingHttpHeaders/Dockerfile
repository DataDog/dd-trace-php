FROM ubuntu:16.04

RUN apt update \
    && apt install -y \
        build-essential \
        curl libcurl4-openssl-dev \
        wget \
        gdb gdbserver \
        libxml2-dev \
        autoconf re2c bison \
        software-properties-common \
        python-software-properties \
    && rm -rf /var/lib/apt/lists/*

# for gdbserver
EXPOSE 2000

#RUN LC_ALL=C.UTF-8 add-apt-repository -y ppa:ondrej/php \
#    && printf "\ndeb http://ppa.launchpad.net/ondrej/php/ubuntu xenial main/debug\n" \
#       | tee -a /etc/apt/sources.list.d/ondrej-ubuntu-php-xenial.list \
#    && apt update \
#    && DEBIAN_FRONTEND=noninteractive apt install -y \
#        php7.2-cli-dbgsym \
#        php7.2-common-dbgsym \
#        php7.2-curl-dbgsym \
#        php7.2-dev \
#    && rm -rf /var/lib/apt/lists/*

RUN mkdir -p /php/7.2/conf.d \
    && cd /php/7.2 \
    && wget https://github.com/php/php-src/archive/PHP-7.2.tar.gz \
    && tar -xzf PHP-7.2.tar.gz \
    && cd php-src-PHP-7.2 \
    && ./buildconf --force \
    && ./configure \
    --disable-phpdbg \
    --enable-cli \
    --enable-debug \
    --prefix=/php/7.2 \
    --with-config-file-path=/php/7.2 \
    --with-config-file-scan-dir=/php/7.2/conf.d \
    --with-curl \
    && make -j5 \
    && make install
#RUN /bin/cp /php/7.2/php.ini-development /php/7.2/php.ini \
#    && printf '\nexport PATH="/php/7.2/bin:${PATH?}"\n' | tee -a /root/.bashrc
RUN printf '\nexport PATH="/php/7.2/bin:${PATH?}"\n' | tee -a /root/.bashrc

# Install ddtrace - from source
# First run the following from `~/dd/dd-trace-php`:
# $ git archive --format=tar.gz -o ./tests/ManualTesting/CurlMissingHttpHeaders/ddtrace.tar.gz HEAD
# $ tar --exclude="*.lo" -czf ./tests/ManualTesting/CurlMissingHttpHeaders/ddtrace.tar.gz ./src ./bridge ./config.m4
COPY ddtrace.tar.gz /src/ddtrace/
RUN cd /src/ddtrace \
    && tar -xzf ddtrace.tar.gz \
    && /php/7.2/bin/phpize \
    && ./configure --enable-ddtrace \
       --with-php-config=/php/7.2/bin/php-config \
    && CFLAGS="-g" make -j9 \
    && make install \
    && printf "extension=ddtrace.so\nddtrace.request_init_hook=/src/ddtrace/bridge/dd_wrap_autoloader.php" \
        | tee /php/7.2/conf.d/ddtrace.ini \
    && printf "\n\n🐶 ddtrace successfully installed - WOOF!\n\n"

CMD bash
#WORKDIR /var/app
#ENTRYPOINT ["/php/7.2/bin/php ./playground.php"]
