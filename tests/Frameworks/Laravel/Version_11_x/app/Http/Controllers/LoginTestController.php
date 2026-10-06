<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class LoginTestController extends Controller
{
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

        return redirect('/simple');
    }

    public function behind_auth()
    {
        return 'page behind auth';
    }
}
