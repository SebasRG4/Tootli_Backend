<?php

namespace App\Services\OrderAssignment\Filters;

use Illuminate\Support\Collection;
use App\Models\Order;

class RatingFilter implements OrderAssignmentFilterInterface
{
    protected float $minRating;

    public function __construct(float $minRating = 4.0)
    {
        $this->minRating = $minRating;
    }

    public function handle(Collection $candidates, Order $order): Collection
    {
        return $candidates->filter(function ($deliveryman) {
            // Se asume que 'rating' existe
            return !isset($deliveryman->rating) || $deliveryman->rating >= $this->minRating;
        })->values();
    }
}