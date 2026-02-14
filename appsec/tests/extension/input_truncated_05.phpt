--TEST--
Test exact element limit of 2048 (including containers)
--INI--
datadog.appsec.enabled=1
display_errors=1
--ENV--
DD_TRACE_GENERATE_ROOT_SPAN=0
--FILE--
<?php
use function datadog\appsec\testing\{rinit,rshutdown};
use function datadog\appsec\push_addresses;

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([
    response_list(response_request_init([[['ok', []]]])),
    response_list(response_request_exec([[['ok', []]]])),  // Test 1
    response_list(response_request_exec([[['ok', []]]])),  // Test 2
    response_list(response_request_exec([[['ok', []]]])),  // Test 3
    response_list(response_request_shutdown([[['ok', []]]]))
]);

rinit();

// Test 1: Flat array over limit
$data_over = array_fill(0, 2047, 'y');
push_addresses(["test1" => $data_over]);

// Test 2: two arrays -- the second is clipped
$data_over = array(
    array_fill(0, 2000, 'y'),
    array_fill(0, 100, 'y'),
);
push_addresses(["test2" => $data_over]);

// Test 3: nils after limit exceeded
$data_over = array_fill(0, 2046, array(1)); // each element will count two
push_addresses(["test3" => $data_over]);

rshutdown();

$commands = $helper->get_commands();

$test1_data = $commands[2][1][0];
// outer array + container + 2046
echo "test1: number of elements inside: ", count(reset($test1_data)), "\n";

$test2_data = $commands[3][1][0];
// outer array + container + 2000 + container + 44
echo "test2: number of elements inside 1st array: ", count($test2_data['test2'][0]), "\n";
echo "test2: number of elements inside 2st array: ", count($test2_data['test2'][1]), "\n";

$test3_data = $commands[4][1][0];
$d = $test3_data['test3'];
$num_arrs = count(array_filter($d, function ($x) { return $x === array(1); }));
$num_nils = count(array_filter($d, function ($x) { return $x === null; }));
echo "test3: number of array(1): ", $num_arrs, "\n";
echo "test3: number of nulls: ", $num_nils, "\n";

?>
--EXPECTF--
test1: number of elements inside: 2046
test2: number of elements inside 1st array: 2000
test2: number of elements inside 2st array: 44
test3: number of array(1): 1023
test3: number of nulls: 1023
