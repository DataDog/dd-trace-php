<?php

function doError()
{
    var_dump(tea\trigger_error('My INVALID_ERROR', 9999999999));
}

doError();

echo 'Done.' . PHP_EOL;
