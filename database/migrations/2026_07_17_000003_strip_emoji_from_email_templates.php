<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Règle transverse Admin V2 : aucun emoji dans les communications sortantes.
 * Les templates PAR DÉFAUT sont corrigés dans le code (EmailTemplate::DEFAULTS) ;
 * cette migration nettoie les templates déjà personnalisés et stockés en base.
 */
return new class extends Migration
{
    /** Emojis + variation selector, suivis d'éventuels espaces/nbsp de collage. */
    private const EMOJI_REGEX = '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}](?:&nbsp;|\x{00A0}|\s)*/u';

    public function up(): void
    {
        foreach (DB::table('email_templates')->get() as $template) {
            $subject = preg_replace(self::EMOJI_REGEX, '', $template->subject ?? '');
            $body    = preg_replace(self::EMOJI_REGEX, '', $template->body_html ?? '');

            if ($subject !== $template->subject || $body !== $template->body_html) {
                DB::table('email_templates')->where('id', $template->id)->update([
                    'subject'    => $subject,
                    'body_html'  => $body,
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Suppression de contenu non réversible — rien à faire.
    }
};
