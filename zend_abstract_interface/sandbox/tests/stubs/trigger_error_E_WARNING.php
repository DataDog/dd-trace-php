<?php

function doError()
{
    Zai\trigger_error('My E_WARNING', E_WARNING);
}

doError();
