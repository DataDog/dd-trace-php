--TEST--
Runtime function and class declarations hooked before declaration survive JIT with opcache.protect_memory=1
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80000) die('skip: JIT is only on PHP 8');
if (!function_exists('opcache_get_status')) die('skip: OPcache is required');
if (ini_get('opcache.jit') === false) die('skip: JIT support is required');
?>
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
DD_INSTRUMENTATION_TELEMETRY_ENABLED=0
DD_REMOTE_CONFIG_ENABLED=0
--INI--
opcache.enable=1
opcache.enable_cli=1
opcache.protect_memory=1
opcache.jit_buffer_size=64M
opcache.jit=1255
--FILE--
<?php

DDTrace\hook_function('declared_function',
    function ($args) {
        echo "declared_function hooked.\n";
    }
);

DDTrace\hook_method('DeclaredBase', 'speak',
    function ($This, $scope, $args) {
        echo "{$scope}::speak hooked in DeclaredBase.\n";
    }
);

// opcache cannot fold this at compile time, so the declarations are executed at runtime
if (getenv('NEVER_SET_XYZ') === false) {
    function declared_function($x) {
        return $x * 2;
    }

    class DeclaredBase
    {
        public static function speak()
        {
            return "spoken";
        }
    }

    class DeclaredChild extends DeclaredBase
    {
    }
}

var_dump(declared_function(21));
var_dump(DeclaredBase::speak());
var_dump(DeclaredChild::speak());

?>
--EXPECT--
declared_function hooked.
int(42)
DeclaredBase::speak hooked in DeclaredBase.
string(6) "spoken"
DeclaredChild::speak hooked in DeclaredBase.
string(6) "spoken"
