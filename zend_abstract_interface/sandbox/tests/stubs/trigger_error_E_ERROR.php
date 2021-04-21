<?php

function doError()
{
    Zai\trigger_error('My E_ERROR', E_ERROR);
}

doError();
