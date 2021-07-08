<?php

function throwing_an_exception($password)
{
    $ex = new Exception("Exception generated in external file");
    error_log('Raw trace: ' . var_export($ex->getTrace(), 1));
    throw $ex;
}


throwing_an_exception("secret");
