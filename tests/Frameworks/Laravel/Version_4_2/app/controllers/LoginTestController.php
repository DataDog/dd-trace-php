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
}
