<?php

declare(strict_types=1);

namespace CuyZ\Valinor\Type\Types;

use CuyZ\Valinor\Type\StringType;
use CuyZ\Valinor\Type\Type;
use CuyZ\Valinor\Type\Types\Exception\CannotCastValue;
use CuyZ\Valinor\Utility\IsSingleton;
use Stringable;

use function is_numeric;
use function is_string;

/** @api */
final class NativeStringType implements StringType
{
    use IsSingleton;

    public function accepts($value): bool
    {
        return is_string($value);
    }

    public function matches(Type $other): bool
    {
        if ($other instanceof UnionType) {
            return $other->isMatchedBy($this);
        }

        return $other instanceof self
            || $other instanceof MixedType;
    }

    public function canCast($value): bool
    {
        return is_string($value)
            || is_numeric($value)
            || $value instanceof Stringable;
    }

    public function cast($value): string
    {
        if (! $this->canCast($value)) {
            throw new CannotCastValue($value, $this);
        }

        return (string)$value; // @phpstan-ignore-line
    }

    public function __toString(): string
    {
        return 'string';
    }
}
