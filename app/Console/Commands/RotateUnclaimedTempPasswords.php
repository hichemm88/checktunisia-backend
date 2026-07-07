<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Email\SystemMailer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * One-off cleanup for accounts created before the welcome-email flow
 * switched from emailing a plaintext temporary password to a set-password
 * link: any hotel_admin/receptionist who never logged in still has the
 * original temp password sitting in an old email. This invalidates that
 * password and re-sends the invite via the new link-based flow.
 *
 * Not scheduled — run manually once: php artisan users:rotate-unclaimed-passwords
 */
class RotateUnclaimedTempPasswords extends Command
{
    protected $signature   = 'users:rotate-unclaimed-passwords {--dry-run : List affected accounts without changing anything}';
    protected $description = 'Invalidate temp passwords for hotel staff accounts that never logged in, and re-send a set-password link';

    public function handle(): void
    {
        $users = User::role(['hotel_admin', 'receptionist'])
            ->whereNull('last_login_at')
            ->with('hotels')
            ->get();

        if ($users->isEmpty()) {
            $this->info('No accounts with an unclaimed temp password.');
            return;
        }

        if ($this->option('dry-run')) {
            $this->table(['Email', 'Role', 'Hotel(s)'], $users->map(fn(User $u) => [
                $u->email, $u->primary_role, $u->hotels->pluck('name')->implode(', '),
            ]));
            return;
        }

        foreach ($users as $user) {
            $user->update(['password' => Hash::make(Str::random(40))]);

            SystemMailer::send('welcome', $user->email, [
                'first_name' => $user->first_name,
                'last_name'  => $user->last_name,
                'hotel_name' => $user->hotels->pluck('name')->implode(', ') ?: '—',
                'role_label' => $user->primary_role === 'hotel_admin' ? 'Administrateur' : 'Réceptionniste',
                'cta_button' => SystemMailer::ctaButton(SystemMailer::issueSetPasswordLink($user), 'Définir mon mot de passe'),
            ]);

            $this->line("Rotated + re-invited: {$user->email}");
        }

        $this->info("Done — {$users->count()} account(s) rotated.");
    }
}
