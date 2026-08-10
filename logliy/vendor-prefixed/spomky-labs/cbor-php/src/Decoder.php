<?php

declare(strict_types=1);

namespace Logliy\CBOR;

use Logliy\CBOR\OtherObject\BreakObject;
use Logliy\CBOR\OtherObject\DoublePrecisionFloatObject;
use Logliy\CBOR\OtherObject\FalseObject;
use Logliy\CBOR\OtherObject\HalfPrecisionFloatObject;
use Logliy\CBOR\OtherObject\NullObject;
use Logliy\CBOR\OtherObject\OtherObjectManager;
use Logliy\CBOR\OtherObject\OtherObjectManagerInterface;
use Logliy\CBOR\OtherObject\SimpleObject;
use Logliy\CBOR\OtherObject\SinglePrecisionFloatObject;
use Logliy\CBOR\OtherObject\TrueObject;
use Logliy\CBOR\OtherObject\UndefinedObject;
use Logliy\CBOR\Tag\Base16EncodingTag;
use Logliy\CBOR\Tag\Base64EncodingTag;
use Logliy\CBOR\Tag\Base64Tag;
use Logliy\CBOR\Tag\Base64UrlEncodingTag;
use Logliy\CBOR\Tag\Base64UrlTag;
use Logliy\CBOR\Tag\BigFloatTag;
use Logliy\CBOR\Tag\CBOREncodingTag;
use Logliy\CBOR\Tag\CBORTag;
use Logliy\CBOR\Tag\DatetimeTag;
use Logliy\CBOR\Tag\DecimalFractionTag;
use Logliy\CBOR\Tag\MimeTag;
use Logliy\CBOR\Tag\NegativeBigIntegerTag;
use Logliy\CBOR\Tag\TagManager;
use Logliy\CBOR\Tag\TagManagerInterface;
use Logliy\CBOR\Tag\TimestampTag;
use Logliy\CBOR\Tag\UnsignedBigIntegerTag;
use Logliy\CBOR\Tag\UriTag;
use InvalidArgumentException;
use function ord;
use RuntimeException;
use function sprintf;
use const STR_PAD_LEFT;

final class Decoder implements DecoderInterface
{
    private TagManagerInterface $tagObjectManager;

    private OtherObjectManagerInterface $otherTypeManager;

    public function __construct(
        ?TagManagerInterface $tagObjectManager = null,
        ?OtherObjectManagerInterface $otherTypeManager = null
    ) {
        $this->tagObjectManager = $tagObjectManager ?? $this->generateTagManager();
        $this->otherTypeManager = $otherTypeManager ?? $this->generateOtherObjectManager();
    }

    public static function create(
        ?TagManagerInterface $tagObjectManager = null,
        ?OtherObjectManagerInterface $otherTypeManager = null
    ): self {
        return new self($tagObjectManager, $otherTypeManager);
    }

    public function decode(Stream $stream): CBORObject
    {
        return $this->process($stream, false);
    }

    private function process(Stream $stream, bool $breakable): CBORObject
    {
        $ib = ord($stream->read(1));
        $mt = $ib >> 5;
        $ai = $ib & 0b00011111;
        $val = null;
        switch ($ai) {
            case CBORObject::LENGTH_1_BYTE: //24
            case CBORObject::LENGTH_2_BYTES: //25
            case CBORObject::LENGTH_4_BYTES: //26
            case CBORObject::LENGTH_8_BYTES: //27
                $val = $stream->read(2 ** ($ai & 0b00000111));
                break;
            case CBORObject::FUTURE_USE_1: //28
            case CBORObject::FUTURE_USE_2: //29
            case CBORObject::FUTURE_USE_3: //30
                throw new InvalidArgumentException(sprintf(
                    'Cannot parse the data. Found invalid Additional Information "%s" (%d).',
                    str_pad(decbin($ai), 8, '0', STR_PAD_LEFT),
                    $ai
                ));
            case CBORObject::LENGTH_INDEFINITE: //31
                return $this->processInfinite($stream, $mt, $breakable);
        }

        return $this->processFinite($stream, $mt, $ai, $val);
    }

