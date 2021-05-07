<?php

namespace MyApp\MyBundle;

class SomeServiceForExceptions
{
    public function doThrow()
    {
        throw new Exception("Exception thrown by inner service");
    }
}
