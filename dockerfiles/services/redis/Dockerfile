FROM circleci/redis:5.0-alpine

RUN apk add gettext

ADD app_start.sh /app_start.sh
# ADD conf_template.conf /conf_template.conf
# ADD prepare-server.sh /prepare-server.sh

# # Simple Redis images
# RUN /prepare-server.sh 6379 conf_template.conf
# RUN /prepare-server.sh 6380 conf_template.conf
# RUN /prepare-server.sh 6381 conf_template.conf
# RUN /prepare-server.sh 6382 conf_template.conf
# RUN /prepare-server.sh 6383 conf_template.conf
# RUN /prepare-server.sh 6384 conf_template.conf
# RUN /prepare-server.sh 6385 conf_template.conf
# RUN /prepare-server.sh 6386 conf_template.conf
# RUN /prepare-server.sh 6387 conf_template.conf
# RUN /prepare-server.sh 6388 conf_template.conf
# RUN /prepare-server.sh 6389 conf_template.conf
# RUN /prepare-server.sh 6390 conf_template.conf


# Redis cluster
ADD conf_template_cluster.conf /conf_template_cluster.conf
RUN mkdir -p \
        /redis-service/clusters/7001/data \
        /redis-service/clusters/7002/data \
        /redis-service/clusters/7003/data \
        /redis-service/clusters/7004/data \
        /redis-service/clusters/7005/data \
        /redis-service/clusters/7006/data

# EXPOSE 6379 6380 6381 6382 6383 6384 6385 6386 6387 6388 6389 6390 \
#        7001 7002 7003 7004 7005 7006

CMD /app_start.sh
