FROM php:7.2-alpine

ADD build/packages /packages

RUN apk add /packages/*.apk --allow-untrusted
RUN php -m | grep ddtrace
