<?php

function doError()
{
    var_dump(tea\trigger_error('My E_NOTICE', E_NOTICE));
}

doError();

echo 'Done.' . PHP_EOL;
