<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WalletTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'idempotency_key',
        'sender_user_id',
        'receiver_user_id',
        'sender_card_id',
        'receiver_card_id',
        'amount',
        'type',
        'payment_method',
        'recipient_identifier',
        'recipient_name',
        'status',
        'remarks',
    ];

    protected $casts = [
        'amount' => 'float',
    ];

    public function senderUser()
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function receiverUser()
    {
        return $this->belongsTo(User::class, 'receiver_user_id');
    }

    public function senderCard()
    {
        return $this->belongsTo(UserWalletCard::class, 'sender_card_id');
    }

    public function receiverCard()
    {
        return $this->belongsTo(UserWalletCard::class, 'receiver_card_id');
    }
}

