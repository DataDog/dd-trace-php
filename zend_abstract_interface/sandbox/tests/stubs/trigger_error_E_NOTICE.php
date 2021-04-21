<?php

function doError()
{
    Zai\trigger_error('My E_NOTICE', E_NOTICE);
}

doError();
