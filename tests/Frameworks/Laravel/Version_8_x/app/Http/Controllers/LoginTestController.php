<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class LoginTestController extends Controller
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function auth(Request $request)
    {
        $credentials = [
            'email' => $request->get('email'),
            'password' => $request->query('password', 'password'),
        ];

        if (Auth::attempt($credentials)) {
            return response('Login successful', 200);
        }

        return response('Invalid credentials', 403);
    }

    public function register(Request $request): Response
    {
        $request->validate([
            'name' => ['required'],
            'email' => ['required'],
            'password' => ['required'],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        event(new Registered($user));

        Auth::login($user);

        return response('User created', 200);
    }

    public function authenticate(Request $request): Response
    {
        $credentials = [
            'email' => $request->get('email'),
            'password' => $request->query('password', 'password'),
        ];

        if (Auth::attempt($credentials)) {
            $request->session()->regenerate();
            return response('Login successful', 200);
        }

        return response('Invalid credentials', 403);
    }

    public function registerAppsec(Request $request): Response
    {
        $request->validate([
            'name' => ['required'],
            'email' => ['required'],
            'password' => ['required'],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        event(new Registered($user));

        Auth::login($user);

        return response('User created', 200);
    }

    public function behind_auth()
    {
        return "page behind auth";
    }
}
