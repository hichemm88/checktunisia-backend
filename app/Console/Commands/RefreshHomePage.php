<?php

namespace App\Console\Commands;

use App\Models\Page;
use Database\Seeders\HomePageSeeder;
use Illuminate\Console\Command;

/**
 * Réécrit la page d'accueil CMS avec le contenu du seeder.
 *
 * Le seeder de démarrage ne remplace la page que si personne ne l'a éditée
 * dans l'admin (comparaison d'empreinte). Cette commande est l'échappatoire
 * pour le cas contraire : elle écrase une page personnalisée, donc elle exige
 * --force (ou une confirmation interactive).
 */
class RefreshHomePage extends Command
{
    protected $signature = 'qayed:refresh-home {--force : Écrase sans confirmation, y compris une page éditée dans l\'admin}';

    protected $description = "Réapplique le contenu du seeder à la page d'accueil CMS";

    public function handle(): int
    {
        $seeder = new HomePageSeeder();
        $attributes = $seeder->homeAttributes();
        $page = Page::where('slug', 'home')->first();

        if (! $page) {
            Page::create(['slug' => 'home'] + $attributes);
            $this->info('Page home absente — créée et publiée.');

            return self::SUCCESS;
        }

        if (HomePageSeeder::fingerprint($page->content) === HomePageSeeder::fingerprint($attributes['content'])) {
            $this->info('Page home déjà identique au seeder — rien à faire.');

            return self::SUCCESS;
        }

        if (! $this->option('force')
            && ! $this->confirm('Le contenu actuel de la page home sera remplacé (les éditions faites dans l\'admin seront perdues). Continuer ?', false)) {
            $this->warn('Annulé — page inchangée.');

            return self::SUCCESS;
        }

        $page->update($attributes);
        $this->info('Page home réécrite avec le contenu du seeder.');

        return self::SUCCESS;
    }
}
