<?php

declare(strict_types=1);

namespace Logliy\phpDocumentor\Reflection\DocBlock\Tags\Factory;

use Logliy\phpDocumentor\Reflection\DocBlock\DescriptionFactory;
use Logliy\phpDocumentor\Reflection\DocBlock\Tag;
use Logliy\phpDocumentor\Reflection\DocBlock\Tags\Method;
use Logliy\phpDocumentor\Reflection\DocBlock\Tags\MethodParameter;
use Logliy\phpDocumentor\Reflection\Type;
use Logliy\phpDocumentor\Reflection\TypeResolver;
use Logliy\phpDocumentor\Reflection\Types\Context;
use Logliy\phpDocumentor\Reflection\Types\Mixed_;
use Logliy\phpDocumentor\Reflection\Types\Void_;
use Logliy\PHPStan\PhpDocParser\Ast\PhpDoc\MethodTagValueNode;
use Logliy\PHPStan\PhpDocParser\Ast\PhpDoc\MethodTagValueParameterNode;
use Logliy\PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use Logliy\Webmozart\Assert\Assert;

use function array_map;
use function trim;

/**
 * @internal This class is not part of the BC promise of this library.
 */
final class MethodFactory implements PHPStanFactory
{
    private DescriptionFactory $descriptionFactory;
    private TypeResolver $typeResolver;

    public function __construct(TypeResolver $typeResolver, DescriptionFactory $descriptionFactory)
    {
        $this->descriptionFactory = $descriptionFactory;
        $this->typeResolver = $typeResolver;
    }

    public function create(PhpDocTagNode $node, Context $context): Tag
    {
        $tagValue = $node->value;
        Assert::isInstanceOf($tagValue, MethodTagValueNode::class);

        return new Method(
            $tagValue->methodName,
            [],
            $this->createReturnType($tagValue, $context),
            $tagValue->isStatic,
            $this->descriptionFactory->create($tagValue->description, $context),
            false,
            array_map(
                function (MethodTagValueParameterNode $param) use ($context) {
                    return new MethodParameter(
                        trim($param->parameterName, '$'),
                        $param->type === null ? new Mixed_() : $this->typeResolver->createType(
                            $param->type,
                            $context
                        ),
                        $param->isReference,
                        $param->isVariadic,
                        $param->defaultValue === null ?
                            MethodParameter::NO_DEFAULT_VALUE :
                            (string) $param->defaultValue
                    );
                },
                $tagValue->parameters
            ),
        );
    }

    public function supports(PhpDocTagNode $node, Context $context): bool
    {
        return $node->value instanceof MethodTagValueNode;
    }

    private function createReturnType(MethodTagValueNode $tagValue, Context $context): Type
    {
        if ($tagValue->returnType === null) {
            return new Void_();
        }

        return $this->typeResolver->createType($tagValue->returnType, $context);
    }
}
