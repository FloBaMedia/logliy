<?php
/*
 * This file is part of phpDocumentor.
 *
 *  For the full copyright and license information, please view the LICENSE
 *  file that was distributed with this source code.
 *
 *  @link      http://phpdoc.org
 *
 */

declare(strict_types=1);

namespace Logliy\phpDocumentor\Reflection\PseudoTypes;

use Logliy\phpDocumentor\Reflection\PseudoType;
use Logliy\phpDocumentor\Reflection\Type;
use Logliy\phpDocumentor\Reflection\Types\Mixed_;

use function sprintf;

/** @psalm-immutable */
final class ConstExpression implements PseudoType
{
    private Type $owner;
    private string $expression;

    public function __construct(Type $owner, string $expression)
    {
        $this->owner = $owner;
        $this->expression = $expression;
    }

    public function getOwner(): Type
    {
        return $this->owner;
    }

    public function getExpression(): string
    {
        return $this->expression;
    }

    public function underlyingType(): Type
    {
        return new Mixed_();
    }

    public function __toString(): string
    {
        return sprintf('%s::%s', (string) $this->owner, $this->expression);
    }
}
