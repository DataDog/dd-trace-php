<?php

require __DIR__ . '/ddtrace.php';
instrumentMethod('Foo', 'generateKey');
instrumentMethod('Foo', 'dbConnect');

class Foo
{
    public function generateKey($page)
    {
        usleep(5000);
        if ('speaking' === $page) {
            $this->dbConnect($page);
        }
        return uniqid($page, true);
    }

    private function dbConnect($page)
    {
        return new PDO('mysql:dbname=testdb;host=127.0.0.1', 'user', $page);
    }
}

header('Content-Type:text/plain');

$f = new Foo();
foreach (['contact', 'about', 'speaking'] as $key => $page) {
    $content = $f->generateKey($page);
    echo $key . ' => ' . $content . ' (' . strlen($content) . ' bytes)' . PHP_EOL;
}
