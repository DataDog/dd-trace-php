<?php

function doError()
{
    tea\trigger_error('My E_ERROR', E_ERROR);
}

doError();
