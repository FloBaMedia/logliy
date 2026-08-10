<?php declare(strict_types = 1);

namespace Logliy\PHPStan\PhpDocParser\Ast\PhpDoc;

use Logliy\PHPStan\PhpDocParser\Ast\NodeAttributes;
use function trim;

class ParamLaterInvokedCallableTagValueNode implements PhpDocTagValueNode
{

	use NodeAttributes;

	/** @var string */
	public $parameterName;

	/** @var string (may be empty) */
	public $description;

	public function __construct(string $parameterName, string $description)
	{
		$this->parameterName = $parameterName;
		$this->description = $description;
	}

	public function __toString(): string
	{
		return trim("{$this->parameterName} {$this->description}");
	}

}
