--TEST--
Push address gets blocked even when within a hook
--INI--
extension=ddtrace.so
datadog.appsec.enabled=1
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
use function datadog\appsec\push_address;

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_exec([[['block', ['status_code' => '404', 'type' => 'html']]], ['{"found":"attack"}','{"another":"attack"}']])),
]);
rinit();

class SomeIntegration {
    public function init()
    {
        DDTrace\install_hook("ltrim", self::hooked_function(), null);
    }

    private static function hooked_function()
    {
        return static function (DDTrace\HookData $hook) {
              push_address("server.request.path_params", ["some" => "params", "more" => "parameters"]);
              var_dump("This should be executed");
        };
    }
}

$integration = new SomeIntegration();
$integration->init();
echo PHP_EOL;
var_dump(ltrim("     Calling wrapped function"));
var_dump("THIS SHOULD NOT GET IN THE OUTPUT");

?>
--EXPECTHEADERS--
Status: 404 Not Found
Content-type: text/html; charset=UTF-8
--EXPECTF--
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>You've been blocked</title><style>a,body,div,html,span{margin:0;padding:0;border:0;font-size:100%;font:inherit;vertical-align:baseline}body{background:-webkit-radial-gradient(26% 19%,circle,#fff,#f4f7f9);background:radial-gradient(circle at 26% 19%,#fff,#f4f7f9);display:-webkit-box;display:-ms-flexbox;display:flex;-webkit-box-pack:center;-ms-flex-pack:center;justify-content:center;-webkit-box-align:center;-ms-flex-align:center;align-items:center;-ms-flex-line-pack:center;align-content:center;width:100%;min-height:100vh;line-height:1;flex-direction:column}p{display:block}main{text-align:center;flex:1;display:-webkit-box;display:-ms-flexbox;display:flex;-webkit-box-pack:center;-ms-flex-pack:center;justify-content:center;-webkit-box-align:center;-ms-flex-align:center;align-items:center;-ms-flex-line-pack:center;align-content:center;flex-direction:column}p{font-size:18px;line-height:normal;color:#646464;font-family:sans-serif;font-weight:400}a{color:#4842b7}footer{width:100%;text-align:center}footer p{font-size:16px}</style></head><body><main><p>Sorry, you cannot access this page. Please contact the customer service team.</p></main><footer><p>Security provided by <a href="https://www.datadoghq.com/product/security-platform/application-security-monitoring/" target="_blank">Datadog</a></p></footer></body></html>