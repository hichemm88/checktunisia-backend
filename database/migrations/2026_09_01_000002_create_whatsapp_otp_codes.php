<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Codes à usage unique envoyés par WhatsApp aux agents autorité.
 *
 * ── Pourquoi une table, et pas le cache ──────────────────────────────────
 *
 * Le compteur d'essais et le verrouillage qui en découle sont des objets de
 * SÉCURITÉ : ce sont eux qui empêchent d'essayer les 10⁶ codes possibles. Les
 * loger dans le cache, c'est accepter qu'un `cache:clear` — geste banal d'un
 * déploiement — remette à zéro le verrou d'un numéro en cours d'attaque, sans
 * que rien ne le signale. La base survit à tout ce à quoi le cache ne survit
 * pas, et laisse en plus une trace consultable après incident.
 *
 * Les limiteurs de DÉBIT (3 demandes / 10 min), eux, restent dans le cache :
 * les perdre ne rouvre rien, cela redonne au plus une poignée de demandes.
 *
 * ── Ce que la table ne contient pas ──────────────────────────────────────
 *
 * Jamais le code en clair : seulement son empreinte, du même hachage que les
 * mots de passe. Quelqu'un qui lirait cette table — sauvegarde égarée, accès
 * en lecture à la base — n'y trouve rien qui permette de se connecter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_otp_codes', function (Blueprint $table) {
            $table->id();

            // Numéro normalisé : chiffres internationaux, sans « + » ni
            // suffixe — exactement la forme qu'attend la Cloud API. Le stocker
            // sous une autre forme que celle de l'envoi ferait diverger le
            // « à qui on a envoyé » du « qui a le droit d'essayer ».
            $table->string('phone', 20)->index();

            // Empreinte du code (bcrypt/argon selon la config de hachage).
            $table->string('code_hash');

            // Compte auquel le code ouvre une session. Résolu à la DEMANDE, et
            // non à la vérification : le lien numéro → agent ne doit pas
            // pouvoir changer entre l'envoi et la saisie.
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();

            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);

            // Consommé : le code a ouvert une session. Un second usage est
            // refusé — c'est ce qui rend un code lu par-dessus l'épaule
            // inutilisable après coup.
            $table->timestamp('consumed_at')->nullable();

            // Verrouillage du NUMÉRO après trop d'essais. Porté par la ligne
            // du code fautif : le verrou et sa cause restent solidaires.
            $table->timestamp('locked_until')->nullable();

            $table->timestamps();

            // Le seul index qui compte à la vérification : « le dernier code
            // vivant de ce numéro ».
            $table->index(['phone', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_otp_codes');
    }
};
