<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\TestFramework\Test\Unit\Unit\Helper;

class ProxyTestingTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @param string $method
     * @param array $params
     * @param mixed $proxiedResult
     * @param string|null $proxiedMethod
     * @param string|null $proxiedParams
     * @param string $callProxiedMethod
     * @param array $passProxiedParams
     * @param mixed $expectedResult
     *
     * @dataProvider invokeWithExpectationsDataProvider
     */
    public function testInvokeWithExpectations(
        $method,
        $params,
        $proxiedResult,
        $proxiedMethod,
        $proxiedParams,
        $callProxiedMethod,
        $passProxiedParams,
        $expectedResult
    ) {
        // Create proxied object with $callProxiedMethod
        $proxiedObject = $this->createPartialMock('stdClass', [$callProxiedMethod]);

        // Create object, which reacts on called $method by calling $callProxiedMethod from proxied object
        $callProxy = function () use ($proxiedObject, $callProxiedMethod, $passProxiedParams) {
            return call_user_func_array([$proxiedObject, $callProxiedMethod], $passProxiedParams);
        };

        $object = $this->createPartialMock('stdClass', [$method]);
        $builder = $object->expects($this->once())->method($method);
        call_user_func_array([$builder, 'with'], $params);
        $builder->willReturnCallback($callProxy);

        // Test it
        $helper = new \Magento\Framework\TestFramework\Unit\Helper\ProxyTesting();
        $result = $helper->invokeWithExpectations(
            $object,
            $proxiedObject,
            $method,
            $params,
            $proxiedResult,
            $proxiedMethod,
            $proxiedParams
        );
        $this->assertSame($expectedResult, $result);
    }

    /**
     * @return array
     */
    public static function invokeWithExpectationsDataProvider()
    {
        return [
            'all parameters passed' => [
                'method' => 'returnAplusB',
                'params' => [3, 4],
                'proxiedResult' => 7,
                'proxiedMethod' => 'returnAplusB',
                'proxiedParams' => [3, 4],
                'callProxiedMethod' => 'returnAplusB',
                'passProxiedParams' => [3, 4],
                'expectedResult' => 7,
            ],
            'proxied method and params are to be set from proxy method and params' => [
                'method' => 'returnAplusB',
                'params' => [3, 4],
                'proxiedResult' => 7,
                'proxiedMethod' => null,
                'proxiedParams' => null,
                'callProxiedMethod' => 'returnAplusB',
                'passProxiedParams' => [3, 4],
                'expectedResult' => 7,
            ],
            'proxy and proxied method and params differ' => [
                'method' => 'returnAminusBminusC',
                'params' => [10, 1, 2],
                'proxiedResult' => 7,
                'proxiedMethod' => 'returnAminusB',
                'proxiedParams' => [10, 3],
                'callProxiedMethod' => 'returnAminusB',
                'passProxiedParams' => [10, 3],
                'expectedResult' => 7,
            ]
        ];
    }
}
