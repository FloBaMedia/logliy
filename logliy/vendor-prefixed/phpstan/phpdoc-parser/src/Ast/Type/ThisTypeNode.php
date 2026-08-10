<?php declare(strict_types = 1);

namespace Logliy\PHPStan\PhpDocParser\Ast\Type;

use Logliy\PHPStan\PhpDocParser\Ast\NodeAttributes;

class ThisTypeNode implements TypeNode
{

	use NodeAttributes;

	public function __toString(): string
	{
		return '$this';
	}

}
