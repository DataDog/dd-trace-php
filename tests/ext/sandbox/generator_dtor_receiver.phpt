--TEST--
An abandoned generator keeps its receiver alive through the legacy end hook
--SKIPIF--
<?php if (PHP_VERSION_ID < 80000) die('skip: PHP 8 generator lifecycle'); ?>
--INI--
datadog.trace.generate_root_span=0
datadog.trace.auto_flush_enabled=0
--FILE--
<?php
class GeneratorReceiver {
    public $value = 'alive';
    public $throw = false;

    public function generate() {
        yield 1;
    }

    public function __destruct() {
        echo "receiver destroyed\n";
        if ($this->throw) {
            throw new RuntimeException('receiver exception');
        }
    }
}

DDTrace\trace_method(GeneratorReceiver::class, 'generate', function () {
    echo "end: $this->value\n";
});

foreach ([false, true] as $throw) {
    $receiver = new GeneratorReceiver();
    $receiver->throw = $throw;
    $receiverRef = WeakReference::create($receiver);
    $generator = $receiver->generate();
    try {
        unset($receiver, $generator);
    } catch (RuntimeException $exception) {
        echo $exception->getMessage(), "\n";
        unset($exception);
    }
    var_dump($receiverRef->get() === null);
}
echo "done\n";
?>
--EXPECT--
end: alive
receiver destroyed
bool(true)
end: alive
receiver destroyed
receiver exception
bool(true)
done
