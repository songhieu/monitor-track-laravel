<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

/**
 * Harness wiring (replaces the skeleton's AppServiceProvider).
 */
class AppServiceProvider extends ServiceProvider
{
    public function register()
    {
        // A token-style guard like Passport's: the user comes from the request
        // (X-User-Id), loaded with a query, and `auth:harness` makes it the
        // request's default guard.
        config(['auth.guards.harness' => ['driver' => 'harness', 'provider' => 'users']]);

        // The app logs through the SDK channel too, as dodgeprint's stack
        // would: an exception must still be sent once.
        config(['logging.channels.stack.channels' => ['single', 'monitor-track']]);

        // Registered before the SDK's listeners (which subscribe in boot): what
        // is still buffered when an Octane worker stops gracefully.
        $this->app['events']->listen('Laravel\Octane\Events\WorkerStopping', function () {
            $client = app(\MonitorTrack\Client::class);
            file_put_contents(storage_path('logs/worker-stopping.log'),
                json_encode(['pid' => getmypid(), 'pending' => $client->transport()->pending(), 'at' => microtime(true)])."\n",
                FILE_APPEND | LOCK_EX);
        });
    }

    public function boot()
    {
        Auth::viaRequest('harness', function (Request $request) {
            $id = (int) $request->header('X-User-Id');

            return $id > 0 ? User::find($id) : null;
        });
    }
}
