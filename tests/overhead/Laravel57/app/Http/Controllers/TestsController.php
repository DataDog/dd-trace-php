<?php

namespace App\Http\Controllers;

use App\DummyPipe;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Controller as BaseController;


class TestsController extends BaseController
{
    public function pipelineCalledOnce()
    {
        $pipeline = new Pipeline();
        $result = $pipeline
            ->send(1)
            ->through(new DummyPipe())
            ->via('someHandler')
            ->then(function () {
                return 'done';
            });
        return $result;
    }

    public function pipelineCalledTwice()
    {
        $pipeline = new Pipeline();
        $result1 = $pipeline
            ->send(1)
            ->through(new DummyPipe())
            ->via('someHandler')
            ->then(function () {
                return 'done1';
            });
        $result2 = $pipeline
            ->send(2)
            ->through(new DummyPipe())
            ->via('someHandler')
            ->then(function () {
                return 'done2';
            });
        return $result1  . '/' .  $result2;
    }
}
