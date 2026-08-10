<?php declare(strict_types = 1);

namespace Logliy\PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine;

use Logliy\PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use Logliy\PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use Logliy\PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use Logliy\PHPStan\PhpDocParser\Ast\Node;
use Logliy\PHPStan\PhpDocParser\Ast\NodeAttributes;
use Logliy\PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;

/**
 * @phpstan-import-type ValueType from DoctrineArgument
 * @phpstan-type KeyType = ConstExprIntegerNode|ConstExprStringNode|IdentifierTypeNode|ConstFetchNode|null
 */
class DoctrineArrayItem implements Node
{

	use NodeAttributes;

	/** @var KeyType */
	public $key;

	/** @var ValueType */
	public $value;

	/**
	 * @param KeyType $key
	 * @param ValueType $value
	 */
	public function __construct($key, $value)
	{
		$this->key = $key;
		$this->value = $value;
	}


	public function __toString(): string
	{
		if ($this->key === null) {
			return (string) $this->value;
		}

		return $this->key . '=' . $this->value;
	}

}
