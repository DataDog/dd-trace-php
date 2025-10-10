--TEST--
Abort request as a result of rinit, with an empty template
--INI--
datadog.appsec.enabled=1
--ENV--
DD_APPSEC_HTTP_BLOCKED_TEMPLATE_HTML=tests/extension/templates/empty_response
--FILE--
<?php
use function datadog\appsec\testing\rinit;

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['block', ['status_code' => '500', 'type' => 'html']]], ['{"found":"attack"}','{"another":"attack"}']])),
], ['continuous' => true]);

rinit();

?>
--EXPECTHEADERS--
Status: 500 Internal Server Error
Content-type: text/html;charset=UTF-8
--EXPECTF--
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>You've been blocked</title><style>a,body,div,html,span{margin:0;padding:0;border:0;font-size:100%;font:inherit;vertical-align:baseline}body{background:-webkit-radial-gradient(26% 19%,circle,#fff,#f4f7f9);background:radial-gradient(circle at 26% 19%,#fff,#f4f7f9);display:-webkit-box;display:-ms-flexbox;display:flex;-webkit-box-pack:center;-ms-flex-pack:center;justify-content:center;-webkit-box-align:center;-ms-flex-align:center;align-items:center;-ms-flex-line-pack:center;align-content:center;width:100%;min-height:100vh;line-height:1;flex-direction:column}p{display:block}main{text-align:center;flex:1;display:-webkit-box;display:-ms-flexbox;display:flex;-webkit-box-pack:center;-ms-flex-pack:center;justify-content:center;-webkit-box-align:center;-ms-flex-align:center;align-items:center;-ms-flex-line-pack:center;align-content:center;flex-direction:column}p{font-size:18px;line-height:normal;color:#646464;font-family:sans-serif;font-weight:400}a{color:#4842b7}footer{width:100%;text-align:center}footer p{font-size:16px}</style></head><body><main><p>Sorry, you cannot access this page. Please contact the customer service team.</p></main><footer><p>Security provided by <a href="https://www.datadoghq.com/product/security-platform/application-security-monitoring/" target="_blank">Datadog</a></p></footer></body></html>
Warning: datadog\appsec\testing\rinit(): Datadog blocked the request and presented a static error page - block_id:  in %s on line %d
