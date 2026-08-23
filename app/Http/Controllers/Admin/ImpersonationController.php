<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AppMode;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ImpersonationController extends Controller
{
    /**
     * Startet die Impersonation: meldet den Admin als Ziel-Nutzer an und merkt sich
     * die ursprüngliche Admin-ID in der Session.
     */
    public function start(Request $request, User $user): RedirectResponse
    {
        // Impersonation exists only for multi-tenant installs. The route is registered
        // unconditionally because the mode can be switched at runtime while route
        // definitions are cached at boot, so the guard has to live here.
        abort_unless(AppMode::isMultiTenant(), 403);

        $admin = $request->user();

        abort_unless($admin->isAdmin(), 403);

        if ($user->is($admin) || $user->isAdmin()) {
            abort(403, 'Dieser Nutzer kann nicht impersoniert werden.');
        }

        $request->session()->put('impersonator_id', $admin->id);
        $request->session()->forget('current_station_id');

        Auth::guard('web')->login($user);

        return redirect()->route('dashboard');
    }

    /**
     * Beendet die Impersonation und meldet den ursprünglichen Admin wieder an.
     */
    public function stop(Request $request): RedirectResponse
    {
        $impersonatorId = $request->session()->pull('impersonator_id');

        if (! $impersonatorId) {
            return redirect()->route('dashboard');
        }

        $admin = User::find($impersonatorId);

        $request->session()->forget('current_station_id');

        if ($admin) {
            Auth::guard('web')->login($admin);

            return redirect()->route('admin.users');
        }

        Auth::guard('web')->logout();

        return redirect()->route('login');
    }
}
