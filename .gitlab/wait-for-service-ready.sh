#!/usr/bin/env bash
set -euo pipefail

HOST=$1
PORT=$2
SERVICE_TYPE=${3:-generic}
MAX_ATTEMPTS=${4:-30}
RETRY_DELAY=${5:-5}

# Wait for port to open
echo "Waiting for $HOST:$PORT to be reachable..."
wait-for "$HOST:$PORT" --timeout=180

# Service-specific health checks with retry
for i in $(seq 1 $MAX_ATTEMPTS); do
  case $SERVICE_TYPE in
    test-agent)
      if curl -sf "http://$HOST:$PORT/info" > /dev/null 2>&1; then
        echo "✓ Test agent is ready"
        exit 0
      fi
      ;;
    mysql)
      if mysqladmin ping -h"$HOST" --silent 2>/dev/null; then
        echo "✓ MySQL is ready"
        exit 0
      fi
      ;;
    elasticsearch)
      if curl -sf "http://$HOST:$PORT/_cluster/health?wait_for_status=yellow&timeout=1s" > /dev/null 2>&1; then
        echo "✓ Elasticsearch is ready"
        exit 0
      fi
      ;;
    kafka)
      # Kafka readiness via nc check + settle time
      if timeout 5 nc -z "$HOST" "$PORT" 2>/dev/null; then
        sleep 2  # Additional settle time for Kafka
        echo "✓ Kafka is ready"
        exit 0
      fi
      ;;
    redis)
      if redis-cli -h "$HOST" ping 2>/dev/null | grep -q PONG; then
        echo "✓ Redis is ready"
        exit 0
      fi
      ;;
    httpbin)
      # httpbin-specific check
      if curl -sf "http://$HOST:$PORT/status/200" > /dev/null 2>&1; then
        echo "✓ httpbin is ready"
        exit 0
      fi
      ;;
    generic|*)
      # For generic services, just verify port + HTTP 200/health endpoint
      if curl -sf "http://$HOST:$PORT/" > /dev/null 2>&1 || curl -sf "http://$HOST:$PORT/health" > /dev/null 2>&1; then
        echo "✓ $HOST:$PORT is ready"
        exit 0
      fi
      # If HTTP fails, at least verify the port is still open
      if nc -z "$HOST" "$PORT" 2>/dev/null; then
        echo "✓ $HOST:$PORT port is open (non-HTTP service)"
        exit 0
      fi
      ;;
  esac

  echo "Attempt $i/$MAX_ATTEMPTS: Service not ready yet, retrying in ${RETRY_DELAY}s..."
  sleep $RETRY_DELAY
done

echo "ERROR: Service $HOST:$PORT ($SERVICE_TYPE) failed to become ready after $MAX_ATTEMPTS attempts"
exit 1
