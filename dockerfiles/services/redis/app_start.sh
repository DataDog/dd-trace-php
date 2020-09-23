#!/usr/bin/env sh

set -e

# redis-server /redis.6379.conf --appendfsync no --save  --appendonly no --port 6379
# redis-server /redis.6380.conf --appendfsync no --save  --appendonly no --port 6380
# redis-server /redis.6381.conf --appendfsync no --save  --appendonly no --port 6381
# redis-server /redis.6382.conf --appendfsync no --save  --appendonly no --port 6382
# redis-server /redis.6383.conf --appendfsync no --save  --appendonly no --port 6383
# redis-server /redis.6384.conf --appendfsync no --save  --appendonly no --port 6384
# redis-server /redis.6385.conf --appendfsync no --save  --appendonly no --port 6385
# redis-server /redis.6386.conf --appendfsync no --save  --appendonly no --port 6386
# redis-server /redis.6387.conf --appendfsync no --save  --appendonly no --port 6387
# redis-server /redis.6388.conf --appendfsync no --save  --appendonly no --port 6388
# redis-server /redis.6389.conf --appendfsync no --save  --appendonly no --port 6389
# redis-server /redis.6390.conf --appendfsync no --save  --appendonly no --port 6390



# Cluster
for instance_port in $(seq 7001 7006)
do
    INSTANCE_PORT=${instance_port} envsubst < /conf_template_cluster.conf > /redis-service/clusters/${instance_port}/redis.conf
    redis-server /redis-service/clusters/${instance_port}/redis.conf
done
sleep 1
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

tail -f /dev/null
