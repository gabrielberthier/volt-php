<?php

namespace Asyncopath\Data;

class DtoParser
{
    public function parse(string $class)
    {
        $reflection = new \ReflectionClass($class);
        $properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);
        $parsed = [];
        $parsed['props'] = [];

        foreach ($properties as $property) {
            $reflectedTypes = $this->extractReflectionTypes($property);
            foreach ($reflectedTypes as $type) {
                if (method_exists($type, 'isBuiltin') && !$type->isBuiltin()) {
                    if (class_exists((string) $type)) {
                        $reflectedClass = new \ReflectionClass((string) $type);
                        if ($reflectedClass->isUserDefined()) {
                            $parsed['join'] = [$property->getName() => $this->parse((string) $type)];
                        }
                    }
                } else {
                    if ($type->__tostring() === 'array') {
                        $type = $this->getTypeNameFromAnnotation($property);

                        if ($type) {
                            if (class_exists((string) $type)) {
                                $reflectedClass = new \ReflectionClass((string) $type);
                                if ($reflectedClass->isUserDefined()) {
                                    $parsed['join'] = [
                                        $property->getName() => [
                                            ...$this->parse((string) $type),
                                            'meta' => ['is_array' => true]
                                        ]
                                    ];
                                }
                            }
                        } else {
                            $parsed['props'][] = $property->getName();
                        }
                    } else {
                        $parsed['props'][] = $property->getName();
                    }
                }

                // $typedProp = str_replace('?', '', $type->__toString());

                // var_dump($typedProp->isBuiltin());
            }
        }

        return $parsed;
    }



    private function getTypeNameFromAnnotation(\ReflectionProperty $reflectionProperty): ?string
    {
        try {
            $docComment = $reflectionProperty->getDocComment();

            if ($docComment && preg_match('/@var\s+([^\s]+)/', $docComment, $matches)) {
                
                return str_replace(["[", "]"], "", $matches[1]);
            }
        } catch (\ReflectionException $e) {
            echo "Error: " . $e->getMessage() . PHP_EOL;
        }

        return null;
    }

    private function extractReflectionTypes(\ReflectionProperty $prop)
    {
        $reflectionPropType = $prop->getType();
        if ($reflectionPropType instanceof \ReflectionUnionType) {
            return $reflectionPropType->getTypes();
        } elseif ($reflectionPropType instanceof \ReflectionIntersectionType) {
            return [];
        }

        return [$reflectionPropType];
    }
}
