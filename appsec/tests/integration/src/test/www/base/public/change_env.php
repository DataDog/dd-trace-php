<?php

$root_span = \DDTrace\root_span();
sleep(1);
$root_span->env = $_GET['env'];

var_dump($root_span);
