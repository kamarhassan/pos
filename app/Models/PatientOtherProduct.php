<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class PatientOtherProduct extends Model
{
    protected $fillable = ['name', 'price', 'quantity', 'discount'];
    public function patient(): BelongsTo { return $this->belongsTo(Patient::class); }
}
