#ifndef DDTRACE_HANDLERS_KAFKA_H
#define DDTRACE_HANDLERS_KAFKA_H

#include "php.h"

/**
 * The id of the Kafka cluster `kafka` (an RdKafka\Producer, RdKafka\Consumer or
 * RdKafka\KafkaConsumer) is connected to, as librdkafka has it from the client's last metadata
 * response. NULL if no response has arrived yet, the broker sends no id, `kafka` is another object,
 * or librdkafka's functions cannot be found.
 */
zend_string *ddtrace_kafka_cluster_id(zend_object *kafka);

#endif // DDTRACE_HANDLERS_KAFKA_H
