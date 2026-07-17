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
