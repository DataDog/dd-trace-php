<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\GraphQlCache\Model\Plugin\Query;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\GraphQl\Model\Query\Resolver\Context;
use Magento\Framework\GraphQl\Query\Resolver\Value;
use Magento\GraphQlCache\Model\CacheableQueryHandler;

/**
 * Plugin to handle cache validation that can be done after each resolver
 */
class Resolver
{
    /**
     * @var CacheableQueryHandler
     */
    private $cacheableQueryHandler;

    /**
     * @param CacheableQueryHandler $cacheableQueryHandler
     */
    public function __construct(
        CacheableQueryHandler $cacheableQueryHandler
    ) {
        $this->cacheableQueryHandler = $cacheableQueryHandler;
    }

    /**
     * Set cache validity to the cacheableQuery after resolving any resolver in a query
     *
     * @param ResolverInterface $subject
     * @param mixed|Value $resolvedValue
     * @param Field $field
     * @param Context $context
     * @param ResolveInfo $info
     * @param array|null $value
     * @param array|null $args
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterResolve(
        ResolverInterface $subject,
        $resolvedValue,
        Field $field,
        $context,
        ResolveInfo $info,
        array $value = null,
        array $args = null
    ) {
        $cacheAnnotation = $field->getCache();
        if (!empty($cacheAnnotation)) {
            if (is_array($resolvedValue)) {
                $this->cacheableQueryHandler->handleCacheFromResolverResponse(
                    $resolvedValue,
                    $cacheAnnotation
                );
            } elseif ($resolvedValue instanceof \Magento\Framework\GraphQl\Query\Resolver\Value) {
                $resolvedValue->then(
                    function () use ($resolvedValue, $field, $cacheAnnotation) {
                        if (is_array($resolvedValue->promise->result)) {
                            $this->cacheableQueryHandler->handleCacheFromResolverResponse(
                                $resolvedValue->promise->result,
                                $cacheAnnotation
                            );
                        } else {
                            // case if string or integer we pass in a single array element
                            $this->cacheableQueryHandler->handleCacheFromResolverResponse(
                                $resolvedValue->promise->result === null ?
                                    [] : [$field->getName() => $resolvedValue->promise->result],
                                $cacheAnnotation
                            );
                        }
                    }
                );
            } else {
                // case if string or integer we pass in a single array element
                $this->cacheableQueryHandler->handleCacheFromResolverResponse(
                    $resolvedValue === null ? [] : [$field->getName() => $resolvedValue],
                    $cacheAnnotation
                );
            }
        }
        return $resolvedValue;
    }
}
