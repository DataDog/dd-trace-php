<?php

namespace App\Http\Controllers;

use App\User;
use Illuminate\Routing\Controller as BaseController;


class EloquentTestController extends BaseController
{
    public function get()
    {
        User::get();
    }

    public function insert()
    {
        $user = new User([
            'email' => 'test-user-created@email.com',
        ]);
        $user->save();
    }

    public function update()
    {
        $user = User::where('email', '=', 'test-user-updated@email.com')->firstOrFail();
        $user->name = 'updated';
        $user->save();
    }

    public function delete()
    {
        $user = User::where('email', '=', 'test-user-deleted@email.com')->firstOrFail();
        $user->delete();
    }

    public function destroy()
    {
        User::destroy(1);
    }

    public function refresh()
    {
        $user = User::find(1);
        $user->refresh();
    }
}
