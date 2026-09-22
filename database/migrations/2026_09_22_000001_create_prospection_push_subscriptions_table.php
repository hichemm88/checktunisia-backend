<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Abonnements Web Push (§ Notifications push) d'un compte du CRM. Un
 * navigateur/appareil installé = une ligne (`endpoint` unique) : un même
 * utilisateur peut avoir plusieurs abonnements actifs (téléphone + PC).
 *
 * Ni FCM ni un SDK propriétaire : protocole Web Push standard (RFC 8030),
 * signé VAPID — voir config/webpush.php. Le navigateur choisit lui-même le
 * service de relais (endpoint), Qayed n'a de compte nulle part.
 */
return new class extends Migration
{
    protected $connection = 'prospection';

    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('endpoint');
            $table->string('endpoint_hash', 64)->unique(); // sha256(endpoint) : index court, l'URL peut dépasser toute limite raisonnable d'index btree.
            $table->text('public_key'); // clé p256dh du navigateur
            $table->text('auth_token');
            $table->string('content_encoding', 20)->default('aesgcm');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
