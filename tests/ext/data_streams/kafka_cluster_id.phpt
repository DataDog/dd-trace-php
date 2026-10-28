--TEST--
Data Streams Monitoring: kafka_cluster_id() is null for anything but a php-rdkafka client
--DESCRIPTION--
The id is read from librdkafka through php-rdkafka's own objects, which are only read for
RdKafka\Producer, RdKafka\Consumer and RdKafka\KafkaConsumer. What a connected client returns is
covered by KafkaTest::testClusterId.
--FILE--
<?php

var_dump(\DDTrace\Internal\kafka_cluster_id(new stdClass()));
var_dump(\DDTrace\Internal\kafka_cluster_id(new ArrayObject()));
var_dump(\DDTrace\Internal\kafka_cluster_id(null));
var_dump(\DDTrace\Internal\kafka_cluster_id('5L6g3nShT-eMCtK--X86sw'));

?>
--EXPECT--
NULL
NULL
NULL
NULL
