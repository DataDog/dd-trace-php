<?php

require __DIR__ . '/ddtrace.php';
instrumentMethod('MyApi', 'getUsers');

class MyApi
{
    public function getUsers($group)
    {
        $users = [];
        foreach (range(0, mt_rand(5, 10)) as $i) {
            $users[] = [
                'id' => $i,
                'name' => uniqid($group, true),
                'email' => uniqid() . '@example.com',
            ];
        }
        return $users;
    }
}

header('Content-Type:application/json');

$group = isset($_GET['group']) ? $_GET['group'] : '';
if ('red' === $group) {
    usleep(10000);
}

$api = new MyApi();
echo json_encode($api->getUsers($group));
