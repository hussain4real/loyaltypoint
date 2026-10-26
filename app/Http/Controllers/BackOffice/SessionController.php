<?php

declare(strict_types=1);

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\StaffMembership;
use App\Models\User;
use App\Services\AuditService;
use App\Services\StaffMfaService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    public function __construct(private StaffMfaService $mfa, private AuditService $audit) {}

    public function create(): View
    {
        abort_unless(config('pull2spend.backoffice_enabled', false), 404);

        return view('backoffice.login');
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(config('pull2spend.backoffice_enabled', false), 404);
        $data = $request->validate(['email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string', 'max:1024']]);
        $rateKey = 'staff-login:'.hash('sha256', strtolower($data['email']).'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            throw ValidationException::withMessages(['email' => 'Too many attempts. Try again later.']);
        }
        RateLimiter::hit($rateKey, 300);
        $user = User::query()->where('email', $data['email'])->first();
        if ($user === null || ! Hash::check($data['password'], $user->password)
            || ! StaffMembership::query()->where('user_id', $user->id)->where('enabled', true)->exists()) {
            throw ValidationException::withMessages(['email' => 'The staff credentials are incorrect.']);
        }
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->forget(['staff_mfa_user', 'staff_mfa_at', 'staff_mfa_binding', 'staff_enrollment_user', 'staff_enrollment_binding']);
        $request->session()->put('staff_password_user', $user->id);
        $request->session()->put('staff_password_at', now()->timestamp);
        RateLimiter::clear($rateKey);
        $this->audit->record($user->id, 'staff.password_verified', 'user:'.$user->id, 'staff_sign_in');

        return redirect()->route('backoffice.mfa');
    }

    public function challenge(Request $request): View|RedirectResponse
    {
        abort_unless(config('pull2spend.backoffice_enabled', false), 404);
        if ($request->user('web') === null) {
            return redirect()->route('backoffice.login');
        }
        $staff = StaffMembership::query()->where('user_id', $request->user('web')->id)->where('enabled', true)->firstOrFail();
        try {
            $this->mfa->requirePasswordSession($request->user('web'), $request->session());
        } catch (ValidationException) {
            $request->session()->forget(['staff_password_user', 'staff_password_at']);

            return redirect()->route('backoffice.login')->with('status', 'Sign in again before verifying your authenticator.');
        }

        return view('backoffice.mfa', ['enrolled' => $staff->mfa_confirmed_at !== null, 'secret' => null]);
    }

    public function enroll(Request $request): View
    {
        abort_unless(config('pull2spend.backoffice_enabled', false), 404);
        abort_unless($request->user('web') !== null, 403);
        $data = $request->validate(['token' => ['required', 'string', 'max:128']]);
        $secret = $this->mfa->beginEnrollment($request->user('web'), $data['token'], $request->session());

        return view('backoffice.mfa', ['enrolled' => false, 'secret' => $secret]);
    }

    public function verify(Request $request): RedirectResponse
    {
        abort_unless(config('pull2spend.backoffice_enabled', false), 404);
        abort_unless($request->user('web') !== null, 403);
        $data = $request->validate(['code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/']]);
        $this->mfa->verify($request->user('web'), $data['code'], $request->session());

        return redirect()->route('backoffice.dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        if ($request->user('web') !== null) {
            $this->audit->record($request->user('web')->id, 'staff.signed_out', 'user:'.$request->user('web')->id, 'staff_sign_out');
        }
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('backoffice.login');
    }
}
