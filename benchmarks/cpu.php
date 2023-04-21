i<?php

class PrimeCalculator
{
    public function isPrime($number)
    {
        if ($number <= 1) {
            return false;
        }

        for ($i = 2; $i <= sqrt($number); $i++) {
            if ($number % $i == 0) {
                return false;
            }
        }

        return true;
    }

    public function calculatePrimes($limit)
    {
        $primes = [];
        for ($i = 2; $i <= $limit; $i++) {
            if ($this->isPrime($i)) {
                $primes[] = $i;
            }
        }

        return $primes;
    }
}

class CpuIntensiveApp
{
    private $work;

    public function __construct($work)
    {
        $this->work = $work;
    }

    public function run()
    {
        $primeCalculator = new PrimeCalculator();
        $primes = $primeCalculator->calculatePrimes($this->work);

        return $primes;
    }
}

if ($argc < 2) {
    echo "Usage: php cpuIntensiveApp.php <work>\n";
    exit(1);
}

$work = intval($argv[1]);

if ($work < 1) {
    echo "Work must be a positive integer.\n";
    exit(1);
}

$app = new CpuIntensiveApp($work);
$primes = $app->run();
