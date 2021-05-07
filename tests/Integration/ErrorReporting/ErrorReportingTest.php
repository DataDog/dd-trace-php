<?php

namespace DDTrace\Tests\Integration\ErrorReporting;

use DDTrace\Tests\Common\WebFrameworkTestCase;
use DDTrace\Tests\Frameworks\Util\Request\GetSpec;

final class ErrorReportingTest extends WebFrameworkTestCase
{
    protected static function getAppIndexScript()
    {
        return __DIR__ . '/scripts/index.php';
    }

    public function testUnhandledUserErrorIndex()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('', '/unhandled-user-error-index'));
        });
        $this->assertError($traces[0][0], "Index message", [ ['index.php', '{main}'] ]);
    }

    public function testUnhandledExceptionIndex()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('', '/unhandled-exception-index'));
        });
        $this->assertError($traces[0][0], "Index message", [ ['index.php', '{main}'] ]);
    }

    public function testUnhandledUserErrorExternal()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('', '/unhandled-user-error-external'));
        });
        $this->assertError($traces[0][0], "Index message", [ ['index.php', '{main}'] ]);
    }

    public function testUnhandledExceptionExternal()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('', '/unhandled-exception-external'));
        });
        $this->assertError($traces[0][0], "Index message", [ ['index.php', '{main}'] ]);
    }

    public function testUnhandledUserErrorInFunction()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('', '/unhandled-user-error-in-function'));
        });
        $this->assertError($traces[0][0], "Index message", [ ['index.php', '{main}'] ]);
    }

    public function testUnhandledExceptionInFunction()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('', '/unhandled-exception-in-function'));
        });
        $this->assertError($traces[0][0], "Index message", [ ['index.php', '{main}'] ]);
    }

    public function testUnhandledUserErrorInNestedFunction()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('', '/unhandled-user-error-in-nested-function'));
        });
        $this->assertError($traces[0][0], "Index message", [ ['index.php', '{main}'] ]);
    }

    public function testUnhandledExceptionInNestedFunction()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('', '/unhandled-exception-in-nested-function'));
        });
        $this->assertError($traces[0][0], "Index message", [ ['index.php', '{main}'] ]);
    }

    public function testUnhandledExceptionInClass()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $response = $this->call(GetSpec::create('', '/unhandled-exception-class'));
            error_log('Response: ' . print_r($response, 1));
        });
        $this->assertError($traces[0][0], "Index message", [ ['index.php', '{main}'] ]);
    }

    public function testUnhandledExceptionRethrownInClass()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('', '/unhandled-exception-rethrown-class'));
        });
        $this->assertError($traces[0][0], "Index message", [ ['index.php', '{main}'] ]);
    }

    public function testUnhandledThrowableInClass()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('', '/unhandled-throwable-class'));
        });
        $this->assertError($traces[0][0], "Index message", [ ['index.php', '{main}'] ]);
    }

    public function testUnhandledThrowableInFunction()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('', '/handled-exception-function-header'));
        });
        $this->assertError($traces[0][0], "Index message", [ ['index.php', '{main}'] ]);
    }

    /**
     * Examples of this are:
     *   - Laravel 5.x:
     *       - add link
     *   - Lumen 5:
     *       - https://github.com/laravel/lumen-framework/blob/69e475bd492660a8e95e56c70a4073be7213b7c4/src/Concerns/RoutesRequests.php#L159-L175
     *   - Symfony 4.x/5.x (when catch === true):
     *       - https://github.com/symfony/http-kernel/blob/8597fb40b172c0c4a2759fab78ebc1a41ba52176/HttpKernel.php#L74-L91
     *       - https://github.com/symfony/http-foundation/blob/694c0f7bbe2d5f5c752c37156013a276795b1849/Response.php#L369
     *   - Slim:
     *       - https://github.com/slimphp/Slim/blob/c9bdc9e0d2f8613055632334ec6711b965d5fdf3/Slim/Middleware/ErrorMiddleware.php#L106-L110
     *       - https://github.com/slimphp/Slim/blob/c9bdc9e0d2f8613055632334ec6711b965d5fdf3/Slim/ResponseEmitter.php#L82-L90
     *   - Yii:
     *       - https://github.com/yiisoft/yii2/blob/3a58026359d9596f4ff37674d19a767dde7bc918/framework/base/Application.php#L385-L407
     */
    public function testHandledExceptionViaTryCatchInClassHeaderFunction()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('', '/handled-exception-try-catch-class-header'));
        });
        error_log('Traces: ' . print_r($traces, 1));
        $this->assertError($traces[0][0], "Index message", [ ['index.php', '{main}'] ]);
    }

    /**
     * Examples of this are:
     *   - Symfony 4.x/5.x (when catch === false):
     *       - https://github.com/symfony/http-kernel/blob/8597fb40b172c0c4a2759fab78ebc1a41ba52176/HttpKernel.php#L74-L91
     */
    public function testHandledExceptionRethrownInClassHeaderFunction()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('', '/handled-exception-rethrow-class-header'));
        });
        $this->assertError($traces[0][0], "Index message", [ ['index.php', '{main}'] ]);
    }

    /**
     * Examples of this are:
     *   - Laravel 5.x:
     *       - add link
     *   - Symfony 4.x/5.x (when catch === true):
     *       - https://github.com/symfony/http-kernel/blob/8597fb40b172c0c4a2759fab78ebc1a41ba52176/HttpKernel.php#L74-L91
     *       - https://github.com/symfony/http-foundation/blob/694c0f7bbe2d5f5c752c37156013a276795b1849/Response.php#L369
     */
    public function testHandledThrowableInClassHeaderFunction()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('', '/handled-throwable-class-header'));
        });
        $this->assertError($traces[0][0], "Index message", [ ['index.php', '{main}'] ]);
    }

    public function testHandledExceptionConvertedUserErrorInClass()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('', '/handled-exception-class-converted-user-error'));
        });
        $this->assertError($traces[0][0], "Index message", [ ['index.php', '{main}'] ]);
    }

    public function testHandledThrowableConvertedUserErrorInClass()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('', '/handled-throwable-class-converted-user-error'));
        });
        $this->assertError($traces[0][0], "Index message", [ ['index.php', '{main}'] ]);
    }

    public function testHandledExceptionConvertedUserErrorInFunction()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('', '/handled-exception-function-converted-user-error'));
        });
        $this->assertError($traces[0][0], "Index message", [ ['index.php', '{main}'] ]);
    }

    public function testHandledExceptionWhileRenderingPreviousException()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('', '/handled-exception-generated-while-rendering-exception'));
        });
        $this->assertError($traces[0][0], "Index message", [ ['index.php', '{main}'] ]);
    }

    public function testInternalExceptionThatShouldNotResultInError()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('', '/internal-exception-not-resulting-in-error'));
        });
        $this->assertSame(0, $traces[0][0]['error']);
    }

    public function testInternalUserErrorThatShouldNotResultInError()
    {
        $traces = $this->tracesFromWebRequest(function () {
            $this->call(GetSpec::create('', '/internal-user-error-not-resulting-in-error'));
        });
        $this->assertSame(0, $traces[0][0]['error']);
    }
}
