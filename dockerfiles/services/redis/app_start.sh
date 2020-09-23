#!/usr/bin/env sh

set -e

# non-cluster: we run 12 instances for RedisArray tests
for instance_port in $(seq 6379 6390)
do
    mkdir -p /redis-service/clusters/${instance_port}/data
    INSTANCE_PORT=${instance_port} envsubst < /conf_template.conf > /redis-service/clusters/${instance_port}/redis.conf
    redis-server /redis-service/clusters/${instance_port}/redis.conf
done

# cluster
for instance_port in $(seq 7001 7006)
do
    mkdir -p /redis-service/clusters/${instance_port}/data
    INSTANCE_PORT=${instance_port} envsubst < /conf_template_cluster.conf > /redis-service/clusters/${instance_port}/redis.conf
    redis-server /redis-service/clusters/${instance_port}/redis.conf
done
sleep 1
# We need teh actual IP to be exposed as the cluster IP, otherwise it is not possible to connect from
# other containers (or maybe we just don't know how).
DOCKER_IP=$(ip a | grep inet | grep eth0 | awk '{print $2}' | awk -F  "/" '{print $1}')
redis-cli --cluster create \
    ${DOCKER_IP}:7001 \
    ${DOCKER_IP}:7002 \
    ${DOCKER_IP}:7003 \
    ${DOCKER_IP}:7004 \
    ${DOCKER_IP}:7005 \
    ${DOCKER_IP}:7006 \
    --cluster-replicas 1 \
    --cluster-yes

# Keep the container running
tail -f /dev/null
