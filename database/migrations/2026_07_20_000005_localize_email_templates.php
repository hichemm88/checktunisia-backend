<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Localisation des emails systeme (fr/en/ar).
 *
 * - email_templates devient multilingue : une ligne par (key, locale). L'unicite
 *   passe de `key` a `(key, locale)`. Les overrides existants sont en francais.
 * - users et organizations portent une langue de communication (`locale`),
 *   defaut 'fr'. Elle determine la langue des emails envoyes au destinataire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->char('locale', 2)->default('fr')->after('key');
        });

        // L'unicite porte desormais sur le couple (key, locale).
        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropUnique('email_templates_key_unique');
            $table->unique(['key', 'locale'], 'uq_email_templates_key_locale');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->char('locale', 2)->default('fr')->after('status');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->char('locale', 2)->default('fr')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropUnique('uq_email_templates_key_locale');
            $table->unique('key', 'email_templates_key_unique');
            $table->dropColumn('locale');
        });

        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('locale'));
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn('locale'));
    }
};
