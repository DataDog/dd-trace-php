<?php

if (!class_exists('MyDatadog\\Foo\\App')) {
    require __DIR__ . '/App.php';
}

echo "<**Running App**>\n";
$app = new MyDatadog\Foo\App();
$app->run();
var_dump(MyDatadog\Foo\MY_FUNC/* SHOUTING! */());
var_dump(\MyDatadog\Foo\wait_till_runtime_to_hook_me_too());
echo "</**Running App**>\n";
