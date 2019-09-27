<?php

namespace {

    use DDTrace\Tests\Unit\Util\CustomException;

    function tryCatchFinallyGlobalCallback($arg1, $arg2)
    {
        return 'function ' . $arg1 . ' ' . $arg2;
    }

    function tryCatchFinallyGlobalCallbackException()
    {
        throw new CustomException('an exception');
    }
}
// phpcs:disable PSR12.Files.FileHeader.SpacingAfterBlock
namespace DDTrace\Tests\Unit\Util {

    use DDTrace\Tests\DebugTransport;
    use DDTrace\Tracer;
    use DDTrace\Util\TryCatchFinally;
    use PHPUnit\Framework\TestCase;

    class TryCatchFinallyTest extends TestCase
    {
        /** @var Tracer */
        private $tracer;

        protected function setUp()
        {
            parent::setUp();
            $this->tracer = new Tracer(new DebugTransport());
        }

        public function testExecutePublicMethodReturnsResult()
        {
            $instance = new DummyClass();
            $scope = $this->tracer->startActiveSpan('my.operation');
            $this->assertStringStartsWith(
                'result ',
                TryCatchFinally::executePublicMethod($scope, $instance, 'someMethod', ['1', '2'])
            );
        }

        public function testExecutePublicMethodArgsAreCorrectlyPassed()
        {
            $instance = new DummyClass();
            $scope = $this->tracer->startActiveSpan('my.operation');
            $this->assertStringEndsWith(
                ' 1 2',
                TryCatchFinally::executePublicMethod($scope, $instance, 'someMethod', ['1', '2'])
            );
        }

        public function testExecutePublicMethodAfterResultIsExecutedWithCorrectArgs()
        {
            $instance = new DummyClass();
            $counter = 1;
            $scope = $this->tracer->startActiveSpan('my.operation');
            TryCatchFinally::executePublicMethod(
                $scope,
                $instance,
                'someMethod',
                ['1', '2'],
                function ($result, $span, $scope) use (&$counter) {
                    $counter = $counter + 1;
                    $this->assertStringStartsWith('result ', $result);
                    $this->assertInstanceOf('DDTrace\Span', $span);
                    $this->assertInstanceOf('DDTrace\Scope', $scope);
                }
            );

            $this->assertSame(2, $counter);
        }

        public function testExecutePublicMethodScopeClosedOnSuccess()
        {
            $instance = new DummyClass();
            $scope = $this->tracer->startActiveSpan('my.operation', []);
            TryCatchFinally::executePublicMethod($scope, $instance, 'someMethod', ['1', '2']);
            $this->assertTrue($scope->getSpan()->isFinished());
        }

        public function testExecutePublicMethodScopeClosedOnException()
        {
            $instance = new DummyClass();
            $scope = $this->tracer->startActiveSpan('my.operation');
            try {
                TryCatchFinally::executePublicMethod($scope, $instance, 'throwsException');
            } catch (CustomException $e) {
            }
            $this->assertTrue($scope->getSpan()->isFinished());
        }

        /**
         * @expectedException \DDTrace\Tests\Unit\Util\CustomException
         */
        public function testExecutePublicMethodExceptionReThrown()
        {
            $instance = new DummyClass();
            $scope = $this->tracer->startActiveSpan('my.operation');
            TryCatchFinally::executePublicMethod($scope, $instance, 'throwsException');
        }

        public function testExecutePublicMethodSetSpanErrorOnException()
        {
            $instance = new DummyClass();
            $scope = $this->tracer->startActiveSpan('my.operation');
            try {
                TryCatchFinally::executePublicMethod($scope, $instance, 'throwsException');
            } catch (CustomException $e) {
            }
            $this->assertTrue($scope->getSpan()->hasError());
        }

        public function testExecuteFunctionReturnsResult()
        {
            $scope = $this->tracer->startActiveSpan('my.operation');
            $this->assertStringStartsWith(
                'function ',
                TryCatchFinally::executeFunction($scope, 'tryCatchFinallyGlobalCallback', ['1', '2'])
            );
        }

        public function testExecuteFunctionArgsAreCorrectlyPassed()
        {
            $scope = $this->tracer->startActiveSpan('my.operation');
            $this->assertStringEndsWith(
                ' 1 2',
                TryCatchFinally::executeFunction($scope, 'tryCatchFinallyGlobalCallback', ['1', '2'])
            );
        }

        public function testExecuteFunctionAfterResultIsExecutedWithCorrectArgs()
        {
            $counter = 1;
            $scope = $this->tracer->startActiveSpan('my.operation');
            TryCatchFinally::executeFunction(
                $scope,
                'tryCatchFinallyGlobalCallback',
                ['1', '2'],
                function ($result, $span, $scope) use (&$counter) {
                    $counter = $counter + 1;
                    $this->assertStringStartsWith('function ', $result);
                    $this->assertInstanceOf('DDTrace\Span', $span);
                    $this->assertInstanceOf('DDTrace\Scope', $scope);
                }
            );

            $this->assertSame(2, $counter);
        }

        public function testExecuteFunctionScopeClosedOnSuccess()
        {
            $scope = $this->tracer->startActiveSpan('my.operation');
            TryCatchFinally::executeFunction($scope, 'tryCatchFinallyGlobalCallback', ['1', '2']);
            $this->assertTrue($scope->getSpan()->isFinished());
        }

        public function testExecuteFunctionScopeClosedOnException()
        {
            $scope = $this->tracer->startActiveSpan('my.operation');
            try {
                TryCatchFinally::executeFunction($scope, 'tryCatchFinallyGlobalCallbackException');
            } catch (CustomException $e) {
            }
            $this->assertTrue($scope->getSpan()->isFinished());
        }

        /**
         * @expectedException \DDTrace\Tests\Unit\Util\CustomException
         */
        public function testExecuteFunctionExceptionReThrown()
        {
            $scope = $this->tracer->startActiveSpan('my.operation');
            TryCatchFinally::executeFunction($scope, 'tryCatchFinallyGlobalCallbackException');
        }

        public function testExecuteFunctionSetSpanErrorOnException()
        {
            $scope = $this->tracer->startActiveSpan('my.operation');
            try {
                TryCatchFinally::executeFunction($scope, 'tryCatchFinallyGlobalCallbackException');
            } catch (CustomException $e) {
            }
            $this->assertTrue($scope->getSpan()->hasError());
        }

        public function testExecuteProtectedMethod()
        {
            $instance = new DummyClass();
            $scope = $this->tracer->startActiveSpan('my.operation');
            $this->assertStringStartsWith(
                'protected result ',
                TryCatchFinally::executeAnyMethod($scope, $instance, 'protectedMethod', ['1', '2'])
            );
        }
    }

    class CustomException extends \Exception
    {
    }

    class DummyClass
    {
        public function someMethod($arg1, $arg2)
        {
            return 'result ' . $arg1 . ' ' . $arg2;
        }

        public function throwsException()
        {
            throw new CustomException('an exception');
        }

        protected function protectedMethod($arg1, $arg2)
        {
            return 'protected result ' . $arg1 . ' ' . $arg2;
        }

        public function __call($name, $args)
        {
            return 'catch all';
        }
    }
}
