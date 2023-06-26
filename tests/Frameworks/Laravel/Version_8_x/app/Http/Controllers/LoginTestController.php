<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\RedirectResponse;
use App\Providers\RouteServiceProvider;
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
            'password' => 'password',
        ];

        if (Auth::attempt($credentials)) {
            return response('Login successful', 200);
        }

        return response('Invalid credentials', 403);
    }

    public function register(Request $request): RedirectResponse
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

        return redirect(RouteServiceProvider::HOME);
    }
}
