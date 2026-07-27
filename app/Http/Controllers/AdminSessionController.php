<?php

namespace App\Http\Controllers;

use App\Http\Requests\AdminLoginRequest;
use App\Services\MasterAdminSession;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminSessionController extends Controller
{
    public function store(AdminLoginRequest $request, MasterAdminSession $adminSession)
    {
        $password = $request->validated('password');

        if (! is_string($password) || ! $adminSession->attempt($password)) {
            throw ValidationException::withMessages([
                'password' => 'The admin password is invalid.',
            ]);
        }

        $request->session()->regenerate(true);
        $adminSession->authenticate($request);

        return to_route('admin.index');
    }

    public function destroy(Request $request, MasterAdminSession $adminSession)
    {
        $adminSession->forget($request);
        $request->session()->regenerate(true);

        return to_route('home');
    }
}
