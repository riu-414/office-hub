<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;


class AuthController extends Controller
{
    public function login(LoginRequest $request)
    {
        $credentials = $request->validated();
        $credentials['email'] = strtolower($credentials['email']);
        $credentials['is_active'] = true;

        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['メールアドレスまたはパスワードが正しくありません。'],
            ]);
        }

        // ログイン成功後にセッションIDを作り直す
        $request->session()->regenerate();

        return new UserResource(
            $request->user()->load('department', 'systemRoles.system')
        );
    }

    public function logout(Request $request)
    {
        // 3行セットです。セッションを破棄し、CSRFトークンも作り直す
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request)
    {
        return new UserResource(
            // $request->user() でログイン中のユーザーが取れる
            // load() は前回説明した Eager Loading で、N+1 を避けるため
            $request->user()->load('department', 'systemRoles.system')
        );
    }

}
