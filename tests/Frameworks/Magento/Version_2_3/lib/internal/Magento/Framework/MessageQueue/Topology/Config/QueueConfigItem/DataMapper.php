<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\MessageQueue\Topology\Config\QueueConfigItem;

use Magento\Framework\MessageQueue\Topology\Config\Data;
use Magento\Framework\Communication\ConfigInterface as CommunicationConfig;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\MessageQueue\Rpc\ResponseQueueNameBuilder;
use Magento\Framework\Phrase;

/**
 * Topology queue config data mapper.
 */
class DataMapper
{
    /**
     * Config data.
     *
     * @var array
     */
    private $mappedData;

    /**
     * @var Data
     */
    private $configData;

    /**
     * @var CommunicationConfig
     */
    private $communicationConfig;

    /**
     * @var ResponseQueueNameBuilder
     */
    private $queueNameBuilder;

    /**
     * Initialize dependencies.
     *
     * @param Data $configData
     * @param CommunicationConfig $communicationConfig
     * @param ResponseQueueNameBuilder $queueNameBuilder
     */
    public function __construct(
        Data $configData,
        CommunicationConfig $communicationConfig,
        ResponseQueueNameBuilder $queueNameBuilder
    ) {
        $this->configData = $configData;
        $this->communicationConfig = $communicationConfig;
        $this->queueNameBuilder = $queueNameBuilder;
    }

    /**
     * Get mapped config data.
     *
     * @return array
     */
    public function getMappedData()
    {
        if (null === $this->mappedData) {
            $this->mappedData = [];
            foreach ($this->configData->get() as $exchange) {
                $connection = $exchange['connection'];
                foreach ($exchange['bindings'] as $binding) {
                    if ($binding['destinationType'] === 'queue') {
                        $queueItems = $this->createQueueItems($binding['destination'], $binding['topic'], $connection);
                        $this->mappedData = array_merge($this->mappedData, $queueItems);
                    }
                }
            }
        }
        return $this->mappedData;
    }

    /**
     * Create queue config item.
     *
     * @param string $name
     * @param string $topic
     * @param string $connection
     * @return array
     */
    private function createQueueItems($name, $topic, $connection)
    {
        $output = [];
        $synchronousTopics = [];

        if (strpos($topic, '*') !== false || strpos($topic, '#') !== false) {
            $synchronousTopics = $this->matchSynchronousTopics($topic);
        } elseif ($this->isSynchronousTopic($topic)) {
            $synchronousTopics[$topic] = $topic;
        }

        foreach ($synchronousTopics as $topicName) {
            $callbackQueueName = $this->queueNameBuilder->getQueueName($topicName);
            $output[$callbackQueueName . '--' . $connection] = [
                'name' => $callbackQueueName,
                'connection' => $connection,
                'durable' => true,
                'autoDelete' => false,
                'arguments' => [],
            ];
        }

        $output[$name . '--' . $connection] = [
            'name' => $name,
            'connection' => $connection,
            'durable' => true,
            'autoDelete' => false,
            'arguments' => [],
        ];
        return $output;
    }

    /**
     * Check whether the topic is in synchronous mode
     *
     * @param string $topicName
     * @return bool
     * @throws LocalizedException
     */
    private function isSynchronousTopic($topicName)
    {
        try {
            $topic = $this->communicationConfig->getTopic($topicName);
            $isSync = (bool)$topic[CommunicationConfig::TOPIC_IS_SYNCHRONOUS];
        } catch (LocalizedException $e) {
            throw new LocalizedException(new Phrase('Error while checking if topic is synchronous'));
        }
        return $isSync;
    }

    /**
     * Generate topics list based on wildcards.
     *
     * @param string $wildcard
     * @return array
     */
    private function matchSynchronousTopics($wildcard)
    {
        $topicDefinitions = array_filter(
            $this->communicationConfig->getTopics(),
            function ($item) {
                return (bool)$item[CommunicationConfig::TOPIC_IS_SYNCHRONOUS];
            }
        );

        $topics = [];
        $pattern = $this->buildWildcardPattern($wildcard);
        foreach (array_keys($topicDefinitions) as $topicName) {
            if (preg_match($pattern, $topicName)) {
                $topics[$topicName] = $topicName;
            }
        }
        return $topics;
    }

    /**
     * Construct perl regexp pattern for matching topic names from wildcard key.
     *
     * @param string $wildcardKey
     * @return string
     */
    private function buildWildcardPattern($wildcardKey)
    {
        $pattern = '/^' . str_replace('.', '\.', $wildcardKey);
        $pattern = str_replace('#', '.+', $pattern);
        $pattern = str_replace('*', '[^\.]+', $pattern);
        $pattern .= strpos($wildcardKey, '#') === strlen($wildcardKey) ? '/' : '$/';
        return $pattern;
    }
}
