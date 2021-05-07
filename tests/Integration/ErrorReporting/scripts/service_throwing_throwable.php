<?php

class SomeServiceForThrowables
{
    public function doThrow()
    {
        throw new ThrowableImplementation("Throwable thrown by inner service");
    }
}

class ThrowableImplementation implements Throwable
{
    public function getMessage()
    {
        return "Thrown by a throwable";
    }

    public function getCode()
    {
        return 555;
    }

    public function getFile()
    {
        return 'some/path/to/file.php';
    }

    public function getLine()
    {
        return 123;
    }

    public function getTrace()
    {
        return null;
    }

    public function getTraceAsString()
    {
        return implode(PHP_EOL, ['line 1', 'line 2']);
    }

    public function getPrevious()
    {
        return null;
    }

    public function __toString()
    {
        return "throwable as string";
    }
}
