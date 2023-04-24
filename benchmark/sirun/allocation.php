<?php

class HeavyObject
{
    public $data;

    public function __construct()
    {
        $this->data = str_repeat('datadog', 512*1000);
    }
}

class AllocationHeavyApp
{
    private $work;

    public function __construct($work)
    {
        $this->work = $work;
    }

    public function run()
    {
        for ($i = 0; $i < $this->work; $i++) {
            $heavyObjects = new HeavyObject();
        }
    }
}

if ($argc < 2) {
    echo "Usage: php allocationHeavyApp.php <work>\n";
    exit(1);
}

$work = intval($argv[1]);

if ($work < 1) {
    echo "Work must be a positive integer.\n";
    exit(1);
}

$app = new AllocationHeavyApp($work);
$app->run();
