<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientLens extends Model
{
    protected $fillable = ['product_id', 'lens_name', 'lens_price', 'discount', 'quantity'];

    public function patient(): BelongsTo { return $this->belongsTo(Patient::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
}
