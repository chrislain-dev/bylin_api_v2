<?php

declare(strict_types=1);

namespace Modules\Inventory\Enums;

enum StockReason: string
{
    case ADJUSTMENT = 'adjustment';
    case SALE = 'sale';
    case RETURN = 'return';
    case DAMAGED = 'damaged';
    case RESTOCK = 'restock';
    case LOST = 'lost';
    case ORDER = 'order';

    public function label(): string
    {
        return match ($this) {
            self::ADJUSTMENT => 'Ajustement manuel',
            self::SALE => 'Vente / Réception',
            self::RETURN => 'Retour client',
            self::DAMAGED => 'Produit endommagé',
            self::RESTOCK => 'Produit restocké',
            self::LOST => 'Produit perdu',
            self::ORDER => 'Commande client',
        };
    }

    public function defaultMovementType(): string
    {
        return match ($this) {
            self::SALE, self::ORDER => 'out',
            self::RETURN, self::RESTOCK => 'in',
            default => 'adjustment',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
