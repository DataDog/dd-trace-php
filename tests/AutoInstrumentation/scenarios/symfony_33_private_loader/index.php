<?php

namespace Symfony\Component\HttpKernel
{
    // This is just used as a trigger by 'bridge/functions.php'
    class Kernel {
    }
}

namespace Symfony\Component\Config\Resource
{
    /**
     * Simulating Symfony 3.3 class loader which has private loader method 'throwOnRequiredClass'.
     * Note: this has been changed in 3.4+
     */
    class ClassExistenceResource
    {
        public function register()
        {
            // This class is private
            spl_autoload_register(__CLASS__.'::throwOnRequiredClass');
        }

        private static function throwOnRequiredClass($class)
        {
            return;
        }
    }
}

namespace My\App {

    use Symfony\Component\Config\Resource\ClassExistenceResource;

    require __DIR__ . '/vendor/autoload.php';
    // Registering of a class loader with a private loader
    (new ClassExistenceResource())->register();

    // Looking for a class that does not exist, will cause the private Symfony 3.3's class loader method to be fired.
    class_exists('I\Dont\Exist');

    echo \DDTrace\Tracer::version();
}

