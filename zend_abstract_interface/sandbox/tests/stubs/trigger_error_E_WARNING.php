<?php

function doError()
{
    tea\trigger_error('My E_WARNING', E_WARNING);
}

doError();
