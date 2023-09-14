<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Elasticsearch7\Model\Client;

use Magento\AdvancedSearch\Model\Client\ClientInterface;
use Magento\Elasticsearch\Model\Adapter\FieldsMappingPreprocessorInterface;
use Magento\Elasticsearch7\Model\Adapter\DynamicTemplatesProvider;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\LocalizedException;

/**
 * Elasticsearch client
 */
class Elasticsearch implements ClientInterface
{
    /**
     * @var array
     */
    private $clientOptions;

    /**
     * Elasticsearch Client instances
     *
     * @var \Elasticsearch\Client[]
     */
    private $client;

    /**
     * @var bool
     */
    private $pingResult;

    /**
     * @var FieldsMappingPreprocessorInterface[]
     */
    private $fieldsMappingPreprocessors;

    /**
     * @var DynamicTemplatesProvider|null
     */
    private $dynamicTemplatesProvider;

    /**
     * Initialize Elasticsearch 7 Client
     *
     * @param array $options
     * @param \Elasticsearch\Client|null $elasticsearchClient
     * @param array $fieldsMappingPreprocessors
     * @param DynamicTemplatesProvider|null $dynamicTemplatesProvider
     * @throws LocalizedException
     */
    public function __construct(
        $options = [],
        $elasticsearchClient = null,
        $fieldsMappingPreprocessors = [],
        ?DynamicTemplatesProvider $dynamicTemplatesProvider = null
    ) {
        if (empty($options['hostname'])
            || ((!empty($options['enableAuth']) && ($options['enableAuth'] == 1))
                && (empty($options['username']) || empty($options['password'])))
        ) {
            throw new LocalizedException(
                __('The search failed because of a search engine misconfiguration.')
            );
        }
        // phpstan:ignore
        if ($elasticsearchClient instanceof \Elasticsearch\Client) {
            $this->client[getmypid()] = $elasticsearchClient;
        }
        $this->clientOptions = $options;
        $this->fieldsMappingPreprocessors = $fieldsMappingPreprocessors;
        $this->dynamicTemplatesProvider = $dynamicTemplatesProvider ?: ObjectManager::getInstance()
            ->get(DynamicTemplatesProvider::class);
    }

    /**
     * Execute suggest query for Elasticsearch 7
     *
     * @param array $query
     * @return array
     */
    public function suggest(array $query): array
    {
        return $this->getElasticsearchClient()->suggest($query);
    }

    /**
     * Get Elasticsearch 7 Client
     *
     * @return \Elasticsearch\Client
     */
    private function getElasticsearchClient(): \Elasticsearch\Client
    {
        $pid = getmypid();
        if (!isset($this->client[$pid])) {
            $config = $this->buildESConfig($this->clientOptions);
            $this->client[$pid] = \Elasticsearch\ClientBuilder::fromConfig($config, true);
        }
        return $this->client[$pid];
    }

    /**
     * Ping the Elasticsearch 7 client
     *
     * @return bool
     */
    public function ping(): bool
    {
        if ($this->pingResult === null) {
            $this->pingResult = $this->getElasticsearchClient()
                ->ping(['client' => ['timeout' => $this->clientOptions['timeout']]]);
        }

        return $this->pingResult;
    }

    /**
     * Validate connection params for Elasticsearch 7
     *
     * @return bool
     */
    public function testConnection(): bool
    {
        return $this->ping();
    }

    /**
     * Build config for Elasticsearch 7
     *
     * @param array $options
     * @return array
     */
    private function buildESConfig(array $options = []): array
    {
        $hostname = preg_replace('/http[s]?:\/\//i', '', $options['hostname']);
        // @codingStandardsIgnoreStart
        $protocol = parse_url($options['hostname'], PHP_URL_SCHEME);
        // @codingStandardsIgnoreEnd
        if (!$protocol) {
            $protocol = 'http';
        }

        $authString = '';
        if (!empty($options['enableAuth']) && (int)$options['enableAuth'] === 1) {
            $authString = "{$options['username']}:{$options['password']}@";
        }

        $portString = '';
        if (!empty($options['port'])) {
            $portString = ':' . $options['port'];
        }

        $host = $protocol . '://' . $authString . $hostname . $portString;

        $options['hosts'] = [$host];

        return $options;
    }

    /**
     * Performs bulk query over Elasticsearch 7  index
     *
     * @param array $query
     * @return void
     */
    public function bulkQuery(array $query)
    {
        $this->getElasticsearchClient()->bulk($query);
    }

