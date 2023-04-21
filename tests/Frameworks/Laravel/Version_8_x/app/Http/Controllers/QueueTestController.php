<?php

namespace App\Http\Controllers;

use App\Jobs\NewUserWelcomeMail;
use App\Jobs\SendVerificationEmail;
use App\Models\User;
use Illuminate\Queue\Queue;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;

class QueueTestController extends Controller
{
    public function create()
    {
        //file_put_contents(storage_path('logs/queue.log'), 'create' . PHP_EOL, FILE_APPEND);
        //SendVerificationEmail::dispatch();
        //Bus::dispatch(new SendVerificationEmail());

        $temp = dispatch(new SendVerificationEmail())
            //->onConnection('database')
            ->onQueue('emails');


        return __METHOD__;
    }

    public function createFailTimeout()
    {
        dispatch(new SendVerificationEmail(1));

        return __METHOD__;
    }

    public function createFailException()
    {
        dispatch(new SendVerificationEmail(42, true));

        return __METHOD__;
    }

    public function createFailExceptionWithRetry()
    {
        dispatch(new SendVerificationEmail(42, true, 3));

        return __METHOD__;
    }

    public function chain()
    {
        Bus::chain([
            new SendVerificationEmail,
            new SendVerificationEmail,
            new SendVerificationEmail,
        ])->dispatch();

        return __METHOD__;
    }

    public function chainFailure()
    {
        Bus::chain([
            new SendVerificationEmail,
            new SendVerificationEmail(42, true),
            new SendVerificationEmail, // this job will never be executed
        ])->onConnection(
            'database'
        )->onQueue(
            'emails'
        )->catch(function ($exception) {
        })->dispatch();

        return __METHOD__;
    }

    public function createOn()
    {
        SendVerificationEmail::dispatch()->onQueue('emails');

        return __METHOD__;
    }
    public function push()
    {
        $user = User::create([
            'email' => 'test-user-created@email.com'
        ]);

        dispatch(new NewUserWelcomeMail($user));
        return __METHOD__;
    }

    public function pushOn()
    {
        $user = User::create([
            'email' => 'test-user-created@email.com'
        ]);


        dispatch(new NewUserWelcomeMail($user))->onQueue('emails');
        return __METHOD__;
    }

    public function work()
    {
        Artisan::call('queue:work', ['--once', '--max-time' => 5]);
        return __METHOD__;
    }

    public function workOn()
    {
        Artisan::call('queue:work' , ['--once', '--queue' => 'emails', '--max-time' => 5]);
        return __METHOD__;
    }
}
