<?php

namespace Verge\OpenAPIGenerator\Support;

use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use ReflectionNamedType;
use ReflectionUnionType;

class TypeResolver
{
    /**
     * Resolve PHP type to OpenAPI schema.
     */
    public function resolveType(?string $type, bool $nullable = false): array
    {
        if (!$type) {
            return ['type' => 'string'];
        }

        $schema = match (strtolower($type)) {
            'int', 'integer' => ['type' => 'integer'],
            'float', 'double' => ['type' => 'number'],
            'bool', 'boolean' => ['type' => 'boolean'],
            'string' => ['type' => 'string'],
            'array' => ['type' => 'array', 'items' => new \stdClass()],
            'object', 'stdclass' => ['type' => 'object'],
            'mixed' => ['type' => 'object'],
            'null' => ['type' => 'null'],
            default => $this->resolveClassType($type),
        };

        if ($nullable && isset($schema['type'])) {
            $schema['nullable'] = true;
        }

        return $schema;
    }

    /**
     * Resolve a class type to OpenAPI schema.
     */
    protected function resolveClassType(string $className): array
    {
        // Handle common Laravel types
        if ($this->isDateTimeType($className)) {
            return ['type' => 'string', 'format' => 'date-time'];
        }

        if ($this->isCollectionType($className)) {
            return ['type' => 'array', 'items' => ['type' => 'object']];
        }

        // Handle Enum types
        if (enum_exists($className)) {
            return $this->resolveEnumType($className);
        }

        // For other classes, return object reference
        return [
            'type' => 'object',
            '$ref' => '#/components/schemas/' . class_basename($className),
        ];
    }

    /**
     * Check if type is a DateTime type.
     */
    protected function isDateTimeType(string $className): bool
    {
        $dateTypes = [
            'DateTime',
            'DateTimeInterface',
            'DateTimeImmutable',
            'Carbon\Carbon',
            'Carbon\CarbonImmutable',
            'Illuminate\Support\Carbon',
        ];

        return in_array($className, $dateTypes) || 
               is_subclass_of($className, \DateTimeInterface::class);
    }

    /**
     * Check if type is a Collection type.
     */
    protected function isCollectionType(string $className): bool
    {
        $collectionTypes = [
            'Illuminate\Support\Collection',
            'Illuminate\Database\Eloquent\Collection',
        ];

        return in_array($className, $collectionTypes) ||
               (class_exists($className) && is_subclass_of($className, \Illuminate\Support\Collection::class));
    }

    /**
     * Resolve an Enum type to OpenAPI schema.
     */
    protected function resolveEnumType(string $enumClass): array
    {
        if (!enum_exists($enumClass)) {
            return ['type' => 'string'];
        }

        $reflection = new \ReflectionEnum($enumClass);
        $cases = $reflection->getCases();
        $values = [];

        foreach ($cases as $case) {
            if ($reflection->isBacked()) {
                $values[] = $case->getBackingValue();
            } else {
                $values[] = $case->getName();
            }
        }

        $backingType = 'string';
        if ($reflection->isBacked()) {
            $backingType = $reflection->getBackingType()?->getName() ?? 'string';
        }

        return [
            'type' => $backingType === 'int' ? 'integer' : 'string',
            'enum' => $values,
        ];
    }

    /**
     * Extract type from PHPDoc.
     */
    public function extractTypeFromDocblock(?string $docblock, string $tag = '@return'): ?string
    {
        if (!$docblock) {
            return null;
        }

        $pattern = '/' . preg_quote($tag) . '\s+([^\s]+)/';
        
        if (preg_match($pattern, $docblock, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Parse a compound type (union types like string|int).
     */
    public function parseCompoundType(string $type): array
    {
        $types = explode('|', $type);
        $schemas = [];
        $nullable = false;

        foreach ($types as $t) {
            $t = trim($t);
            
            if (strtolower($t) === 'null') {
                $nullable = true;
                continue;
            }

            $schemas[] = $this->resolveType($t);
        }

        if (count($schemas) === 1) {
            $schema = $schemas[0];
            if ($nullable) {
                $schema['nullable'] = true;
            }
            return $schema;
        }

        return ['oneOf' => $schemas];
    }

    /**
     * Resolve array item type from docblock.
     */
    public function resolveArrayItemType(?string $docType): array
    {
        if (!$docType) {
            return ['type' => 'object'];
        }

        // Handle array<Type> or Type[]
        if (preg_match('/^array<(.+)>$/i', $docType, $matches)) {
            return $this->resolveType($matches[1]);
        }

        if (preg_match('/^(.+)\[\]$/', $docType, $matches)) {
            return $this->resolveType($matches[1]);
        }

        return ['type' => 'object'];
    }

    /**
     * Get properties from a class using reflection.
     */
    public function getClassProperties(string $className): array
    {
        if (!class_exists($className)) {
            return [];
        }

        $reflection = new ReflectionClass($className);
        $properties = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $type = $property->getType();
            $nullable = $type ? $type->allowsNull() : true;

            $typeName = null;
            if ($type instanceof ReflectionNamedType) {
                $typeName = $type->getName();
            }

            $properties[$property->getName()] = [
                'schema' => $this->resolveType($typeName, $nullable),
                'docblock' => $property->getDocComment() ?: null,
            ];
        }

        return $properties;
    }
}
