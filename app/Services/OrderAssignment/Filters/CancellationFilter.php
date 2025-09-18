<?php

namespace App\Services\OrderAssignment\Filters;

use Illuminate\Support\Collection;
use App\Models\Order;

class CancellationFilter implements OrderAssignmentFilterInterface
{
    protected int $maxCancellations;

    public function __construct(int $maxCancellations = 3)
    {
        $this->maxCancellations = $maxCancellations;
    }

    public function handle(Collection $candidates, Order $order): Collection
    {
        return $candidates->filter(function ($deliveryman) {
            // Se asume que 'recent_cancellations' existe
            return !isset($deliveryman->recent_cancellations) || $deliveryman->recent_cancellations <= $this->maxCancellations;
        })->values();
    }
}