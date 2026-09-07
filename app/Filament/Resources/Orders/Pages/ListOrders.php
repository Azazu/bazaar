<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use Filament\Resources\Pages\ListRecords;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    /** No "create" header action: orders only come from checkout. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
