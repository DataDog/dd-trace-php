<?php

namespace App\Http\Controllers;

use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;

class ExampleController extends BaseController
{
    public function example()
    {
        // $this->pdoQueries();
        // $this->curlRequest();
        return "Hi from Laravel 5.7 app!\n";
    }

    private function pdoQueries()
    {
        $pdo = DB::connection()->getPdo();
        $stm = $pdo->query("show tables");
    }

    private function curlRequest()
    {
        $url = (\getenv('DD_HTTPBIN_HOST') ?: 'localhost' ) . ':4000/status/200';
        $ch = \curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 1);
        \curl_exec($ch);
    }
}
