<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

class EventoPruebaBroadcast implements ShouldBroadcast
{
    use InteractsWithSockets;

    public $mensaje;

    public function __construct($mensaje)
    {
        $this->mensaje = $mensaje;
    }

    public function broadcastOn()
    {
        return ['canal-tootli'];
    }

    public function broadcastAs()
    {
        return 'evento.prueba';
    }
}