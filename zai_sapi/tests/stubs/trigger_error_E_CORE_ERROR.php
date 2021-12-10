<?php

function doError()
{
    var_dump(Zai\trigger_error('My E_CORE_ERROR', E_CORE_ERROR));
}

doError();

echo 'Done.' . PHP_EOL;
