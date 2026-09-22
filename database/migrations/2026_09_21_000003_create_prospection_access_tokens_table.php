<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jetons porteurs du CRM de prospection — mécanisme dédié plutôt que
 * Laravel Sanctum : Sanctum::usePersonalAccessTokenModel() est un réglage
 * GLOBAL à l'application, déjà pris par App\Models\User (jetons de
 * production dans `personal_access_tokens`, schéma public). Le réutiliser
 * pour les comptes de prospection aurait mêlé les deux registres de jetons
 * dans la même table, à rebours de l'isolation par schéma recherchée ici.
 *
 * Seul le hash du jeton est stocké (comme les codes de liaison établissement,
 * cf. `establishment_link_codes`) : un vol de base ne permet pas de rejouer
 * les jetons émis. `expires_at` est volontairement lointain (défaut 1 an,
 * voir SessionIssuer prospection) — "session longue durée", outil interne
 * utilisé plusieurs fois par jour.
 */
return new class extends Migration
{
    protected $connection = 'prospection';

    public function up(): void
    {
        Schema::create('access_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique(); // sha256 hex
            $table->string('device_label', 100)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_tokens');
    }
};
