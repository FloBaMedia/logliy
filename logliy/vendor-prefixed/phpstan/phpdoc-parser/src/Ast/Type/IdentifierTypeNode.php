<?php declare(strict_types = 1);

namespace Logliy\PHPStan\PhpDocParser\Ast\Type;

use Logliy\PHPStan\PhpDocParser\Ast\NodeAttributes;

class IdentifierTypeNode implements TypeNode
{

	use NodeAttributes;

	/** @var string */
	public $name;

	public function __construct(string $name)
	{
		$this->name = $name;
	}


	public function __toString(): string
	{
		return $this->name;
	}

}