    /**
     * Creates an Elasticsearch 7 index.
     *
     * @param string $index
     * @param array $settings
     * @return void
     */
    public function createIndex(string $index, array $settings)
    {
        $this->getElasticsearchClient()->indices()->create(
            [
                'index' => $index,
                'body' => $settings,
            ]
        );
    }

    /**
     * Add/update an Elasticsearch index settings.
     *
     * @param string $index
     * @param array $settings
     * @return void
     */
    public function putIndexSettings(string $index, array $settings): void
    {
        $this->getElasticsearchClient()->indices()->putSettings(
            [
                'index' => $index,
                'body' => $settings,
            ]
        );
    }

    /**
     * Delete an Elasticsearch 7 index.
     *
     * @param string $index
     * @return void
     */
    public function deleteIndex(string $index)
    {
        $this->getElasticsearchClient()->indices()->delete(['index' => $index]);
    }

    /**
     * Check if index is empty.
     *
     * @param string $index
     * @return bool
     */
    public function isEmptyIndex(string $index): bool
    {
        $stats = $this->getElasticsearchClient()->indices()->stats(['index' => $index, 'metric' => 'docs']);
        if ($stats['indices'][$index]['primaries']['docs']['count'] === 0) {
            return true;
        }

        return false;
    }

    /**
     * Updates alias.
     *
     * @param string $alias
     * @param string $newIndex
     * @param string $oldIndex
     * @return void
     */
    public function updateAlias(string $alias, string $newIndex, string $oldIndex = '')
    {
        $params = [
            'body' => [
                'actions' => [],
            ],
        ];
        if ($oldIndex) {
            $params['body']['actions'][] = ['remove' => ['alias' => $alias, 'index' => $oldIndex]];
        }
        if ($newIndex) {
            $params['body']['actions'][] = ['add' => ['alias' => $alias, 'index' => $newIndex]];
        }

        $this->getElasticsearchClient()->indices()->updateAliases($params);
    }

    /**
     * Checks whether Elasticsearch 7 index exists
     *
     * @param string $index
     * @return bool
     */
    public function indexExists(string $index): bool
    {
        return $this->getElasticsearchClient()->indices()->exists(['index' => $index]);
    }

    /**
     * Exists alias.
     *
     * @param string $alias
     * @param string $index
     * @return bool
     */
    public function existsAlias(string $alias, string $index = ''): bool
    {
        $params = ['name' => $alias];
        if ($index) {
            $params['index'] = $index;
        }

        return $this->getElasticsearchClient()->indices()->existsAlias($params);
    }

    /**
     * Get alias.
     *
     * @param string $alias
     * @return array
     */
    public function getAlias(string $alias): array
    {
        return $this->getElasticsearchClient()->indices()->getAlias(['name' => $alias]);
    }

    /**
     * Add mapping to Elasticsearch 7 index
     *
     * @param array $fields
     * @param string $index
     * @param string $entityType
     * @return void
     */
    public function addFieldsMapping(array $fields, string $index, string $entityType)
    {
        $params = [
            'index' => $index,
            'type' => $entityType,
            'include_type_name' => true,
            'body' => [
                $entityType => [
                    'properties' => [],
                    'dynamic_templates' => $this->dynamicTemplatesProvider->getTemplates(),
                ],
            ],
        ];

        foreach ($this->applyFieldsMappingPreprocessors($fields) as $field => $fieldInfo) {
            $params['body'][$entityType]['properties'][$field] = $fieldInfo;
        }

        $this->getElasticsearchClient()->indices()->putMapping($params);
    }

    /**
     * Execute search by $query
     *
     * @param array $query
     * @return array
     */
    public function query(array $query): array
    {
        return $this->getElasticsearchClient()->search($query);
    }

    /**
     * Get mapping from Elasticsearch index.
     *
     * @param array $params
     * @return array
     */
    public function getMapping(array $params): array
    {
        return $this->getElasticsearchClient()->indices()->getMapping($params);
    }

    /**
     * Delete mapping in Elasticsearch 7 index
     *
     * @param string $index
     * @param string $entityType
     * @return void
     */
    public function deleteMapping(string $index, string $entityType)
    {
        $this->getElasticsearchClient()->indices()->deleteMapping(
            [
                'index' => $index,
                'type' => $entityType,
            ]
        );
    }

    /**
     * Apply fields mapping preprocessors
     *
     * @param array $properties
     * @return array
     */
    private function applyFieldsMappingPreprocessors(array $properties): array
    {
        foreach ($this->fieldsMappingPreprocessors as $preprocessor) {
            $properties = $preprocessor->process($properties);
        }
        return $properties;
    }
}
