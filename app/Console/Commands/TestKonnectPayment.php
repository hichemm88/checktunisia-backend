<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Payment\PaymentGatewayResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Banc d'essai du parcours de paiement, sur un compte précis.
 *
 * Vérifier une passerelle demande une facture réelle, un e-mail réel et un
 * lien de paiement réel. Les produire à la main est long et, surtout,
 * dangereux : la relance ne s'obtient normalement qu'en lançant
 * `invoices:dunning`, qui balaie TOUT le portefeuille — de vraies relances à
 * de vrais clients, et une suspension automatique passé 21 jours de retard.
 * Cette commande fait la même chose pour UNE facture et UN compte.
 *
 * Tout ce qu'elle crée porte la marque `metadata.test_payment`, et `--cleanup`
 * la reprend. Rien ne se mélange à la facturation réelle.
 *
 * Exemples :
 *   php artisan qayed:test-payment moi@exemple.tn --amount=1
 *   php artisan qayed:test-payment moi@exemple.tn --days-late=3 --remind
 *   php artisan qayed:test-payment moi@exemple.tn --pay
 *   php artisan qayed:test-payment moi@exemple.tn --cleanup
 */
class TestKonnectPayment extends Command
{
    protected $signature = 'qayed:test-payment
        {email : adresse du compte hébergeur à utiliser}
        {--amount=1 : montant TTC de la facture d\'essai, en TND}
        {--days-late=0 : antidate l\'échéance de N jours (3, 7 ou 14 pour déclencher une relance)}
        {--remind : envoie la relance « facture impayée » pour CETTE facture uniquement}
        {--pay : ouvre une session de paiement chez la passerelle et affiche le lien}
        {--cleanup : supprime les factures et paiements d\'essai de ce compte, puis s\'arrête}';

    protected $description = "Crée une facture d'essai, sa relance et un lien de paiement, sur un seul compte";

    public function handle(BillingService $billing, PaymentGatewayResolver $gateways): int
    {
        $email = (string) $this->argument('email');
        $user  = User::where('email', $email)->first();

        if (! $user) {
            $this->error("Aucun utilisateur avec l'adresse {$email}.");

            return self::FAILURE;
        }

        $org = $user->organization;

        if (! $org) {
            $this->error("Le compte {$email} n'appartient à aucune organisation : il n'a pas d'abonnement à facturer.");

            return self::FAILURE;
        }

        $subscription = $org->subscriptions()->latest()->first();

        if (! $subscription) {
            $this->error("L'organisation « {$org->name} » n'a aucun abonnement. Créez-en un depuis Admin → Hébergeurs.");

            return self::FAILURE;
        }

        if ($this->option('cleanup')) {
            return $this->cleanup($subscription->id, $org->name);
        }

        $this->line("Compte    : {$user->email}");
        $this->line("Organisme : {$org->name} (relances envoyées à {$org->contact_email})");

        // ── La facture ───────────────────────────────────────────────────────
        $daysLate = (int) $this->option('days-late');
        $amount   = round((float) $this->option('amount'), 3);
        $dueAt    = now()->subDays($daysLate);

        $invoice = Invoice::create([
            'subscription_id' => $subscription->id,
            'invoice_number'  => $billing->nextInvoiceNumber(),
            'amount'          => $amount,
            'tax_amount'      => 0,
            'total_amount'    => $amount,
            'currency'        => 'TND',
            // « overdue » d'emblée quand elle est antidatée : c'est l'état dans
            // lequel le planificateur l'aurait mise, et celui qu'attend la
            // relance.
            'status'          => $daysLate > 0 ? 'overdue' : 'sent',
            'due_at'          => $dueAt,
            'metadata'        => ['test_payment' => true],
        ]);

        $this->info("✔ Facture {$invoice->invoice_number} — {$amount} TND, échéance {$dueAt->toDateString()} ({$invoice->status}).");

        // ── La relance ───────────────────────────────────────────────────────
        if ($this->option('remind')) {
            if ($daysLate < 3) {
                $this->warn('  Relance demandée sur une facture à jour : en conditions réelles, le premier rappel part à J+3. Utilisez --days-late=3.');
            }

            $billing->remindOverdue($invoice, $daysLate);
            $this->info("✔ Relance « facture impayée » envoyée à {$org->contact_email}.");
        }

        // ── Le lien de paiement ──────────────────────────────────────────────
        if ($this->option('pay')) {
            $settings = PlatformSetting::get();
            $provider = $settings->onlineProvider();
            $gateway  = $gateways->forNewPayment();

            if (! $gateway || ! $provider) {
                $this->error('Le paiement en ligne est fermé : aucun canal praticable. Vérifiez Admin → Paiements.');

                return self::FAILURE;
            }

            $credentials = $settings->konnectCredentials();
            $this->line("Passerelle: {$provider} ({$credentials['environment']} → {$credentials['base_url']})");

            $trackingId = Str::uuid()->toString();

            try {
                $result = $gateway->createPayment((int) round($amount * 1000), $trackingId, [
                    'order_id'    => $invoice->invoice_number,
                    'description' => 'Qayed — essai '.$invoice->invoice_number,
                    'first_name'  => $user->first_name,
                    'last_name'   => $user->last_name,
                    'email'       => $user->email,
                    'phone'       => $user->phone,
                ]);
            } catch (\RuntimeException $e) {
                $this->error('La passerelle a refusé : '.$e->getMessage());
                $this->line("Vérifiez que l'environnement correspond aux identifiants : une clé de production sur l'API de simulation est rejetée, et l'inverse aussi.");

                return self::FAILURE;
            }

            Payment::create([
                'invoice_id'           => $invoice->id,
                'hotel_id'             => $invoice->hotel_id,
                'provider'             => $provider,
                'provider_payment_id'  => $result['payment_id'],
                'provider_tracking_id' => $trackingId,
                'status'               => 'pending',
                'amount'               => $amount,
                'currency'             => 'TND',
                'payment_url'          => $result['payment_url'],
                'expires_at'           => now()->addMinutes((int) config('konnect.lifespan_minutes', 15)),
            ]);

            $this->newLine();
            $this->info('✔ Session de paiement ouverte. Référence : '.$result['payment_id']);
            $this->line('  '.$result['payment_url']);
            $this->newLine();

            $this->line('  Rappel serveur : '.$this->webhookLine($gateway));
            $this->line('  Après paiement, contrôlez : facture « paid », abonnement prolongé, un seul e-mail « paiement reçu ».');
        }

        $this->newLine();
        $this->comment("Pensez à « --cleanup » après l'essai : sans quoi cette facture partira en relance réelle, puis suspendra ce compte à 21 jours de retard.");

        return self::SUCCESS;
    }

    /**
     * L'URL de rappel RÉELLEMENT transmise à Konnect, jeton masqué.
     *
     * Poser KONNECT_WEBHOOK_TOKEN ne suffit pas : c'est la base
     * (KONNECT_WEBHOOK_URL, à défaut APP_URL) qui décide où Konnect appellera.
     * Une base fausse — une adresse locale, un domaine périmé — donne un
     * webhook parfaitement silencieux, impossible à distinguer d'un webhook
     * absent sans l'avoir regardé. D'où l'affichage.
     *
     * Le jeton est tronqué : il n'a rien à faire dans un historique de
     * terminal, et voir la base suffit à trancher.
     */
    private function webhookLine(object $gateway): string
    {
        if (! method_exists($gateway, 'webhookUrl')) {
            return 'sans objet pour cette passerelle';
        }

        $url = $gateway->webhookUrl();

        if ($url === null) {
            return 'ABSENT — KONNECT_WEBHOOK_TOKEN non défini. Un client qui ne revient pas après avoir payé ne sera jamais constaté.';
        }

        return preg_replace('#/([0-9a-f]{4})[0-9a-f]+$#i', '/$1…', $url)
            .'  (vérifiez que ce domaine est bien celui de votre API, joignable depuis Internet)';
    }

    private function cleanup(string $subscriptionId, string $orgName): int
    {
        $invoices = Invoice::where('subscription_id', $subscriptionId)
            ->whereRaw("COALESCE(metadata->>'test_payment', '') = 'true'")
            ->get();

        if ($invoices->isEmpty()) {
            $this->info("Aucune facture d'essai à retirer pour « {$orgName} ».");

            return self::SUCCESS;
        }

        $payments = Payment::whereIn('invoice_id', $invoices->pluck('id'))->count();
        Payment::whereIn('invoice_id', $invoices->pluck('id'))->delete();
        $count = $invoices->count();
        Invoice::whereIn('id', $invoices->pluck('id'))->delete();

        $this->info("✔ {$count} facture(s) d'essai et {$payments} paiement(s) retirés pour « {$orgName} ».");
        $this->comment("L'abonnement n'est pas touché : si un essai l'a prolongé, corrigez la date depuis Admin → Hébergeurs.");

        return self::SUCCESS;
    }
}
