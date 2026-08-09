<?php

namespace Google\ApiCore;

use Exception;
use Google\Protobuf\Any;
use Google\Protobuf\Descriptor;
use Google\Protobuf\DescriptorPool;
use Google\Protobuf\FieldDescriptor;
use Google\Protobuf\Internal\Message;
use RuntimeException;

/**
 * Collection of methods to help with serialization of protobuf objects
 */
class Serializer
{
    const MAP_KEY_FIELD_NAME = 'key';
    const MAP_VALUE_FIELD_NAME = 'value';

    private static $phpArraySerializer;
    
    private static array $getterMap = [];
    private static array $setterMap = [];
    private static array $snakeCaseMap = [];
    private static array $camelCaseMap = [];

    private $fieldTransformers;
    private $messageTypeTransformers;
    private $decodeFieldTransformers;
    private $decodeMessageTypeTransformers;
    
    
    
    
    private $customEncoders;

    private $descriptorMaps = [];

    /**
     * Serializer constructor.
     *
     * @param array $fieldTransformers An array mapping field names to transformation functions
     * @param array $messageTypeTransformers An array mapping message names to transformation functions
     * @param array $decodeFieldTransformers An array mapping field names to transformation functions
     * @param array $decodeMessageTypeTransformers An array mapping message names to transformation functions
     */
    public function __construct(
        $fieldTransformers = [],
        $messageTypeTransformers = [],
        $decodeFieldTransformers = [],
        $decodeMessageTypeTransformers = [],
        $customEncoders = [],
    ) {
        $this->fieldTransformers = $fieldTransformers;
        $this->messageTypeTransformers = $messageTypeTransformers;
        $this->decodeFieldTransformers = $decodeFieldTransformers;
        $this->decodeMessageTypeTransformers = $decodeMessageTypeTransformers;
        $this->customEncoders = $customEncoders;
    }

