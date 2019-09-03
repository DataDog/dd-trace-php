<?php

namespace DDTrace\Util;

/**
 * Utility class to extract container info.
 */
class ContainerInfo
{
    private $cgroupProcFile;

    // Example Docker
    // 13:name=systemd:/docker/3726184226f5d3147c25fdeab5b60097e378e8a720503a5e19ecfdf29f869860
    // Example Kubernetes
    // 11:perf_event:/kubepods/something/pod3d274242-8ee0-11e9-a8a6-1e68d864ef1a/3e74d3fd9db4c9dd921ae05c2502fb984d0cde1b36e581b13f79c639da4518a1
    // Example ECS
    // 9:perf_event:/ecs/user-ecs-classic/5a0d5ceddf6c44c1928d367a815d890f/38fac3e99302b3622be089dd41e7ccf38aff368a86cc339972075136ee2710ce
    // Example Fargate
    // 11:something:/ecs/5a081c13-b8cf-4801-b427-f4601742204d/432624d2150b349fe35ba397284dea788c2bf66b885d14dfc1569b01890ca7da
    const LINE_RE = '/^(\d+):([^:]*):.*\/([0-9a-f]{64})$/';

    public function __construct($cgroupProcFile = '/proc/self/cgroup')
    {
        $this->cgroupProcFile = $cgroupProcFile;
    }

    /**
     * Extracts the container id if the application runs in a containerized environment, `null` otherwise.
     * Note that the value is not cached, so invoking this method multiple times might lead to performance
     * degradation as one IO operation and possibly a few regex match operations are required.
     *
     * @return string|null
     */
    public function getContainerId()
    {
        if (!file_exists($this->cgroupProcFile)) {
            return null;
        }

        $file = null;
        try {
            $file = fopen($this->cgroupProcFile, 'r');
            while (!feof($file)) {
                $line = fgets($file);
                $matches = array();
                preg_match(self::LINE_RE, trim($line), $matches);
                if (count($matches) > 3) {
                    return $matches[3];
                }
            }
        } catch (\Exception $e) {
        }

        if ($file) {
            fclose($file);
        }

        return null;
    }
}
