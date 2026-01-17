<?php

namespace Fomvasss\LaravelBackupUi\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class BackupUiAuth
{
    public function handle(Request $request, Closure $next)
    {
        $authCallback = config('backup-ui.auth_callback');

        if ($authCallback && is_callable($authCallback)) {
            if (!call_user_func($authCallback)) {
                abort(403, 'Unauthorized access to backup interface');
            }
        } else {
            if (!auth()->check()) {
                return redirect()->route('login');
            }

            $allowedUsers = config('backup-ui.allowed_users', []);

            if (!empty($allowedUsers) && !in_array(auth()->user()->email, $allowedUsers)) {
                abort(403, 'You are not authorized to access the backup interface');
            }
        }

        return $next($request);
    }
}
