<?php

declare(strict_types=1);

/**
 * This file is part of phpDocumentor.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @link https://phpdoc.org
 */

namespace Logliy\phpDocumentor\Reflection\PseudoTypes;

use Logliy\phpDocumentor\Reflection\PseudoType;
use Logliy\phpDocumentor\Reflection\Type;
use Logliy\phpDocumentor\Reflection\Types\Boolean;

use function class_alias;

/**
 * Value Object representing the PseudoType 'False', which is a Boolean type.
 *
 * @psalm-immutable
 */
final class False_ extends Boolean implements PseudoType
{
    public function underlyingType(): Type
    {
        return new Boolean();
    }

    public function __toString(): string
    {
        return 'false';
    }
}

class_alias(False_::class, 'Logliy\phpDocumentor\Reflection\Types\False_', false);
