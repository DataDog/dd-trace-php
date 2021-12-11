<?php

namespace RandomizedTests\Tooling;

require_once __DIR__ . '/Utils.php';

use RandomizedTests\Tooling\Utils;

class RequestTargetsGenerator
{
    public function generate($destination, $numberOfRequestTargets)
    {
        $availableQueries = [
            'key' => 'value',
            'key1' => 'value1',
            'key.2' => '2',
            'key_3' => 'value-3',
            'key%204' => 'value%204',
        ];
        $availableHeaders = [
            'content-type: application/json',
            'authorization: Bearer abcdef0987654321',
            'origin: http://some.url.com:9000',
            'cache-control: no-cache',
            'accept: */*',
        ];
        $requests = '';
        for ($idx = 0; $idx < $numberOfRequestTargets; $idx++) {
            $method = ['GET', 'POST'][rand(0, 1)];
            $port = [/* nginx */80, /* apache*/ 81][rand(0, 1)];
            $host = 'http://localhost';
            // Query String
            $query = '?seed=' . \rand() . '&';
            if (Utils::percentOfTimes(50)) {
                // We are adding a query string
                foreach ($availableQueries as $key => $value) {
                    if (Utils::percentOfTimes(70)) {
                        continue;
                    }
                    $query .= "$key=$value&";
                }
            }
            // Headers
            //   - distributed traing
            //   - datadog origin header
            //   - common headers (e.g. Content-Type, Origin)
            $headers = [];
            if (Utils::percentOfTimes(30)) {
                $headers[] = 'x-datadog-trace-id: ' . rand();
                $headers[] = 'x-datadog-parent-id: ' . rand();
                $headers[] = 'x-datadog-sampling-priority: ' . (Utils::percentOfTimes(70) ? '1.0' : '0.3');
            }
            if (Utils::percentOfTimes(30)) {
                $headers[] = 'x-datadog-origin: some-origin';
            }
            foreach ($availableHeaders as $header) {
                if (Utils::percentOfTimes(20)) {
                    $headers[] = $header;
                }
            }

            $requests .= sprintf(
                "%s %s:%d%s\n%s\n\n",
                $method,
                $host,
                $port,
                $query,
                implode("\n", $headers)
            );
        }

        // Removing extra new lines at the end of the file to avoid git's "new blank line at EOF" during commit.
        $requests = preg_replace('/\n+$/', "\n", $requests);

        file_put_contents($destination, $requests);
    }
}
