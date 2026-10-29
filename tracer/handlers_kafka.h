#ifndef DDTRACE_HANDLERS_KAFKA_H
#define DDTRACE_HANDLERS_KAFKA_H

#include "php.h"

/**
 * The id of the Kafka cluster `kafka` (an RdKafka\Producer, RdKafka\Consumer or
 * RdKafka\KafkaConsumer) is connected to, as librdkafka has it from the client's last metadata
 * response. NULL if no response has arrived yet, the broker sends no id, or the client's handle
 * cannot be read, i.e. `kafka` is another object or php-rdkafka is not version 6 or 7.
 *
 * With the client's `bootstrap_servers`, an id read from the client is shared with the sidecar's
 * other processes, and a client that has had no response yet gets the id another client with the
 * same bootstrap servers read, or, failing that, waits up to `wait_ms` milliseconds for its own.
 */
zend_string *ddtrace_kafka_cluster_id(zend_object *kafka, zend_string *bootstrap_servers, zend_long wait_ms);

#endif // DDTRACE_HANDLERS_KAFKA_H
