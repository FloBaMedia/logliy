<?php declare(strict_types = 1);

namespace Logliy\PHPStan\PhpDocParser\Ast\PhpDoc;

use Logliy\PHPStan\PhpDocParser\Ast\NodeAttributes;
use Logliy\PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use function trim;

class UsesTagValueNode implements PhpDocTagValueNode
{

	use NodeAttributes;

	/** @var GenericTypeNode */
	public $type;

	/** @var string (may be empty) */
	public $description;

	public function __construct(GenericTypeNode $type, string $description)
	{
		$this->type = $type;
		$this->description = $description;
	}


	public function __toString(): string
	{
		return trim("{$this->type} {$this->description}");
	}

}
