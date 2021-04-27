<?php

namespace App\Http\Controllers;

use App\Models\User;

class EloquentTestController extends Controller
{
    public function get()
    {
        User::get();
        return __METHOD__;
    }

    public function insert()
    {
        $user = new User([
            'email' => 'test-user-created@email.com',
        ]);
        $user->save();
        return __METHOD__;
    }

    public function update()
    {
        $user = User::where('email', '=', 'test-user-updated@email.com')->firstOrFail();
        $user->name = 'updated';
        $user->save();
        return __METHOD__;
    }

    public function delete()
    {
        $user = User::where('email', '=', 'test-user-deleted@email.com')->firstOrFail();
        $user->delete();
        return __METHOD__;
    }

    public function destroy()
    {
        User::destroy(1);
        return __METHOD__;
    }

    public function refresh()
    {
        $user = User::find(1);
        $user->refresh();
        return __METHOD__;
    }
}
