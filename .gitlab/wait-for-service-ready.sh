#!/usr/bin/env bash

set -euo pipefail

detect_service_type() {
  local host=${1}
  case ${host} in
    test-agent) echo "test-agent" ;;
    mysql-integration) echo "mysql" ;;
    elasticsearch*) echo "elasticsearch" ;;
    zookeeper*) echo "zookeeper" ;;
    kafka*) echo "kafka" ;;
    redis*) echo "redis" ;;
    httpbin*) echo "httpbin" ;;
    *) echo "generic" ;;
  esac
}

wait_for_single_service() {
  local HOST=${1}
  local PORT=${2}
  local SERVICE_TYPE=${3:-generic}
  local MAX_ATTEMPTS=${4:-30}
  local RETRY_DELAY=${5:-5}

  echo "Waiting for ${HOST}:${PORT} to be reachable..."
  if ! wait-for "${HOST}:${PORT}" --timeout=180; then
      echo "ERROR: Could not reach ${HOST}:${PORT}" >&2
      return 1
  fi

  for i in $(seq 1 ${MAX_ATTEMPTS}); do
    case ${SERVICE_TYPE} in
      test-agent)
        if curl -sf "http://${HOST}:${PORT}/info" > /dev/null 2>&1; then
          echo "Test agent is ready"
          return 0
        fi
        ;;
      mysql)
        if mysqladmin ping -h"${HOST}" --silent 2>/dev/null; then
          echo "MySQL is ready"
          return 0
        fi
        ;;
      elasticsearch)
        if curl -sf "http://${HOST}:${PORT}/_cluster/health?wait_for_status=yellow&timeout=1s" > /dev/null 2>&1; then
          echo "Elasticsearch is ready"
          return 0
        fi
        ;;
      kafka)
        # Kafka readiness via nc check + settle time
        if timeout 5 nc -z "${HOST}" "${PORT}" 2>/dev/null; then
          sleep 5  # Additional settle time for Kafka
          echo "Kafka is ready"
          return 0
        fi
        ;;
      redis)
        if redis-cli -h "${HOST}" ping 2>/dev/null | grep -q PONG; then
          echo "Redis is ready"
          return 0
        fi
        ;;
      httpbin)
        # httpbin-specific check
        if curl -sf "http://${HOST}:${PORT}/status/200" > /dev/null 2>&1; then
          echo "httpbin is ready"
          return 0
        fi
        ;;
      zookeeper)
        # Zookeeper readiness via "ruok" four-letter-word command
        if echo "ruok" | nc -w1 -q1 "${HOST}" "${PORT}" 2>/dev/null | grep -q "imok"; then
          echo "Zookeeper is ready"
          return 0
        fi
        ;;
      generic|*)
        # For generic services, just verify port + HTTP 200/health endpoint
        if curl -sf "http://${HOST}:${PORT}/" > /dev/null 2>&1 || curl -sf "http://${HOST}:${PORT}/health" > /dev/null 2>&1; then
          echo "${HOST}:${PORT} is ready"
          return 0
        fi
        # If HTTP fails, at least verify the port is still open
        if nc -z "${HOST}" "${PORT}" 2>/dev/null; then
          echo "${HOST}:${PORT} port is open (non-HTTP service)"
          return 0
        fi
        ;;
    esac

    echo "Attempt ${i}/${MAX_ATTEMPTS}: Service not ready yet, retrying in ${RETRY_DELAY}s..."
    sleep ${RETRY_DELAY}
  done

  echo "ERROR: Service ${HOST}:${PORT} (${SERVICE_TYPE}) failed to become ready after ${MAX_ATTEMPTS} attempts"
  return 1
}

if [ -n "${WAIT_FOR:-}" ]; then
  for service in ${WAIT_FOR}; do
    host="$(echo ${service} | cut -d: -f1)"
    port="$(echo ${service} | cut -d: -f2)"
    service_type="$(detect_service_type "${host}")"

    if ! wait_for_single_service "${host}" "${port}" "${service_type}" 30 5; then
      exit 1
    fi
  done
fi
