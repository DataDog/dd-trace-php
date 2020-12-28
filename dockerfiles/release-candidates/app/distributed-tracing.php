<?php

require __DIR__ . '/ddtrace.php';
instrumentMethod('DistributedTracing', 'fetchUsersFromApi');

class DistributedTracing
{
    public function fetchUsersFromApi($group)
    {
        // Tests distributed tracing
        $ch = curl_init(DD_BASE_URL . '/api.php?group=' . $group);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-mt-rand: ' . mt_rand(),
        ]);
        $content = curl_exec($ch);
        echo 'Downloaded "' . $group . '" group content (' . strlen($content) . ' bytes)' . PHP_EOL;
        return json_decode($content, true);
    }
}

header('Content-Type:text/plain');

$dt = new DistributedTracing();
foreach (['green', 'red', 'blue'] as $group) {
    $users = $dt->fetchUsersFromApi($group);
    var_dump($users);
}
