<?php

/* Verify that PHP 8.4 property hook get and set frames appear with their class
 * and hook names in wall-time and sample profiles.
 */

namespace Datadog\Test {
    final class Temperature {
        public float $celsius = 0.0 {
            get {
                usleep(150_000);
                return $this->celsius;
            }
            set(float $value) {
                usleep(150_000);
                $this->celsius = $value;
            }
        }
    }
}

namespace {
    function main() {
        $temperature = new \Datadog\Test\Temperature();
        $temperature->celsius = 20.0;
        $_ = $temperature->celsius;
    }

    main();
}
