<?php

$thrown = null;
$result = null;
try {
    $result = dd_trace_forward_call();
} catch (\Exception $ex) {
    $thrown = $ex;
}

if ($thrown) {
    throw $thrown;
}

return $result;
