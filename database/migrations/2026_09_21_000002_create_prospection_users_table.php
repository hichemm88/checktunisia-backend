<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Comptes internes du CRM de prospection (Hichem + coéquipiers), distincts
 * des `users` de production (personnel des établissements clients). Accès
 * interne uniquement : pas d'inscription publique, comptes créés par
 * ProspectionUserSeeder (les 2 comptes initiaux) ou par un admin depuis
 * l'app (§ Authentification).
 */
return new class extends Migration
{
    protected $connection = 'prospection';

    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role', 20)->default('membre'); // admin | membre

            // Préférences de notification (§ Notifications push). Colonnes
            // plutôt qu'une table séparée : une seule ligne par utilisateur,
            // pas de justification à un modèle 1-n ici.
            $table->boolean('notif_digest_enabled')->default(true);
            $table->string('notif_digest_hour', 5)->default('08:30'); // HH:MM, Africa/Tunis
            $table->boolean('notif_demo_reminder_enabled')->default(true);
            $table->boolean('notif_activity_enabled')->default(true);

            $table->boolean('active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
