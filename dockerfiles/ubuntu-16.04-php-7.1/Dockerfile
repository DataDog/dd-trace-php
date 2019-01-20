
FROM ubuntu:16.04

RUN apt-get update && apt-get install -y vim valgrind software-properties-common
RUN LC_ALL=C.UTF-8 add-apt-repository ppa:ondrej/php && apt-get update
RUN apt-get install php7.1-cli php7.1-dev -y
RUN apt-get install build-essential -y
RUN apt-get install php7.1-curl php7.1-opcache php7.1-xml php7.1-xmlrpc php7.1-phpdbg php7.1-json php7.1-gd -y
CMD ["bash"]

ENTRYPOINT ["/bin/bash", "-c"]
