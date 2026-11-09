<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Patient extends Model
{
    use HasFactory;

    protected $fillable = [
        'full_name',
        'phone',
        'email',
        'medical_history',
        'prescription_details',
        'frame_chosen',
        'frame_product_id',
        'frame_price',
        'frame_quantity',
        'lens_chosen',
        'lens_price',
        'lens_quantity',
        'others_chosen',
        'notes',
        'power_right_sphere',
        'power_right_cylinder',
        'power_right_addition',
        'power_left_sphere',
        'power_left_cylinder',
        'power_left_addition',
        'power_notes',
        'legacy_customer_id',
    ];

    protected $keyType = 'string';

    public $incrementing = false;

    protected static function booted(): void
    {
        static::creating(function (Patient $patient) {
            if (!$patient->getKey()) {
                $patient->id = (string) Str::uuid();
            }
        });
    }

    public function legacyCustomer()
    {
        return $this->belongsTo(Customer::class, 'legacy_customer_id');
    }

    public function frameProduct()
    {
        return $this->belongsTo(Product::class, 'frame_product_id');
    }
}
