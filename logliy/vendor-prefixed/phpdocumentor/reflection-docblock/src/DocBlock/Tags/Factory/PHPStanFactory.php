<?php

declare(strict_types=1);

namespace Logliy\phpDocumentor\Reflection\DocBlock\Tags\Factory;

use Logliy\phpDocumentor\Reflection\DocBlock\Tag;
use Logliy\phpDocumentor\Reflection\Types\Context;
use Logliy\PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;

interface PHPStanFactory
{
    public function create(PhpDocTagNode $node, Context $context): Tag;

    public function supports(PhpDocTagNode $node, Context $context): bool;
}
