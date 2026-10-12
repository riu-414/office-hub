<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        // API 専用なのでログイン画面へのリダイレクトはしない（route('login') を探して 500 になるのを防ぐ）
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // ログインしていない状態で、ログイン必須の API にアクセスしたとき
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            // API 以外（/up など）は Laravel 標準の処理に任せる
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => 'ログインしてください。',
                'error_code' => 'UNAUTHENTICATED',
            ], 401);
        });

        // フォームの入力チェックに失敗したとき
        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => '入力内容に誤りがあります。',
                'error_code' => 'VALIDATION_FAILED',
                'errors' => $e->errors(),
            ], $e->status);
        });

        // 権限が無い（403）、存在しない URL（404）、GET 専用の URL に POST した（405）、ログインを試しすぎた（429）とき
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            [$code, $message] = match ($e->getStatusCode()) {
                403 => ['FORBIDDEN', 'この操作を行う権限がありません。'],
                404 => ['NOT_FOUND', '対象が見つかりません。'],
                405 => ['METHOD_NOT_ALLOWED', '許可されていない操作です。'],
                429 => ['TOO_MANY_REQUESTS', 'しばらく時間を置いてから再度お試しください。'],
                default => ['HTTP_ERROR', 'リクエストを処理できませんでした。'],
            };

            return response()->json([
                'message' => $message,
                'error_code' => $code,
            ], $e->getStatusCode(), $e->getHeaders());
        });

        // 上のどれにも当てはまらない、想定外のエラー（バグや DB 停止など）のとき
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') || config('app.debug')) {
                return null;
            }

            return response()->json([
                'message' => 'サーバーでエラーが発生しました。',
                'error_code' => 'SERVER_ERROR',
            ], 500);
        });
    })->create();
