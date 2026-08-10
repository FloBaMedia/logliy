<?php declare(strict_types = 1);

namespace Logliy\PHPStan\PhpDocParser\Ast\Type;

use Logliy\PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprNode;
use Logliy\PHPStan\PhpDocParser\Ast\NodeAttributes;

class ConstTypeNode implements TypeNode
{

	use NodeAttributes;

	/** @var ConstExprNode */
	public $constExpr;

	public function __construct(ConstExprNode $constExpr)
	{
		$this->constExpr = $constExpr;
	}

	public function __toString(): string
	{
		return $this->constExpr->__toString();
	}

}
