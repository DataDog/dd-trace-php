<?php

namespace DDTrace\Integrations\ElasticSearch\V1;

class ElasticSearchCommon
{
    /**
     * @param string $methodName
     * @param array|null $params
     * @return string
     */
    public static function buildResourceName($methodName, $params)
    {
        if (!is_array($params)) {
            return $methodName;
        }

        $resourceFragments = [$methodName];
        $relevantParamNames = ['index', 'type'];

        foreach ($relevantParamNames as $relevantParamName) {
            if (empty($params[$relevantParamName])) {
                continue;
            }
            $resourceFragments[] = $relevantParamName . ':' . $params[$relevantParamName];
        }

        return implode(' ', $resourceFragments);
    }
}
