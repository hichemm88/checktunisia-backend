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
