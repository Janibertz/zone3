<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class MakeUserAdmin extends Command
{
    protected $signature   = 'admin:make {email : E-Mail-Adresse des Nutzers}';
    protected $description = 'Admin-Rechte an einen Nutzer vergeben';

    public function handle(): int
    {
        $email = $this->argument('email');
        $user  = User::where('email', $email)->first();

        if (!$user) {
            $this->error("Kein Nutzer mit der E-Mail gefunden: {$email}");
            return 1;
        }

        if ($user->is_admin) {
            $this->info("{$user->name} ist bereits Admin.");
            return 0;
        }

        $user->update(['is_admin' => true]);

        $this->info("Admin-Rechte vergeben an: {$user->name} ({$user->email})");
        return 0;
    }
}
