<?php

declare(strict_types=1);

namespace Logliy\phpDocumentor\Reflection\DocBlock\Tags\Factory;

use Logliy\phpDocumentor\Reflection\DocBlock\DescriptionFactory;
use Logliy\phpDocumentor\Reflection\DocBlock\Tag;
use Logliy\phpDocumentor\Reflection\DocBlock\Tags\Extends_;
use Logliy\phpDocumentor\Reflection\TypeResolver;
use Logliy\phpDocumentor\Reflection\Types\Context;
use Logliy\PHPStan\PhpDocParser\Ast\PhpDoc\ExtendsTagValueNode;
use Logliy\PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use Logliy\Webmozart\Assert\Assert;

use function is_string;

/**
 * @internal This class is not part of the BC promise of this library.
 */
final class ExtendsFactory implements PHPStanFactory
{
    private DescriptionFactory $descriptionFactory;
    private TypeResolver $typeResolver;

    public function __construct(TypeResolver $typeResolver, DescriptionFactory $descriptionFactory)
    {
        $this->descriptionFactory = $descriptionFactory;
        $this->typeResolver = $typeResolver;
    }

    public function supports(PhpDocTagNode $node, Context $context): bool
    {
        return $node->value instanceof ExtendsTagValueNode && $node->name === '@extends';
    }

    public function create(PhpDocTagNode $node, Context $context): Tag
    {
        $tagValue = $node->value;
        Assert::isInstanceOf($tagValue, ExtendsTagValueNode::class);

        $description = $tagValue->getAttribute('description');
        if (is_string($description) === false) {
            $description = $tagValue->description;
        }

        return new Extends_(
            $this->typeResolver->createType($tagValue->type, $context),
            $this->descriptionFactory->create($description, $context)
        );
    }
}
