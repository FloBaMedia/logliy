<?php declare(strict_types = 1);

namespace Logliy\PHPStan\PhpDocParser\Ast\PhpDoc;

use Logliy\PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprNode;
use Logliy\PHPStan\PhpDocParser\Ast\Node;
use Logliy\PHPStan\PhpDocParser\Ast\NodeAttributes;
use Logliy\PHPStan\PhpDocParser\Ast\Type\TypeNode;

class MethodTagValueParameterNode implements Node
{

	use NodeAttributes;

	/** @var TypeNode|null */
	public $type;

	/** @var bool */
	public $isReference;

	/** @var bool */
	public $isVariadic;

	/** @var string */
	public $parameterName;

	/** @var ConstExprNode|null */
	public $defaultValue;

	public function __construct(?TypeNode $type, bool $isReference, bool $isVariadic, string $parameterName, ?ConstExprNode $defaultValue)
	{
		$this->type = $type;
		$this->isReference = $isReference;
		$this->isVariadic = $isVariadic;
		$this->parameterName = $parameterName;
		$this->defaultValue = $defaultValue;
	}


	public function __toString(): string
	{
		$type = $this->type !== null ? "{$this->type} " : '';
		$isReference = $this->isReference ? '&' : '';
		$isVariadic = $this->isVariadic ? '...' : '';
		$default = $this->defaultValue !== null ? " = {$this->defaultValue}" : '';
		return "{$type}{$isReference}{$isVariadic}{$this->parameterName}{$default}";
	}

}
