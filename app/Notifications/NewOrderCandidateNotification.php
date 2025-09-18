<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use App\Models\Order;

class NewOrderCandidateNotification extends Notification
{
    use Queueable;

    protected $order;
    protected $incentive;

    public function __construct(Order $order, $incentive = 0)
    {
        $this->order = $order;
        $this->incentive = $incentive;
    }

    public function via($notifiable)
    {
        return ['database', 'broadcast']; // O tu canal push real
    }

    public function toArray($notifiable)
    {
        return [
            'order_id' => $this->order->id,
            'message' => "Nuevo pedido disponible. Incentivo extra: {$this->incentive}%",
        ];
    }
}