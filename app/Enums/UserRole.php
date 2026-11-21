<?php

namespace App\Enums;

enum UserRole: string
{
    case Owner = 'owner';
    case Administrator = 'administrator';
    case ProductManager = 'product_manager';
    case InventoryManager = 'inventory_manager';
    case OrderManager = 'order_manager';
    case SupportAgent = 'support_agent';
    case Customer = 'customer';

    public function isStaff(): bool
    {
        return $this !== self::Customer;
    }

    public function canManageCatalog(): bool
    {
        return in_array($this, [self::Owner, self::Administrator, self::ProductManager], true);
    }

    public function canManageInventory(): bool
    {
        return in_array($this, [self::Owner, self::Administrator, self::InventoryManager], true);
    }

    public function canManageOrders(): bool
    {
        return in_array($this, [self::Owner, self::Administrator, self::OrderManager], true);
    }

    public function canManageSupport(): bool
    {
        return in_array($this, [self::Owner, self::Administrator, self::SupportAgent], true);
    }

    public function canManageAdministration(): bool
    {
        return in_array($this, [self::Owner, self::Administrator], true);
    }
}
