<?php

require __DIR__ . '/ddtrace.php';
instrumentMethod('MyApi', 'getUsers');

class MyApi
{
    public function getUsers()
    {
        $users = [];
        $prefix = isset($_GET['group']) ? $_GET['group'] : '';
        foreach (range(0, mt_rand(5, 10)) as $i) {
            $users[] = [
                'id' => $i,
                'name' => uniqid($prefix, true),
                'email' => uniqid() . '@example.com',
            ];
        }
        return $users;
    }
}

header('Content-Type:application/json');
$api = new MyApi();
echo json_encode($api->getUsers());
