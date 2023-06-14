<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Routing\Controller as BaseController;

class LoginTestController extends BaseController
{
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
}
