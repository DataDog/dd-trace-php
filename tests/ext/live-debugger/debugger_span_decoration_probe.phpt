--TEST--
Installing a live debugger span decoration probe
--SKIPIF--
<?php include __DIR__ . '/../includes/skipif_no_dev_env.inc'; ?>
--ENV--
DD_AGENT_HOST=request-replayer
DD_TRACE_AGENT_PORT=80
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php

require __DIR__ . "/live_debugger.inc";

reset_request_replayer();

function &foo($arg) {
    $meta = &\DDTrace\active_span()->meta;
    unset($meta["runtime-id"]);
    return $meta;
}

function &root($arg) {
    $meta = &\DDTrace\root_span()->meta;
    unset($meta["runtime-id"]);
    return $arg;
}

await_probe_installation(function() {
    build_span_decoration_probe(["where" => ["methodName" => "foo"], "inBodyLocation" => "START", "decorations" => [
        ["tags" => [["name" => "bare", "value" => ["segments" => [["str" => "raw"]]]]]],
        ["tags" => [["name" => "arg", "value" => ["segments" => [["json" => ["ref" => "arg"]]]]]], "when" => ["json" => ["instanceof" => [["index" => [["ref" => "arg"], "foo"]], "object"]]]],
    ]]);
    build_span_decoration_probe(["where" => ["methodName" => "foo"], "decorations" => [
        ["tags" => [["name" => "error", "value" => ["segments" => [["json" => ["ref" => "arg"]]]]]], "when" => ["json" => ["eq" => [["index" => [["ref" => "arg"], "val"]], "test"]]]],
        ["tags" => [["name" => "valid", "value" => ["segments" => [["json" => ["filter" => [["ref" => "arg"], ["ne" => [["ref" => "@it"], 1]]]]]]]]], "when" => ["json" => ["ne" => [["getmember" => [["index" => [["ref" => "arg"], "foo"]], "var"]], 1]]]],
    ]]);

    build_span_decoration_probe(["where" => ["methodName" => "root"], "inBodyLocation" => "EXIT", "targetSpan" => "ROOT", "decorations" => [
        ["tags" => [["name" => "ret", "value" => ["segments" => [["str" => "return: "], ["json" => ["ref" => "@return"]]]]]]],
    ]]);

    \DDTrace\start_span(); // submit span data
});

\DDTrace\start_span();
var_dump(foo(["foo" => (object)["var" => 1, "val" => "test"], "val" => 123]));
\DDTrace\active_span()->meta = [];
var_dump(foo(["foo" => (object)["var" => 2]]));

root(1);
var_dump(\DDTrace\root_span()->meta);

$dlr = new DebuggerLogReplayer;
$log = $dlr->waitForDebuggerDataAndReplay();
var_dump($log["uri"]);
var_dump(json_decode($log["body"], true));

?>
--CLEAN--
<?php
require __DIR__ . "/live_debugger.inc";
reset_request_replayer();
?>
--EXPECTF--
array(4) {
  ["bare"]=>
  string(3) "raw"
  ["_dd.di.bare.probe_id"]=>
  string(1) "1"
  ["arg"]=>
  string(50) "[foo => (stdClass){val: test, var: 1}, val => 123]"
  ["_dd.di.arg.probe_id"]=>
  string(1) "1"
}
array(6) {
  ["bare"]=>
  string(3) "raw"
  ["_dd.di.bare.probe_id"]=>
  string(1) "1"
  ["arg"]=>
  string(27) "[foo => (stdClass){var: 2}]"
  ["_dd.di.arg.probe_id"]=>
  string(1) "1"
  ["valid"]=>
  string(20) "[(stdClass){var: 2}]"
  ["_dd.di.valid.probe_id"]=>
  string(1) "2"
}
array(2) {
  ["ret"]=>
  string(9) "return: 1"
  ["_dd.di.ret.probe_id"]=>
  string(1) "3"
}
string(%d) "/debugger/v1/input?ddtags=debugger_version:1.%s,env:none,version:,runtime_id:%s-%s-%s-%s-%s,host_name:%s"
array(1) {
  [0]=>
  array(5) {
    ["service"]=>
    string(34) "debugger_span_decoration_probe.php"
    ["source"]=>
    string(11) "dd_debugger"
    ["timestamp"]=>
    int(1%d)
    ["debugger"]=>
    array(1) {
      ["snapshot"]=>
      array(5) {
        ["language"]=>
        string(3) "php"
        ["id"]=>
        string(36) "%s-%s-%s-%s-%s"
        ["timestamp"]=>
        int(1%d)
        ["probe"]=>
        array(2) {
          ["id"]=>
          string(1) "2"
          ["location"]=>
          array(1) {
            ["method"]=>
            string(3) "foo"
          }
        }
        ["evaluationErrors"]=>
        array(1) {
          [0]=>
          array(2) {
            ["expr"]=>
            string(20) "arg["val"] == "test""
            ["message"]=>
            string(77) "Could not fetch index "val" on arg (evaluated to [foo => (stdClass){var: 2}])"
          }
        }
      }
    }
    ["message"]=>
    string(32) "Evaluation errors for probe id 2"
  }
}
