<?php

namespace App\Filament\Resources\Stores\Schemas;

use App\Enums\StoreStatus;
use App\Services\Media\ImageProcessor;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class StoreForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('owner_id')
                    ->relationship('owner', 'name')
                    ->required(),
                TextInput::make('name')
                    ->required(),
                TextInput::make('slug')
                    ->required(),
                Textarea::make('description')
                    ->columnSpanFull(),
                FileUpload::make('logo')
                    ->disk(ImageProcessor::DISK)
                    ->directory('stores')
                    ->image()
                    ->avatar()
                    ->imageEditor()
                    ->maxSize(4 * 1024)
                    ->rules([ImageProcessor::DIMENSIONS_RULE]),
                Select::make('status')
                    ->options(StoreStatus::class)
                    ->default('pending')
                    ->required(),
            ]);
    }
}
