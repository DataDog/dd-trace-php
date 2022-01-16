<?php

function doError()
{
    var_dump(tea\trigger_error('My E_WARNING', E_WARNING));
}

doError();

echo 'Done.' . PHP_EOL;
