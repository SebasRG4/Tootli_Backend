<?php

namespace App\Services\OrderAssignment\Filters;

use Illuminate\Support\Collection;
use App\Models\Order;

class SuccessRateFilter implements OrderAssignmentFilterInterface
{
    protected float $minSuccessRate;

    public function __construct(float $minSuccessRate = 0.85)
    {
        $this->minSuccessRate = $minSuccessRate;
    }

    public function handle(Collection $candidates, Order $order): Collection
    {
        return $candidates->filter(function ($deliveryman) {
            // Se asume que 'success_rate' existe y es float (0-1)
            return !isset($deliveryman->success_rate) || $deliveryman->success_rate >= $this->minSuccessRate;
        })->values();
    }
}