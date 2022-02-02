<?php

function doError()
{
    tea\trigger_error('My E_NOTICE', E_NOTICE);
}

doError();
