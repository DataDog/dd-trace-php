<?php

require __DIR__ . '/ddtrace.php';
instrumentMethod('Foo', 'generateKey');
instrumentMethod('Foo', 'fatalError');

class Foo
{
    public function generateKey($page)
    {
        usleep(5000);
        if ('speaking' === $page) {
            $this->fatalError($page);
        }
        return uniqid($page, true);
    }

    private function fatalError($page)
    {
        this_function_does_not_exist($page);
    }
}

header('Content-Type:text/plain');

$f = new Foo();
foreach (['contact', 'about', 'speaking'] as $key => $page) {
    $content = $f->generateKey($page);
    echo $key . ' => ' . $content . ' (' . strlen($content) . ' bytes)' . PHP_EOL;
}
