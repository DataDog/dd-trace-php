<?php
// the only purpose this script has is to trigger as much cheap timeline samples
// as possible

for ($i = 0; $i < 100_000; $i++) {
    try {
        throw new \Exception('FooBar');
    } catch (\Exception $e) {
        // we do not care ;-P
    }
}
