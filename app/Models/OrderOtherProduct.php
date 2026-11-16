<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class OrderOtherProduct extends Model
{
    protected $fillable = ['name', 'price', 'quantity', 'discount', 'sub_total', 'total'];
    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
}
