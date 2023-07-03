<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Auth\RegistersUsers;
use Illuminate\Support\Facades\Validator;
use App\User;

class LoginTestController extends BaseController
{
    use RegistersUsers;

    /**
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function auth(Request $request)
    {
        $credentials = [
            'email' => $request->get('email'),
            'password' => 'password',
        ];

        if (Auth::attempt($credentials)) {
            return response('Login successful', 200);
        }

        return response('Invalid credentials', 403);
    }

    protected function validator(array $data)
    {
        return Validator::make(
            $data,
            [
                'name' => 'required',
                'email' => 'required',
                'password' => 'required',
            ],
            []
        );
    }

    protected function create(array $data)
    {
        $user = User::create([
            'name' => strip_tags($data['name']),
            'email' => strip_tags($data['email']),
            'password' => strip_tags($data['password']),
        ]);

        return $user;
    }
}
