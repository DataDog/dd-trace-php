--TEST--
request_init delivers $_POST and $_FILES data of multipart request
--POST_RAW--
Content-Type: multipart/form-data; boundary=---------------------------20896060251896012921717172737
-----------------------------20896060251896012921717172737
Content-Disposition: form-data; filename="file1.txt"; name="myfile"
Content-Type: text/plain-file

foobar
-----------------------------20896060251896012921717172737
Content-Disposition: form-data; filename="file2.txt";
Content-Type: text/plain-file

file2
-----------------------------20896060251896012921717172737
Content-Disposition: form-data; name="foo"
Content-Type: text/plain

bar
-----------------------------20896060251896012921717172737--
--FILE--
<?php
use function datadog\appsec\testing\rinit;

include __DIR__ . '/inc/mock_helper.php';

$helper = Helper::createInitedRun([['ok']]);

var_dump(rinit());

$c = $helper->get_commands();

function p($n) {
    global $c;
    echo "$n:\n";
    var_dump($c[1][1][0][$n]);
}
p('server.request.body');
p('server.request.body.filenames');
p('server.request.body.files_field_names');
p('server.request.body.raw');

?>
--EXPECT--
bool(true)
server.request.body:
array(1) {
  ["foo"]=>
  string(3) "bar"
}
server.request.body.filenames:
array(2) {
  [0]=>
  string(9) "file1.txt"
  [1]=>
  string(9) "file2.txt"
}
server.request.body.files_field_names:
array(2) {
  [0]=>
  string(6) "myfile"
  [1]=>
  string(1) "0"
}
server.request.body.raw:
string(0) ""
