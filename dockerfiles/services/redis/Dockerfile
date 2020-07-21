FROM circleci/redis:3.0-alpine

ADD app_start.sh /app_start.sh
ADD conf_template.conf /conf_template.conf
ADD prepare-server.sh /prepare-server.sh

RUN /prepare-server.sh 6379
RUN /prepare-server.sh 6380
RUN /prepare-server.sh 6381
RUN /prepare-server.sh 6382
RUN /prepare-server.sh 6383
RUN /prepare-server.sh 6384
RUN /prepare-server.sh 6385
RUN /prepare-server.sh 6386
RUN /prepare-server.sh 6387
RUN /prepare-server.sh 6388
RUN /prepare-server.sh 6389
RUN /prepare-server.sh 6390

EXPOSE 6379 6380 6381 6382 6383 6384 6385 6386 6387 6388 6389 6390

CMD /app_start.sh
