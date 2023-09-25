<?php
/**
 * Invalid state exception
 *
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Exception\Test\Unit\State;

use \Magento\Framework\Exception\State\InvalidTransitionException;
use Magento\Framework\Phrase;

class InvalidTransitionExceptionTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @return void
     */
    public function testConstructor()
    {
        $instanceClass = \Magento\Framework\Exception\State\InvalidTransitionException::class;
        $message =  'message %1 %2';
        $params = [
            'parameter1',
            'parameter2',
        ];
        $cause = new \Exception();
        $stateException = new InvalidTransitionException(new Phrase($message, $params), $cause);
        $this->assertInstanceOf($instanceClass, $stateException);
    }
}