    /**
     * Encode protobuf message as a PHP array
     *
     * @param mixed $message
     * @return array
     * @throws ValidationException
     */
    public function encodeMessage($message)
    {
        $cls = get_class($message);

        
        
        if (array_key_exists($cls, $this->customEncoders)) {
            $func = $this->customEncoders[$cls];
            return call_user_func($func, $message);
        }
        
        $pool = DescriptorPool::getGeneratedPool();
        $messageType = $pool->getDescriptorByClassName(get_class($message));
        try {
            return $this->encodeMessageImpl($message, $messageType);
        } catch (\Exception $e) {
            throw new ValidationException(
                'Error encoding message: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Decode PHP array into the specified protobuf message
     *
     * @param mixed $message
     * @param array $data
     * @return mixed
     * @throws ValidationException
     */
    public function decodeMessage($message, array $data)
    {
        
        $pool = DescriptorPool::getGeneratedPool();
        $messageType = $pool->getDescriptorByClassName(get_class($message));
        try {
            return $this->decodeMessageImpl($message, $messageType, $data);
        } catch (\Exception $e) {
            throw new ValidationException(
                'Error decoding message: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * @param Message $message
     * @return string Json representation of $message
     * @throws ValidationException
     */
    public static function serializeToJson(Message $message)
    {
        return json_encode(self::serializeToPhpArray($message), JSON_PRETTY_PRINT);
    }

    /**
     * @param Message $message
     * @return array PHP array representation of $message
     * @throws ValidationException
     */
    public static function serializeToPhpArray(Message $message)
    {
        return self::getPhpArraySerializer()->encodeMessage($message);
    }

    /**
     * Decode metadata received from gRPC status object
     *
     * @param array $metadata
     * @param null|array $errors
     * @return array
     */
    public static function decodeMetadata(array $metadata, ?array &$errors = null)
    {
        if (count($metadata) == 0) {
            return [];
        }
        
        KnownTypes::addKnownTypesToDescriptorPool();
        $result = [];
        
        if (isset($metadata['grpc-status-details-bin'])) {
            $status = new \Google\Rpc\Status();
            $status->mergeFromString($metadata['grpc-status-details-bin'][0]);
            foreach ($status->getDetails() as $any) {
                if (isset(KnownTypes::TYPE_URLS[$any->getTypeUrl()])) {
                    $class = KnownTypes::TYPE_URLS[$any->getTypeUrl()];
                    new $class(); 
                }
                try {
                    $error = $any->unpack();
                } catch (Exception $ex) {
                    
                    $error = $any;
                }
                if (!is_null($errors)) {
                    $errors[] = $error;
                }
                $result[] = [
                    '@type' => $any->getTypeUrl(),
                ] + self::serializeToPhpArray($error);
            }
            return $result;
        }

        
        
        
        foreach ($metadata as $key => $values) {
            foreach ($values as $value) {
                $decodedValue = ['@type' => $key];
                if (self::hasBinaryHeaderSuffix($key)) {
                    if (isset(KnownTypes::BIN_TYPES[$key])) {
                        $class = KnownTypes::BIN_TYPES[$key];
                        /** @var Message $message */
                        $message = new $class();
                        try {
                            $message->mergeFromString($value);
                            $decodedValue += self::serializeToPhpArray($message);
                            if (!is_null($errors)) {
                                $errors[] = $message;
                            }
                        } catch (\Exception $e) {
                            
                            $decodedValue['data'] = '<Unable to deserialize data>';
                        }
                    } else {
                        
                        $decodedValue['data'] = '<Unknown Binary Data>';
                    }
                } else {
                    $decodedValue['data'] = $value;
                }
                $result[] = $decodedValue;
            }
        }
        return $result;
    }

    /**
     * Decode an array of Any messages into a printable PHP array.
     *
     * @param iterable $anyArray
     * @return array
     */
    public static function decodeAnyMessages($anyArray)
    {
        $results = [];
        foreach ($anyArray as $any) {
            try {
                /** @var Any $any */
                /** @var Message $unpacked */
                $unpacked = $any->unpack();
                $results[] = self::serializeToPhpArray($unpacked);
            } catch (\Exception $ex) {
                
                $results[] = [
                    'typeUrl' => $any->getTypeUrl(),
                    'value' => '<Unknown Binary Data>',
                ];
            }
        }
        return $results;
    }

    /**
     * @param FieldDescriptor $field
     * @param Message|array|string $data
     * @return mixed
     * @throws \Exception
     */
    private function encodeElement(FieldDescriptor $field, $data)
    {
        switch ($field->getType()) {
            case GPBType::MESSAGE:
                if (is_array($data)) {
                    $result = $data;
                } else {
                    $result = $this->encodeMessageImpl($data, $field->getMessageType());
                }
                $messageType = $field->getMessageType()->getFullName();
                if (isset($this->messageTypeTransformers[$messageType])) {
                    $result = $this->messageTypeTransformers[$messageType]($result);
                }
                break;
            default:
                $result = $data;
                break;
        }

        if (isset($this->fieldTransformers[$field->getName()])) {
            $result = $this->fieldTransformers[$field->getName()]($result);
        }
        return $result;
    }

    private function getDescriptorMaps(Descriptor $descriptor)
    {
        if (!isset($this->descriptorMaps[$descriptor->getFullName()])) {
            $fieldsByName = [];
            $fieldCount = $descriptor->getFieldCount();
            for ($i = 0; $i < $fieldCount; $i++) {
                $field = $descriptor->getField($i);
                $fieldsByName[$field->getName()] = $field;
            }
            $fieldToOneof = [];
            $oneofCount = $descriptor->getOneofDeclCount();
            for ($i = 0; $i < $oneofCount; $i++) {
                $oneof = $descriptor->getOneofDecl($i);
                $oneofFieldCount = $oneof->getFieldCount();
                for ($j = 0; $j < $oneofFieldCount; $j++) {
                    $field = $oneof->getField($j);
                    $fieldToOneof[$field->getName()] = $oneof->getName();
                }
            }
            $this->descriptorMaps[$descriptor->getFullName()] = [$fieldsByName, $fieldToOneof];
        }
        return $this->descriptorMaps[$descriptor->getFullName()];
    }

    /**
     * @param Message $message
     * @param Descriptor $messageType
     * @return array
     * @throws \Exception
     */
    private function encodeMessageImpl(Message $message, Descriptor $messageType)
    {
        $data = [];

        
        
        list($fields, $fieldsToOneof) = $this->getDescriptorMaps($messageType);
        foreach ($fields as $field) {
            $key = $field->getName();
            $getter = self::getGetter($key);
            $v = $message->$getter();

            if (is_null($v)) {
                continue;
            }

            
            if (isset($fieldsToOneof[$key])) {
                $oneofName = $fieldsToOneof[$key];
                $oneofGetter =  self::getGetter($oneofName);
                if ($message->$oneofGetter() !== $key) {
                    continue;
                }
            }

            if ($field->isMap()) {
                list($mapFieldsByName, $_) = $this->getDescriptorMaps($field->getMessageType());
                $keyField = $mapFieldsByName[self::MAP_KEY_FIELD_NAME];
                $valueField = $mapFieldsByName[self::MAP_VALUE_FIELD_NAME];
                $arr = [];
                foreach ($v as $k => $vv) {
                    $arr[$this->encodeElement($keyField, $k)] = $this->encodeElement($valueField, $vv);
                }
                $v = $arr;
            } elseif ($this->checkFieldRepeated($field)) {
                $arr = [];
                foreach ($v as $k => $vv) {
                    $arr[$k] = $this->encodeElement($field, $vv);
                }
                $v = $arr;
            } else {
                $v = $this->encodeElement($field, $v);
            }

            $key = self::toCamelCase($key);
            $data[$key] = $v;
        }

        return $data;
    }

    /**
     * @param FieldDescriptor $field
     * @param mixed $data
     * @return mixed
     * @throws \Exception
     */
    private function decodeElement(FieldDescriptor $field, $data)
    {
        if (isset($this->decodeFieldTransformers[$field->getName()])) {
            $data = $this->decodeFieldTransformers[$field->getName()]($data);
        }

        switch ($field->getType()) {
            case GPBType::MESSAGE:
                if ($data instanceof Message) {
                    return $data;
                }
                $messageType = $field->getMessageType();
                $messageTypeName = $messageType->getFullName();
                $klass = $messageType->getClass();
                $msg = new $klass();
                if (isset($this->decodeMessageTypeTransformers[$messageTypeName])) {
                    $data = $this->decodeMessageTypeTransformers[$messageTypeName]($data);
                }

                return $this->decodeMessageImpl($msg, $messageType, $data);
            default:
                return $data;
        }
    }

    /**
     * @param Message $message
     * @param Descriptor $messageType
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    private function decodeMessageImpl(Message $message, Descriptor $messageType, array $data)
    {
        list($fieldsByName, $_) = $this->getDescriptorMaps($messageType);
        foreach ($data as $key => $v) {
            
            $fieldName = self::toSnakeCase($key);

            
            if (!isset($fieldsByName[$fieldName])) {
                throw new RuntimeException(sprintf(
                    'cannot handle unknown field %s on message %s',
                    $fieldName,
                    $messageType->getFullName()
                ));
            }

            /** @var FieldDescriptor $field */
            $field = $fieldsByName[$fieldName];

            if ($field->isMap()) {
                list($mapFieldsByName, $_) = $this->getDescriptorMaps($field->getMessageType());
                $keyField = $mapFieldsByName[self::MAP_KEY_FIELD_NAME];
                $valueField = $mapFieldsByName[self::MAP_VALUE_FIELD_NAME];
                $arr = [];
                foreach ($v as $k => $vv) {
                    $arr[$this->decodeElement($keyField, $k)] = $this->decodeElement($valueField, $vv);
                }
                $value = $arr;
            } elseif ($this->checkFieldRepeated($field)) {
                $arr = [];
                foreach ($v as $k => $vv) {
                    $arr[$k] = $this->decodeElement($field, $vv);
                }
                $value = $arr;
            } else {
                $value = $this->decodeElement($field, $v);
            }

            $setter = self::getSetter($field->getName());
            $message->$setter($value);

            
            
            unset($value);
        }
        return $message;
    }

    /**
     * @param FieldDescriptor $field
     * @return bool
     */
    private function checkFieldRepeated(FieldDescriptor $field): bool
    {
        if (method_exists($field, 'isRepeated')) {
            return $field->isRepeated();
        }

        if (method_exists($field, 'getLabel')) {
            return $field->getLabel() === GPBLabel::REPEATED;
        }

        throw new \Exception('No field repeated method avaialble');
    }

    /**
     * @param string $name
     * @return string Getter function
     */
    public static function getGetter(string $name)
    {
        if (!isset(self::$getterMap[$name])) {
            self::$getterMap[$name] = 'get' . ucfirst(self::toCamelCase($name));
        }
        return self::$getterMap[$name];
    }

    /**
     * @param string $name
     * @return string Setter function
     */
    public static function getSetter(string $name)
    {
        if (!isset(self::$setterMap[$name])) {
            self::$setterMap[$name] = 'set' . ucfirst(self::toCamelCase($name));
        }
        return self::$setterMap[$name];
    }

    /**
     * Convert string from camelCase to snake_case
     *
     * @param string $key
     * @return string
     */
    public static function toSnakeCase(string $key)
    {
        if (!isset(self::$snakeCaseMap[$key])) {
            self::$snakeCaseMap[$key] = strtolower(
                preg_replace(['/([a-z\d])([A-Z])/', '/([^_])([A-Z][a-z])/'], '$1_$2', $key)
            );
        }
        return self::$snakeCaseMap[$key];
    }

    /**
     * Convert string from snake_case to camelCase
     *
     * @param string $key
     * @return string
     */
    public static function toCamelCase(string $key)
    {
        if (!isset(self::$camelCaseMap[$key])) {
            self::$camelCaseMap[$key] = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
        }
        return self::$camelCaseMap[$key];
    }

    private static function hasBinaryHeaderSuffix(string $key)
    {
        return substr_compare($key, '-bin', strlen($key) - 4) === 0;
    }

    private static function getPhpArraySerializer()
    {
        if (is_null(self::$phpArraySerializer)) {
            self::$phpArraySerializer = new Serializer();
        }
        return self::$phpArraySerializer;
    }

    public static function loadKnownMetadataTypes()
    {
        foreach (KnownTypes::allKnownTypes() as $key => $class) {
            new $class();
        }
    }
}

Serializer::loadKnownMetadataTypes();

