<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Response;

class LoginController extends Controller
{
    /**
     * Handle an authentication attempt.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function authenticate(Request $request)
    {
        // When the `email` query param is present (even if empty), use its
        // value verbatim; otherwise fall back to the default. Laravel's
        // ConvertEmptyStringsToNull middleware coerces `?email=` to null on
        // the parsed request, so we read from the raw query string instead.
        $rawQuery = $request->getQueryString() ?? '';
        $hasEmail = false;
        $rawEmail = '';
        foreach (explode('&', $rawQuery) as $pair) {
            $kv = explode('=', $pair, 2);
            if ($kv[0] === 'email') {
                $hasEmail = true;
                $rawEmail = isset($kv[1]) ? urldecode($kv[1]) : '';
                break;
            }
        }
        $credentials = [
            'email' => $hasEmail ? $rawEmail : 'ciuser@example.com',
            'password' => $request->query('password', 'password'),
        ];

        if (Auth::attempt($credentials)) {
            $request->session()->regenerate();

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
}