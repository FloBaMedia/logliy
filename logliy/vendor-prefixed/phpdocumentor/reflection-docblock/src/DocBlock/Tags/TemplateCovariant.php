<?php

declare(strict_types=1);

/**
 * This file is part of phpDocumentor.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @link      http://phpdoc.org
 */

namespace Logliy\phpDocumentor\Reflection\DocBlock\Tags;

use Logliy\phpDocumentor\Reflection\DocBlock\Description;
use Logliy\phpDocumentor\Reflection\DocBlock\DescriptionFactory;
use Logliy\phpDocumentor\Reflection\Type;
use Logliy\phpDocumentor\Reflection\TypeResolver;
use Logliy\phpDocumentor\Reflection\Types\Context as TypeContext;
use Logliy\Webmozart\Assert\Assert;

/**
 * Reflection class for a {@}template-covariant tag in a Docblock.
 */
final class TemplateCovariant extends TagWithType implements Factory\StaticMethod
{
    public function __construct(Type $type, ?Description $description = null)
    {
        $this->name        = 'template-covariant';
        $this->type        = $type;
        $this->description = $description;
    }

    public static function create(
        string $body,
        ?TypeResolver $typeResolver = null,
        ?DescriptionFactory $descriptionFactory = null,
        ?TypeContext $context = null
    ): self {
        Assert::notNull($typeResolver);
        Assert::notNull($descriptionFactory);

        [$type, $description] = self::extractTypeFromBody($body);

        $type        = $typeResolver->resolve($type, $context);
        $description = $descriptionFactory->create($description, $context);

        return new static($type, $description);
    }
}
