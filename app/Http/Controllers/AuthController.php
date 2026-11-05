<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Rules\HouseholdCredential;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function show() { return view('auth.login', ['members' => User::orderBy('id')->get(['id', 'name', 'color'])]); }

    public function login(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:50', 'password' => 'required|string|max:128']);
        $key = 'login:'.$request->ip().':'.strtolower($data['name']);
        if (RateLimiter::tooManyAttempts($key, 5)) throw ValidationException::withMessages(['credential' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.']);
        if (!Auth::attempt(['name' => $data['name'], 'password' => $data['password'], 'is_active' => true], $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['credential' => 'The name and password or PIN did not match.']);
        }
        RateLimiter::clear($key); $request->session()->regenerate();
        return redirect()->intended('/');
    }

    public function password() { return view('auth.password'); }

    public function updatePassword(Request $request)
    {
        $data = $request->validate(['current_password' => 'required|current_password', 'password' => ['required', 'confirmed', 'different:current_password', new HouseholdCredential]]);
        $user = $request->user();
        $user->forceFill(['password' => $data['password'], 'must_change_password' => false, 'remember_token' => null])->save();
        DB::table('sessions')->where('user_id', $user->id)->where('id', '<>', $request->session()->getId())->delete();
        $request->session()->regenerate();
        return redirect('/')->with('success', 'Your new password or PIN is saved.');
    }

    public function logout(Request $request)
    {
        Auth::logout(); $request->session()->invalidate(); $request->session()->regenerateToken();
        return redirect()->route('login');
    }
}
