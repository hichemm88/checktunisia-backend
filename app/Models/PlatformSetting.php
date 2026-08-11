<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    protected $fillable = [
        'company_name',
        'company_mf',
        'company_rc',
        'company_address',
        'flouci_enabled',
        'flouci_app_token',
        'flouci_app_secret',
        'konnect_enabled',
        'konnect_environment',
        'konnect_api_key',
        'konnect_wallet_id',
        'virement_enabled',
        'virement_rib',
        'virement_iban',
        'virement_bank_name',
        'virement_beneficiary',
        'virement_details',
        'tax_rate',
        'timbre_fiscal',
    ];

    protected function casts(): array
    {
        return [
            'flouci_enabled'   => 'boolean',
            'konnect_enabled'  => 'boolean',
            // La clé Konnect ouvre les encaissements à elle seule : elle ne
            // reste pas en clair dans la base.
            'konnect_api_key'  => 'encrypted',
            'virement_enabled' => 'boolean',
            'tax_rate'         => 'decimal:2',
            'timbre_fiscal'    => 'decimal:3',
        ];
    }

    /** Always returns the single settings row (id = 1). */
    public static function get(): self
    {
        return static::firstOrCreate(['id' => 1], [
            'company_name'        => 'Kasbahost Sarl',
            'flouci_enabled'      => false,
            'virement_enabled'    => true,
            'virement_bank_name'  => 'Banque de Tunisie',
            'virement_beneficiary'=> 'Kasbahost Sarl',
        ]);
    }

    // ─── Praticabilité des canaux de paiement ────────────────────────────
    //
    // Un canal ANNONCÉ doit être un canal PRATICABLE. Le drapeau
    // `*_enabled` ne dit que l'intention de l'exploitant ; ces méthodes
    // disent si le règlement peut réellement aboutir. Toute surface qui
    // propose un paiement doit passer par ici — c'est ce qui empêche
    // d'ouvrir un canal muet.

    /**
     * Identifiants Flouci effectifs : la saisie du back-office d'abord, les
     * variables d'environnement en repli.
     *
     * L'écran Paiements expose « App Token » et « App Secret » et les
     * enregistre ici depuis toujours — mais rien ne les relisait : le service
     * n'interrogeait que l'environnement. Un exploitant qui configurait la
     * passerelle par le chemin prévu obtenait donc un canal ouvert dont
     * chaque paiement échouait.
     *
     * @return array{token: string, secret: string}
     */
    public function flouciCredentials(): array
    {
        return [
            'token'  => filled($this->flouci_app_token) ? (string) $this->flouci_app_token : (string) config('flouci.app_token'),
            'secret' => filled($this->flouci_app_secret) ? (string) $this->flouci_app_secret : (string) config('flouci.app_secret'),
        ];
    }

    /** Le paiement en ligne peut-il réellement aboutir ? */
    public function flouciReady(): bool
    {
        $credentials = $this->flouciCredentials();

        return (bool) $this->flouci_enabled
            && filled($credentials['token'])
            && filled($credentials['secret']);
    }

    /**
     * Identifiants Konnect effectifs — même règle que Flouci : la saisie du
     * back-office d'abord, l'environnement en repli, résolue À CHAQUE APPEL.
     *
     * `base_url` ne se saisit pas : elle est DÉDUITE de l'environnement. C'est
     * ce qui rend impossible le pire scénario de la bascule — encaisser en
     * simulation en croyant être en production, ou l'inverse.
     *
     * @return array{api_key: string, wallet_id: string, environment: string, base_url: string}
     */
    public function konnectCredentials(): array
    {
        $environment = filled($this->konnect_environment)
            ? (string) $this->konnect_environment
            : (string) config('konnect.environment', 'sandbox');

        $baseUrls = (array) config('konnect.base_urls', []);

        return [
            'api_key'     => filled($this->konnect_api_key) ? (string) $this->konnect_api_key : (string) config('konnect.api_key'),
            'wallet_id'   => filled($this->konnect_wallet_id) ? (string) $this->konnect_wallet_id : (string) config('konnect.wallet_id'),
            'environment' => $environment,
            'base_url'    => rtrim((string) ($baseUrls[$environment] ?? $baseUrls['sandbox'] ?? ''), '/'),
        ];
    }

    /** Le paiement Konnect peut-il réellement aboutir ? */
    public function konnectReady(): bool
    {
        $credentials = $this->konnectCredentials();

        return (bool) $this->konnect_enabled
            && filled($credentials['api_key'])
            && filled($credentials['wallet_id'])
            && filled($credentials['base_url']);
    }

    /**
     * Le canal « paiement en ligne », quel que soit le prestataire derrière.
     *
     * C'est ce que doivent interroger toutes les surfaces (API, écrans) :
     * elles n'ont pas à savoir QUI encaisse. Le jour où le prestataire change,
     * rien ne bouge au-dessus de cette ligne.
     */
    public function onlinePaymentReady(): bool
    {
        return $this->onlineProvider() !== null;
    }

    /**
     * Prestataire à utiliser pour un NOUVEAU paiement en ligne, ou null si le
     * canal est fermé. Konnect d'abord ; Flouci ne sert plus que s'il est
     * explicitement rallumé (paiements historiques mis à part, qui se
     * vérifient toujours par leur propre passerelle).
     */
    public function onlineProvider(): ?string
    {
        if ($this->konnectReady()) {
            return 'konnect';
        }

        return $this->flouciReady() ? 'flouci' : null;
    }

    /**
     * Le virement est-il exploitable par le client ? Sans bénéficiaire ni
     * compte, il reçoit un formulaire de déclaration sans savoir où envoyer
     * l'argent.
     */
    public function virementReady(): bool
    {
        return (bool) $this->virement_enabled
            && filled($this->virement_beneficiary)
            && (filled($this->virement_iban) || filled($this->virement_rib));
    }

    /** Public-safe representation (hides API credentials). */
    public function toPublicArray(): array
    {
        return [
            'company_name'         => $this->company_name,
            'company_mf'           => $this->company_mf,
            'company_rc'           => $this->company_rc,
            'company_address'      => $this->company_address,
            'flouci_enabled'       => $this->flouci_enabled,
            'konnect_enabled'      => $this->konnect_enabled,
            'konnect_environment'  => $this->konnect_environment,
            // Le seul drapeau que les écrans doivent lire : il dit si le
            // règlement en ligne peut RÉELLEMENT aboutir, sans nommer le
            // prestataire. Un bouton « Payer en ligne » ne doit jamais
            // s'afficher au-dessus d'un canal muet.
            'online_payment_enabled' => $this->onlinePaymentReady(),
            'virement_enabled'     => $this->virement_enabled,
            'virement_rib'         => $this->virement_rib,
            'virement_iban'        => $this->virement_iban,
            'virement_bank_name'   => $this->virement_bank_name,
            'virement_beneficiary' => $this->virement_beneficiary,
            'virement_details'     => $this->virement_details,
            'tax_rate'             => $this->tax_rate,
            'timbre_fiscal'        => $this->timbre_fiscal,
        ];
    }
}
