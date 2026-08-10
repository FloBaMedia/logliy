<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Logliy\Symfony\Component\Serializer\Context\Normalizer;

use Logliy\Symfony\Component\PropertyAccess\Exception\InvalidPropertyPathException;
use Logliy\Symfony\Component\PropertyAccess\PropertyPath;
use Logliy\Symfony\Component\Serializer\Context\ContextBuilderInterface;
use Logliy\Symfony\Component\Serializer\Context\ContextBuilderTrait;
use Logliy\Symfony\Component\Serializer\Exception\InvalidArgumentException;
use Logliy\Symfony\Component\Serializer\Normalizer\UnwrappingDenormalizer;

/**
 * A helper providing autocompletion for available UnwrappingDenormalizer options.
 *
 * @author Mathias Arlaud <mathias.arlaud@gmail.com>
 */
final class UnwrappingDenormalizerContextBuilder implements ContextBuilderInterface
{
    use ContextBuilderTrait;

    /**
     * Configures the path of wrapped data during denormalization.
     *
     * Eg: [foo].bar[bar]
     *
     * @see https://symfony.com/doc/current/components/property_access.html
     *
     * @throws InvalidArgumentException
     */
    public function withUnwrapPath(?string $unwrapPath): static
    {
        if (null === $unwrapPath) {
            return $this->with(UnwrappingDenormalizer::UNWRAP_PATH, null);
        }

        try {
            new PropertyPath($unwrapPath);
        } catch (InvalidPropertyPathException $e) {
            throw new InvalidArgumentException(\sprintf('The "%s" property path is not valid.', $unwrapPath), previous: $e);
        }

        return $this->with(UnwrappingDenormalizer::UNWRAP_PATH, $unwrapPath);
    }
}
