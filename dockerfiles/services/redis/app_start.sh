#!/usr/bin/env sh

set -e

redis-server /redis.6380.conf --appendfsync no --save  --appendonly no --port 6380 &
redis-server /redis.6381.conf --appendfsync no --save  --appendonly no --port 6381 &
redis-server /redis.6382.conf --appendfsync no --save  --appendonly no --port 6382 &
redis-server /redis.6383.conf --appendfsync no --save  --appendonly no --port 6383 &
redis-server /redis.6384.conf --appendfsync no --save  --appendonly no --port 6384 &
redis-server /redis.6385.conf --appendfsync no --save  --appendonly no --port 6385 &
redis-server /redis.6386.conf --appendfsync no --save  --appendonly no --port 6386 &
redis-server /redis.6387.conf --appendfsync no --save  --appendonly no --port 6387 &
redis-server /redis.6388.conf --appendfsync no --save  --appendonly no --port 6388 &
redis-server /redis.6389.conf --appendfsync no --save  --appendonly no --port 6389 &
redis-server /redis.6390.conf --appendfsync no --save  --appendonly no --port 6390 &
# Last (default) instance is blocking
redis-server /redis.6379.conf --appendfsync no --save  --appendonly no --port 6379