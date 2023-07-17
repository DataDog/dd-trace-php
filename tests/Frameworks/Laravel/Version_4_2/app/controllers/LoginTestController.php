<?php

use Illuminate\Routing\Controller as BaseController;

class LoginTestController extends BaseController
{
    public function auth()
    {
        $credentials = [
            'email' => Input::get('email'),
            'password' => 'password',
        ];

        if (Auth::attempt($credentials)) {
            return "success";
        }

        return "error";
    }

    public function register()
    {
        $user = new User;

        $user->email = Input::get('email');
        $user->name = Input::get('name');
        $user->password = Input::get('password');

        $user->save();

        return "registered";
    }
}
