<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Enums\ProductStatus;
use App\Models\ProductImage;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('store_id')
                    ->relationship('store', 'name'),
                TextInput::make('title')
                    ->required(),
                TextInput::make('slug')
                    ->required(),
                Textarea::make('description')
                    ->columnSpanFull(),
                TextInput::make('price_cents')
                    ->required()
                    ->numeric(),
                TextInput::make('currency')
                    ->required()
                    ->default('USD'),
                Select::make('status')
                    ->options(ProductStatus::class)
                    ->default('draft')
                    ->required(),
                // Gallery: one row per image, drag to reorder; the first one is the primary image.
                Repeater::make('images')
                    ->relationship()
                    ->orderColumn('position')
                    ->reorderableWithDragAndDrop()
                    ->schema([
                        FileUpload::make('path')
                            ->label('Image')
                            ->disk(ProductImage::DISK)
                            ->directory('products')
                            ->image()
                            ->imageEditor()
                            ->maxSize(8 * 1024)
                            ->required(),
                    ])
                    ->defaultItems(0)
                    ->addActionLabel('Add image')
                    ->columnSpanFull(),
            ]);
    }
}
