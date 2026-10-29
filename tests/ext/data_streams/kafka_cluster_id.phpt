--TEST--
Data Streams Monitoring: kafka_cluster_id() only takes a php-rdkafka client
--DESCRIPTION--
The id is read from librdkafka through php-rdkafka's own objects, so anything but an
RdKafka\Producer, RdKafka\Consumer or RdKafka\KafkaConsumer is a TypeError. strict_types makes PHP 7
throw it too, as PHP 8 does in any mode. What a connected client returns is covered by
KafkaTest::testClusterId.
--FILE--
<?php

declare(strict_types=1);

foreach ([new stdClass(), new ArrayObject(), null, '5L6g3nShT-eMCtK--X86sw'] as $notAClient) {
    try {
        var_dump(\DDTrace\Internal\kafka_cluster_id($notAClient));
    } catch (\TypeError $e) {
        echo get_class($e), "\n";
    }
}

?>
--EXPECT--
TypeError
TypeError
TypeError
TypeError
