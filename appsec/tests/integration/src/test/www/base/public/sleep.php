<?php

sleep(2);

$rootSpan = \DDTrace\root_span();
$rootSpanId = $rootSpan ? $rootSpan->id : null;
var_dump($rootSpan);