    private function processFinite(Stream $stream, int $mt, int $ai, ?string $val): CBORObject
    {
        switch ($mt) {
            case CBORObject::MAJOR_TYPE_UNSIGNED_INTEGER: //0
                return UnsignedIntegerObject::createObjectForValue($ai, $val);
            case CBORObject::MAJOR_TYPE_NEGATIVE_INTEGER: //1
                return NegativeIntegerObject::createObjectForValue($ai, $val);
            case CBORObject::MAJOR_TYPE_BYTE_STRING: //2
                $length = $val === null ? $ai : Utils::binToInt($val);

                return ByteStringObject::create($stream->read($length));
            case CBORObject::MAJOR_TYPE_TEXT_STRING: //3
                $length = $val === null ? $ai : Utils::binToInt($val);

                return TextStringObject::create($stream->read($length));
            case CBORObject::MAJOR_TYPE_LIST: //4
                $object = ListObject::create();
                $nbItems = $val === null ? $ai : Utils::binToInt($val);
                for ($i = 0; $i < $nbItems; ++$i) {
                    $object->add($this->process($stream, false));
                }

                return $object;
            case CBORObject::MAJOR_TYPE_MAP: //5
                $object = MapObject::create();
                $nbItems = $val === null ? $ai : Utils::binToInt($val);
                for ($i = 0; $i < $nbItems; ++$i) {
                    $object->add($this->process($stream, false), $this->process($stream, false));
                }

                return $object;
            case CBORObject::MAJOR_TYPE_TAG: //6
                return $this->tagObjectManager->createObjectForValue($ai, $val, $this->process($stream, false));
            case CBORObject::MAJOR_TYPE_OTHER_TYPE: //7
                return $this->otherTypeManager->createObjectForValue($ai, $val);
            default:
                throw new RuntimeException(sprintf(
                    'Unsupported major type "%s" (%d).',
                    str_pad(decbin($mt), 5, '0', STR_PAD_LEFT),
                    $mt
                )); // Should never append
        }
    }

    private function processInfinite(Stream $stream, int $mt, bool $breakable): CBORObject
    {
        switch ($mt) {
            case CBORObject::MAJOR_TYPE_BYTE_STRING: //2
                $object = IndefiniteLengthByteStringObject::create();
                while (! ($it = $this->process($stream, true)) instanceof BreakObject) {
                    if (! $it instanceof ByteStringObject) {
                        throw new RuntimeException(
                            'Unable to parse the data. Infinite Byte String object can only get Byte String objects.'
                        );
                    }
                    $object->add($it);
                }

                return $object;
            case CBORObject::MAJOR_TYPE_TEXT_STRING: //3
                $object = IndefiniteLengthTextStringObject::create();
                while (! ($it = $this->process($stream, true)) instanceof BreakObject) {
                    if (! $it instanceof TextStringObject) {
                        throw new RuntimeException(
                            'Unable to parse the data. Infinite Text String object can only get Text String objects.'
                        );
                    }
                    $object->add($it);
                }

                return $object;
            case CBORObject::MAJOR_TYPE_LIST: //4
                $object = IndefiniteLengthListObject::create();
                $it = $this->process($stream, true);
                while (! $it instanceof BreakObject) {
                    $object->add($it);
                    $it = $this->process($stream, true);
                }

                return $object;
            case CBORObject::MAJOR_TYPE_MAP: //5
                $object = IndefiniteLengthMapObject::create();
                while (! ($it = $this->process($stream, true)) instanceof BreakObject) {
                    $object->add($it, $this->process($stream, false));
                }

                return $object;
            case CBORObject::MAJOR_TYPE_OTHER_TYPE: //7
                if (! $breakable) {
                    throw new InvalidArgumentException('Cannot parse the data. No enclosing indefinite.');
                }

                return BreakObject::create();
            case CBORObject::MAJOR_TYPE_UNSIGNED_INTEGER: //0
            case CBORObject::MAJOR_TYPE_NEGATIVE_INTEGER: //1
            case CBORObject::MAJOR_TYPE_TAG: //6
            default:
                throw new InvalidArgumentException(sprintf(
                    'Cannot parse the data. Found infinite length for Major Type "%s" (%d).',
                    str_pad(decbin($mt), 5, '0', STR_PAD_LEFT),
                    $mt
                ));
        }
    }

    private function generateTagManager(): TagManagerInterface
    {
        return TagManager::create([
            DatetimeTag::class,
            TimestampTag::class,

            UnsignedBigIntegerTag::class,
            NegativeBigIntegerTag::class,

            DecimalFractionTag::class,
            BigFloatTag::class,

            Base64UrlEncodingTag::class,
            Base64EncodingTag::class,
            Base16EncodingTag::class,
            CBOREncodingTag::class,

            UriTag::class,
            Base64UrlTag::class,
            Base64Tag::class,
            MimeTag::class,

            CBORTag::class,
        ]);
    }

    private function generateOtherObjectManager(): OtherObjectManagerInterface
    {
        return OtherObjectManager::create()
            ->add(BreakObject::class)
            ->add(SimpleObject::class)
            ->add(FalseObject::class)
            ->add(TrueObject::class)
            ->add(NullObject::class)
            ->add(UndefinedObject::class)
            ->add(HalfPrecisionFloatObject::class)
            ->add(SinglePrecisionFloatObject::class)
            ->add(DoublePrecisionFloatObject::class)
        ;
    }
}
