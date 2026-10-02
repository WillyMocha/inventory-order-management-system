<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Supplier dan Customer sengaja dijaga sebagai dua entity terpisah meskipun
 * field-nya identik — relasinya berbeda dan sumber tidak memperlakukannya
 * sebagai satu collection polymorphic (data-model.md).
 */
final class Supplier
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $name,
        public readonly string $contact,
        public readonly string $address,
        public readonly bool $isActive,
    ) {
    }
}
