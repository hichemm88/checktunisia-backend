<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model {
    use HasUuids;
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['hotel_id','subscription_id','invoice_number','amount','tax_amount','discount_amount','coupon_code','total_amount','currency','status','due_at','paid_at','payment_method','payment_reference','notes','metadata','created_by'];
    protected function casts(): array { return ['due_at'=>'datetime','paid_at'=>'datetime','metadata'=>'array','amount'=>'decimal:3','tax_amount'=>'decimal:3','discount_amount'=>'decimal:3','total_amount'=>'decimal:3']; }
    public function hotel(): BelongsTo { return $this->belongsTo(Hotel::class); }
    public function subscription(): BelongsTo { return $this->belongsTo(Subscription::class); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }
    public function isPaid(): bool { return $this->status === 'paid'; }

    /**
     * Garde de DERNIER RECOURS : un compte interne ne reçoit jamais de facture.
     *
     * Les services ont chacun leur garde (renouvellement, dépassement,
     * changement de plan, saisie admin). Celle-ci couvre les chemins qui
     * n'existent pas encore — un script de rattrapage, une commande écrite
     * dans six mois, un appel direct au modèle. La règle cesse d'être une
     * convention que chaque nouveau développeur doit connaître : elle devient
     * une impossibilité.
     *
     * Elle ne s'applique qu'à la CRÉATION : les factures émises du temps où le
     * compte était commercial restent lisibles, modifiables et payables. Une
     * exemption commerciale n'efface pas l'histoire.
     *
     * Les migrations écrivent en SQL brut (DB::table) et ne passent donc pas
     * par ici — c'est voulu : une reprise de données n'est pas un acte de
     * facturation.
     */
    protected static function booted(): void
    {
        static::creating(function (self $invoice) {
            if (! $invoice->subscription_id) {
                return;
            }

            $sub = Subscription::with('organization', 'hotel.organization')->find($invoice->subscription_id);

            if ($sub?->isInternal()) {
                throw new \DomainException(
                    'Compte interne : aucune facture ne peut être émise. La règle est portée par billing_mode, '
                    .'jamais par le nom du compte — repassez-le en « commercial » si une facture doit réellement partir.',
                );
            }
        });
    }
}
