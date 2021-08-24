<?php

class AutoloaderThatFails
{
    public function register()
    {
        spl_autoload_register(array($this, 'loadClass'));
    }

    public function loadClass($class)
    {
        trigger_error('Simulating an autoloader that generates an error if class not found: ' . $class, E_USER_ERROR);
    }
}
