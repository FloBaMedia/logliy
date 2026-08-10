<?php declare(strict_types = 1);

namespace Logliy\PHPStan\PhpDocParser\Ast\PhpDoc;

use Logliy\PHPStan\PhpDocParser\Ast\NodeAttributes;

class PhpDocTextNode implements PhpDocChildNode
{

	use NodeAttributes;

	/** @var string */
	public $text;

	public function __construct(string $text)
	{
		$this->text = $text;
	}


	public function __toString(): string
	{
		return $this->text;
	}

}
