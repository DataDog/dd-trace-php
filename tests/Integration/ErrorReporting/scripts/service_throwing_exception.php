<?php

class SomeServiceForExceptions
{
    public function doThrow()
    {
        throw new Exception("Exception thrown by inner service");
    }
}
