<?php

namespace App\Filament\Resources\Users\Pages;

use App\Exceptions\DeletionBlockedException;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Services\Account\AccountService;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Anonymise + soft-delete, store archived first (AccountService); refused while orders are open.
            DeleteAction::make()
                ->label('Delete')
                ->modalHeading('Delete account')
                ->using(function (User $record): bool {
                    try {
                        app(AccountService::class)->close($record);

                        return true;
                    } catch (DeletionBlockedException $e) {
                        Notification::make()->title('Cannot delete this account')->body($e->getMessage())->danger()->send();

                        return false;
                    }
                }),
        ];
    }
}
