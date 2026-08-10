<?php declare(strict_types = 1);

namespace Logliy\PHPStan\PhpDocParser\Ast\NodeVisitor;

use Logliy\PHPStan\PhpDocParser\Ast\AbstractNodeVisitor;
use Logliy\PHPStan\PhpDocParser\Ast\Attribute;
use Logliy\PHPStan\PhpDocParser\Ast\Node;

final class CloningVisitor extends AbstractNodeVisitor
{

	public function enterNode(Node $originalNode)
	{
		$node = clone $originalNode;
		$node->setAttribute(Attribute::ORIGINAL_NODE, $originalNode);

		return $node;
	}

}
