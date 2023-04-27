<?php

namespace App\Http\Controllers;

use Illuminate\Routing\Controller as BaseController;
use App\Jobs\SendVerificationEmail;
use Illuminate\Queue\Queue;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;

class QueueTestController extends BaseController
{
    public function create()
    {
        $temp = dispatch(new SendVerificationEmail())
            ->onConnection('database')
            ->onQueue('emails');

        return __METHOD__;
    }

    public function workOn()
    {
        Artisan::call('queue:work database --stop-when-empty --queue=emails --sleep=2');

        return __METHOD__;
    }
}
