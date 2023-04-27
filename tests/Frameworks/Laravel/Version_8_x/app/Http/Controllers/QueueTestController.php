<?php

namespace App\Http\Controllers;

use App\Jobs\SendVerificationEmail;
use Illuminate\Queue\Queue;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;

class QueueTestController extends Controller
{
    public function create()
    {
        $temp = dispatch(new SendVerificationEmail())
            ->onConnection('database')
            ->onQueue('emails');

        return __METHOD__;
    }

    /**
     * @throws \Throwable
     */
    public function batch()
    {
        Bus::batch([
            new SendVerificationEmail,
            new SendVerificationEmail
        ])->onConnection(
            'database'
        )->onQueue(
            'emails'
        )->dispatch();

        return __METHOD__;
    }

    public function batchDefault()
    {
        $tmp = Bus::batch([
            new SendVerificationEmail,
            new SendVerificationEmail,
        ])->dispatch();

        return __METHOD__;
    }

    public function workOn()
    {
        Artisan::call('queue:work database --stop-when-empty --queue=emails --sleep=2');

        return __METHOD__;
    }
}
