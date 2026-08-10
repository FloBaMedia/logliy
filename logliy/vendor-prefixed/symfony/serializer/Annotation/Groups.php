<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Logliy\Symfony\Component\Serializer\Annotation;

class_exists(\Logliy\Symfony\Component\Serializer\Attribute\Groups::class);

if (false) {
    /**
     * @deprecated since Symfony 7.4, use {@see \Symfony\Component\Serializer\Attribute\Groups} instead
     */
    #[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_PROPERTY | \Attribute::TARGET_CLASS)]
    class Groups extends \Logliy\Symfony\Component\Serializer\Attribute\Groups
    {
    }
}
