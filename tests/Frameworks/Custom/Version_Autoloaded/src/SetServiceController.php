<?php

namespace App;

class SetServiceController
{
    public function render()
    {
        ini_set('datadog.service', 'request-svc');
        header('Content-type: text/plain; charset=utf-8');
        echo 'service set';
    }
}
