--TEST--
Max depth of arrays is 20
--INI--
datadog.appsec.enabled=1
display_errors=1
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--COOKIE--
d['1']['2']['3']['4']['5']['6']['7']['8']['9']['10']['11']['12']['13']['14']['15']['16']['17']['18']['19']['20']='aaa';
truncated['1']['2']['3']['4']['5']['6']['7']['8']['9']['10']['11']['12']['13']['14']['15']['16']['17']['18']['19']['20']['21']='aaa';
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_shutdown([[['ok', []]], [], false, [],
    [], [], ["waf.requests" => [[2.0, ""], [1.0, "a=b"]]]]))
]);

rinit();
rshutdown();

$commands = $helper->get_commands();
var_dump($commands[1][1][0]['server.request.cookies']['d']);
var_dump($commands[1][1][0]['server.request.cookies']['truncated']);
?>
--EXPECTF--
Notice: datadog\appsec\testing\rshutdown(): Would call ddtrace_metric_register_buffer with name=waf.requests type=1 ns=3 in %s on line %d

Notice: datadog\appsec\testing\rshutdown(): Would call to ddtrace_metric_add_point with name=waf.requests value=2.000000 tags=input_truncated=true in %s on line %d

Notice: datadog\appsec\testing\rshutdown(): Would call ddtrace_metric_register_buffer with name=waf.requests type=1 ns=3 in %s on line %d

Notice: datadog\appsec\testing\rshutdown(): Would call to ddtrace_metric_add_point with name=waf.requests value=1.000000 tags=a=b,input_truncated=true in %s on line %d
array(1) {
  ["'1'"]=>
  array(1) {
    ["'2'"]=>
    array(1) {
      ["'3'"]=>
      array(1) {
        ["'4'"]=>
        array(1) {
          ["'5'"]=>
          array(1) {
            ["'6'"]=>
            array(1) {
              ["'7'"]=>
              array(1) {
                ["'8'"]=>
                array(1) {
                  ["'9'"]=>
                  array(1) {
                    ["'10'"]=>
                    array(1) {
                      ["'11'"]=>
                      array(1) {
                        ["'12'"]=>
                        array(1) {
                          ["'13'"]=>
                          array(1) {
                            ["'14'"]=>
                            array(1) {
                              ["'15'"]=>
                              array(1) {
                                ["'16'"]=>
                                array(1) {
                                  ["'17'"]=>
                                  array(1) {
                                    ["'18'"]=>
                                    array(1) {
                                      ["'19'"]=>
                                      array(1) {
                                        ["'20'"]=>
                                        string(5) "'aaa'"
                                      }
                                    }
                                  }
                                }
                              }
                            }
                          }
                        }
                      }
                    }
                  }
                }
              }
            }
          }
        }
      }
    }
  }
}
array(1) {
  ["'1'"]=>
  array(1) {
    ["'2'"]=>
    array(1) {
      ["'3'"]=>
      array(1) {
        ["'4'"]=>
        array(1) {
          ["'5'"]=>
          array(1) {
            ["'6'"]=>
            array(1) {
              ["'7'"]=>
              array(1) {
                ["'8'"]=>
                array(1) {
                  ["'9'"]=>
                  array(1) {
                    ["'10'"]=>
                    array(1) {
                      ["'11'"]=>
                      array(1) {
                        ["'12'"]=>
                        array(1) {
                          ["'13'"]=>
                          array(1) {
                            ["'14'"]=>
                            array(1) {
                              ["'15'"]=>
                              array(1) {
                                ["'16'"]=>
                                array(1) {
                                  ["'17'"]=>
                                  array(1) {
                                    ["'18'"]=>
                                    array(1) {
                                      ["'19'"]=>
                                      array(1) {
                                        ["'20'"]=>
                                        NULL
                                      }
                                    }
                                  }
                                }
                              }
                            }
                          }
                        }
                      }
                    }
                  }
                }
              }
            }
          }
        }
      }
    }
  }
}