<?php

namespace App\Filament\Resources\Stores\Pages;

use App\Exceptions\DeletionBlockedException;
use App\Filament\Resources\Stores\StoreResource;
use App\Models\Store;
use App\Services\Account\AccountService;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditStore extends EditRecord
{
    protected static string $resource = StoreResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Soft-delete under a row lock (AccountService): history is kept; refused while orders are open.
            DeleteAction::make()
                ->label('Archive')
                ->modalHeading('Archive store')
                ->using(function (Store $record): bool {
                    try {
                        app(AccountService::class)->archiveStore($record);

                        return true;
                    } catch (DeletionBlockedException $e) {
                        Notification::make()->title('Cannot archive this store')->body($e->getMessage())->danger()->send();

                        return false;
                    }
                }),
        ];
    }
}
