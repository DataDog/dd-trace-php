--TEST--
When  ddtrace module no loaded, functions do nothing
--INI--
datadog.appsec.log_file=/tmp/php_appsec_test.log
datadog.appsec.log_level=debug
--FILE--
<?php
require __DIR__ . '/inc/logging.php';

use function datadog\appsec\track_custom_event;
use function datadog\appsec\track_user_login_success_event;
use function datadog\appsec\track_user_login_failure_event;


var_dump(track_user_login_success_event("Admin",
[
    "value" => "something",
    "metadata" => "some other metadata",
    "email" => "noneofyour@business.com"
]));

var_dump(track_user_login_failure_event("Admin", false,
[
    "value" => "something",
    "metadata" => "some other metadata",
    "email" => "noneofyour@business.com"
]));

var_dump(track_custom_event("myevent",
[
    "value" => "something",
    "metadata" => "some other metadata",
    "email" => "noneofyour@business.com"
]));


match_log("/Trying to access to track_user_login_success_event function while appsec is disabled/");
match_log("/Trying to access to track_user_login_failure_event function while appsec is disabled/");
match_log("/Trying to access to track_custom_event function while appsec is disabled/");

?>
--EXPECTF--
NULL
NULL
NULL
found message in log matching /Trying to access to track_user_login_success_event function while appsec is disabled/
found message in log matching /Trying to access to track_user_login_failure_event function while appsec is disabled/
found message in log matching /Trying to access to track_custom_event function while appsec is disabled/