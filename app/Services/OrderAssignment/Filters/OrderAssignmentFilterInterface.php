<?php

namespace App\Services\OrderAssignment\Filters;

use Illuminate\Support\Collection;
use App\Models\Order;

interface OrderAssignmentFilterInterface
{
    public function handle(Collection $candidates, Order $order): Collection;
}