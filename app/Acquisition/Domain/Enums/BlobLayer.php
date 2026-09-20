<?php

namespace App\Acquisition\Domain\Enums;

/**
 * The three artifact layers (plan §9). The value doubles as the first path
 * segment of a blob key, so RAW and NORMALIZED objects never collide even
 * when they happen to contain identical bytes.
 */
enum BlobLayer: string
{
    case Raw = 'raw';
    case Normalized = 'normalized';
    case Derived = 'derived';
}
