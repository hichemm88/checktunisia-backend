<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model {
    use HasUuids;
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['hotel_id','subscription_id','invoice_number','amount','tax_amount','total_amount','currency','status','due_at','paid_at','payment_method','payment_reference','notes','metadata','created_by'];
    protected function casts(): array { return ['due_at'=>'datetime','paid_at'=>'datetime','metadata'=>'array','amount'=>'decimal:3','tax_amount'=>'decimal:3','total_amount'=>'decimal:3']; }
    public function hotel(): BelongsTo { return $this->belongsTo(Hotel::class); }
    public function subscription(): BelongsTo { return $this->belongsTo(Subscription::class); }
    public function isPaid(): bool { return $this->status === 'paid'; }
}
