<?php

require __DIR__ . '/ddtrace.php';
instrumentMethod('DistributedTracingMulti', 'fetchUsersFromApi');

class DistributedTracingMulti
{
    // Tests distributed tracing with multi-exec
    public function fetchUsersFromApi($groups)
    {
        $mh = curl_multi_init();

        $handles = [];
        foreach ($groups as $group) {
            $ch = curl_init(DD_BASE_URL . '/api.php?group=' . $group);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'X-mt-rand: ' . mt_rand(),
            ]);
            $handles[$group] = $ch;
            curl_multi_add_handle($mh, $ch);
        }

        do {
            curl_multi_exec($mh, $active);
            curl_multi_select($mh);
        } while ($active > 0);

        $users = [];
        foreach ($handles as $group => $ch) {
            $content = curl_multi_getcontent($ch);
            $users[$group] = json_decode($content, true);
            echo 'Downloaded "' . $group . '" group content (' . strlen($content) . ' bytes)' . PHP_EOL;
            curl_multi_remove_handle($mh, $ch);
        }

        curl_multi_close($mh);

        return $users;
    }
}

header('Content-Type:text/plain');

$dt = new DistributedTracingMulti();
$users = $dt->fetchUsersFromApi(['green', 'red', 'blue']);
var_dump($users);
