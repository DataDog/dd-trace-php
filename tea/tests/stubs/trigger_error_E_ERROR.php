<?php

function doError()
{
    var_dump(tea\trigger_error('My E_ERROR', E_ERROR));
}

doError();

echo 'Done.' . PHP_EOL;
