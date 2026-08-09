<?php

namespace Google\ApiCore\Testing;

use Google\ApiCore\Serializer;
use Google\Protobuf\DescriptorPool;
use Google\Protobuf\Internal\Message;
use Google\Protobuf\RepeatedField;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
abstract class GeneratedTest extends TestCase
{
    /**
     * @param mixed $expected
     * @param mixed $actual
     */
    public function assertProtobufEquals(&$expected, &$actual)
    {
        if ($expected === $actual) {
            
            $this->assertSame($expected, $actual);

            return;
        }

        if (is_array($expected) || $expected instanceof RepeatedField) {
            if (is_array($expected) === is_array($actual)) {
                $this->assertEquals($expected, $actual);
            }

            $this->assertCount(count($expected), $actual);

            $expectedValues = $this->getValues($expected);
            $actualValues = $this->getValues($actual);

            for ($i = 0; $i < count($expectedValues); $i++) {
                $expectedElement = $expectedValues[$i];
                $actualElement = $actualValues[$i];
                $this->assertProtobufEquals($expectedElement, $actualElement);
            }
        } else {
            $this->assertEquals($expected, $actual);
            if ($expected instanceof Message) {
                $pool = DescriptorPool::getGeneratedPool();
                $descriptor = $pool->getDescriptorByClassName(get_class($expected));

                $fieldCount = $descriptor->getFieldCount();
                for ($i = 0; $i < $fieldCount; $i++) {
                    $field = $descriptor->getField($i);
                    $getter = Serializer::getGetter($field->getName());
                    $expectedFieldValue = $expected->$getter();
                    $actualFieldValue = $actual->$getter();
                    $this->assertProtobufEquals($expectedFieldValue, $actualFieldValue);
                }
            }
        }
    }

    /**
     * @param iterable $field
     */
    private function getValues($field)
    {
        return array_values(
            is_array($field)
                ? $field
                : iterator_to_array($field)
        );
    }
}
