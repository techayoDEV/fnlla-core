<?php

declare(strict_types=1);

namespace App\Controllers;

use Fnlla\Php\Auth\AuthManager;
use Fnlla\Php\Auth\Authorization\AccessControl;
use Fnlla\Php\Cache\CacheStoreInterface;
use Fnlla\Php\Http\{Request, Response};

/** Local developer exercise only. Never install the demo on a public host. */
final class DemoController
{
    public function token(): Response
    {
        return Response::json(['token' => csrf_token()], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function login(Request $request, AuthManager $auth): Response
    {
        $email = $request->input('email');
        $password = $request->input('password');
        if (!is_string($email) || !is_string($password) || !$auth->attempt(['email' => $email, 'password' => $password])) {
            return Response::json(['error' => 'Unauthenticated.'], 401);
        }
        return Response::json(['authenticated' => true], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function report(AccessControl $access, CacheStoreInterface $cache): Response
    {
        // The authenticated database actor supplies the role; request input cannot grant it.
        if (!$access->allows('demo.report')) { return Response::json(['error' => 'Forbidden.'], 403); }
        $key = 'developer-exercise-' . (string) config('auth.providers.users.table');
        $cache->put($key, 'ready', 30);
        return Response::json(['report' => 'ready', 'cache_ready' => $cache->get($key) === 'ready']);
    }
}
