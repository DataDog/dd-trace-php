<?php

namespace DDTrace\Sampling;

use DDTrace\Configuration;
use DDTrace\Contracts\Span;

/**
 * A sampler configurable using global a global configuration parameter.
 */
class ConfigurableSampler implements Sampler
{
    const KNUT_FACTOR = 1111111111111111111;
    /**
     * @param Span $span
     * @return int
     */
    public function getPrioritySampling(Span $span)
    {
        echo "\n";
        $rate = 0.5;
//        $this->dump('KNUT   ', self::KNUT_FACTOR);
//        $this->dump('PHP MAX', decbin(PHP_INT_MAX));
//        $this->dump('span id', (float)$span->getSpanId());
//        $this->dump('PHP_INT_MAX as float', (float)PHP_INT_MAX);
//        $this->dump('Span id as float * KNUT', (float)$span->getSpanId() * self::KNUT_FACTOR);
//        $this->dump('(Span id as float * KNUT) / MAX_INT', ((float)$span->getSpanId() * self::KNUT_FACTOR) / (float)PHP_INT_MAX);
//
//        $module = fmod((float)$span->getSpanId() * self::KNUT_FACTOR, (float)PHP_INT_MAX);
//        $this->dump('Span id as float * KNUT % MAX_INT', $module);
//        $this->dump(' ^^^ as binary', decbin($module));
//
//        $this->dump('example with floats', fmod(10.0, 3.0));
//
//        $this->dump('example with floats', fmod(10.0, 3.0));
//        $this->dump('$rate * PHP_INT_MAX', $rate * PHP_INT_MAX);

//        $this->dump('a float', (float)1.1);
//        $this->dump('max float', PHP_FLOAT_MAX);
//        $this->dump('PHP_INT_MAX as float', (float)PHP_INT_MAX);
//        $this->dump('span id', (float)$span->getSpanId());
//        $this->dump('(float)$span->getSpanId() * self::KNUT_FACTOR', (float)$span->getSpanId() * self::KNUT_FACTOR);
//        $this->dump('fmod((float)PHP_INT_MAX, (float)$span->getSpanId())', fmod((float)PHP_INT_MAX, (float)$span->getSpanId()));
//        $this->dump('fmod((float)$span->getSpanId(), (float)PHP_INT_MAX)', fmod((float)$span->getSpanId(), (float)PHP_INT_MAX));
        $dividend = (float)$span->getSpanId() * self::KNUT_FACTOR;
        $divisor = (float)PHP_INT_MAX;
        $division = $dividend / $divisor;
        $this->dump('PHP_INT_MAX as float', (float)PHP_INT_MAX);
        $this->dump('$dividend', $dividend);
        $this->dump('$divisor', $divisor);
        $this->dump('$division', $division);
        echo "Is division less than MAX INT? " . ($division <= PHP_INT_MAX) . "\n";
        $this->dump('$dividend / $divisor', $dividend / $divisor);
        $this->dump('$dividend / $divisor as int', intval($division));
        $this->dump('($dividend / $divisor as int) * $divisor', intval($division) * $divisor);
        $this->dump('fmod($dividend, $divisor)', fmod($dividend, $divisor));
        $this->dump('trick', floatval($dividend - intval($dividend / $divisor) * $divisor));

//        $rate = Configuration::get()->getSamplingRate();
//        // $shouldKeep = (int) $span->getSpanId() <= $rate * PHP_INT_MAX;
//        $shouldKeep = ((((float) $span->getSpanId()) * self::KNUT_FACTOR) % PHP_INT_MAX) <= ($rate * PHP_INT_MAX);
//        return $shouldKeep ? PrioritySampling::AUTO_KEEP : PrioritySampling::AUTO_REJECT;
    }

    private function dump($what, $value)
    {
        echo $what . " of type '" . gettype($value) . "': " . $value . "\n";
    }
}
