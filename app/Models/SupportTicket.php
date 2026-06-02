<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTicket extends Model
{
    protected $fillable = ['user_id', 'subject', 'description', 'type', 'status', 'last_reply_at'];

    protected $casts = ['last_reply_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function replies()
    {
        return $this->hasMany(SupportTicketReply::class, 'ticket_id')->orderBy('created_at');
    }

    public function typeLabel(): string
    {
        return match($this->type) {
            'bug'         => 'Bug',
            'improvement' => 'Verbesserung',
            'question'    => 'Frage',
            default       => 'Sonstiges',
        };
    }

    public function statusLabel(): string
    {
        return match($this->status) {
            'open'        => 'Offen',
            'in_progress' => 'In Bearbeitung',
            'resolved'    => 'Gelöst',
            'closed'      => 'Geschlossen',
            default       => $this->status,
        };
    }
}
