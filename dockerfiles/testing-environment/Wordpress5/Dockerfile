FROM ubuntu:18.04

ENV DEBIAN_FRONTEND=noninteractive

# Install common tools
ADD docker-scripts/install-common.sh /scripts/install-common.sh
RUN /scripts/install-common.sh

# Install PHP 7.3
ADD docker-scripts/install-php.sh /scripts/install-php.sh
RUN /scripts/install-php.sh

CMD bash
