<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use function PHPSTORM_META\map;

class Order extends Model
{
    use HasFactory;

    protected $guarded = [];
    protected $appends = ['total_item'];
    public function products()
    {
        return $this->hasMany(OrderProduct::class);
    }

    public function lenses()
    {
        return $this->hasMany(OrderLens::class);
    }

    public function otherProducts()
    {
        return $this->hasMany(OrderOtherProduct::class);
    }
    public function transactions()
    {
        return $this->hasMany(OrderTransaction::class);
    }
    public function customer(){
        return $this->belongsTo(Customer::class);
    }
    public function getTotalItemAttribute()
    {
        return $this->products()->sum('quantity') + (int) $this->lens_quantity;
    }
   
}
