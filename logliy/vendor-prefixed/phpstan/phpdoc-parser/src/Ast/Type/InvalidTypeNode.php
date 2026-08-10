<?php declare(strict_types = 1);

namespace Logliy\PHPStan\PhpDocParser\Ast\Type;

use Logliy\PHPStan\PhpDocParser\Ast\NodeAttributes;
use Logliy\PHPStan\PhpDocParser\Parser\ParserException;

class InvalidTypeNode implements TypeNode
{

	use NodeAttributes;

	/** @var mixed[] */
	private $exceptionArgs;

	public function __construct(ParserException $exception)
	{
		$this->exceptionArgs = [
			$exception->getCurrentTokenValue(),
			$exception->getCurrentTokenType(),
			$exception->getCurrentOffset(),
			$exception->getExpectedTokenType(),
			$exception->getExpectedTokenValue(),
			$exception->getCurrentTokenLine(),
		];
	}

	public function getException(): ParserException
	{
		return new ParserException(...$this->exceptionArgs);
	}

	public function __toString(): string
	{
		return '*Invalid type*';
	}

}
