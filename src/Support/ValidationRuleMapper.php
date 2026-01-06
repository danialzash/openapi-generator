<?php

namespace Verge\OpenAPIGenerator\Support;

use Illuminate\Support\Str;

class ValidationRuleMapper
{
    /**
     * Map Laravel validation rules to OpenAPI schema properties.
     */
    public function mapRulesToSchema(array $rules): array
    {
        $properties = [];
        $required = [];

        foreach ($rules as $field => $fieldRules) {
            $normalizedRules = $this->normalizeRules($fieldRules);
            $schema = $this->mapFieldRules($field, $normalizedRules);
            
            // Handle nested fields (e.g., 'user.name')
            if (Str::contains($field, '.')) {
                $this->setNestedProperty($properties, $field, $schema);
            } else {
                // Handle array notation (e.g., 'items.*')
                if (Str::contains($field, '*')) {
                    $this->handleArrayField($properties, $field, $schema);
                } else {
                    $properties[$field] = $schema;
                }
            }

            // Check if field is required
            if ($this->isRequired($normalizedRules)) {
                $baseName = Str::before($field, '.');
                $baseName = Str::before($baseName, '*');
                $baseName = rtrim($baseName, '.');
                if (!in_array($baseName, $required) && !Str::contains($field, '.')) {
                    $required[] = $baseName;
                }
            }
        }

        $schema = [
            'type' => 'object',
            'properties' => $properties,
        ];

        if (!empty($required)) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * Normalize rules to an array format.
     */
    protected function normalizeRules(mixed $rules): array
    {
        if (is_string($rules)) {
            return explode('|', $rules);
        }

        if (is_array($rules)) {
            $normalized = [];
            foreach ($rules as $rule) {
                if (is_string($rule)) {
                    $normalized[] = $rule;
                } elseif (is_object($rule)) {
                    $normalized[] = get_class($rule);
                }
            }
            return $normalized;
        }

        return [];
    }

    /**
     * Map field rules to OpenAPI schema.
     */
    protected function mapFieldRules(string $field, array $rules): array
    {
        $schema = [
            'type' => 'string', // Default type
        ];

        foreach ($rules as $rule) {
            $schema = $this->applyRule($schema, $rule);
        }

        return $schema;
    }

    /**
     * Apply a single validation rule to the schema.
     */
    protected function applyRule(array $schema, string $rule): array
    {
        // Parse rule and parameters
        $parts = explode(':', $rule, 2);
        $ruleName = strtolower($parts[0]);
        $params = isset($parts[1]) ? explode(',', $parts[1]) : [];

        return match ($ruleName) {
            // Type rules
            'string' => array_merge($schema, ['type' => 'string']),
            'integer', 'int' => array_merge($schema, ['type' => 'integer']),
            'numeric' => array_merge($schema, ['type' => 'number']),
            'boolean', 'bool' => array_merge($schema, ['type' => 'boolean']),
            'array' => array_merge($schema, ['type' => 'array', 'items' => ['type' => 'string']]),
            'json' => array_merge($schema, ['type' => 'object']),
            'file', 'image' => array_merge($schema, ['type' => 'string', 'format' => 'binary']),

            // Format rules
            'email' => array_merge($schema, ['type' => 'string', 'format' => 'email']),
            'url', 'active_url' => array_merge($schema, ['type' => 'string', 'format' => 'uri']),
            'uuid' => array_merge($schema, ['type' => 'string', 'format' => 'uuid']),
            'ip' => array_merge($schema, ['type' => 'string', 'format' => 'ipv4']),
            'ipv4' => array_merge($schema, ['type' => 'string', 'format' => 'ipv4']),
            'ipv6' => array_merge($schema, ['type' => 'string', 'format' => 'ipv6']),
            'date' => array_merge($schema, ['type' => 'string', 'format' => 'date']),
            'date_format' => $this->applyDateFormat($schema, $params),
            'password' => array_merge($schema, ['type' => 'string', 'format' => 'password']),

            // Constraint rules
            'min' => $this->applyMin($schema, $params),
            'max' => $this->applyMax($schema, $params),
            'between' => $this->applyBetween($schema, $params),
            'size' => $this->applySize($schema, $params),
            'digits' => $this->applyDigits($schema, $params),
            'digits_between' => $this->applyDigitsBetween($schema, $params),

            // Enum rules
            'in' => $this->applyIn($schema, $params),
            'not_in' => $schema, // Can't represent in OpenAPI

            // Pattern rules
            'regex' => $this->applyRegex($schema, $params),
            'alpha' => array_merge($schema, ['pattern' => '^[a-zA-Z]+$']),
            'alpha_num' => array_merge($schema, ['pattern' => '^[a-zA-Z0-9]+$']),
            'alpha_dash' => array_merge($schema, ['pattern' => '^[a-zA-Z0-9_-]+$']),

            // Nullable
            'nullable' => array_merge($schema, ['nullable' => true]),

            // Array items
            'array' => array_merge($schema, ['type' => 'array', 'items' => new \stdClass()]),

            // Default - return unchanged
            default => $schema,
        };
    }

    /**
     * Apply date_format rule.
     */
    protected function applyDateFormat(array $schema, array $params): array
    {
        $format = $params[0] ?? 'Y-m-d';

        return match ($format) {
            'Y-m-d' => array_merge($schema, ['type' => 'string', 'format' => 'date']),
            'Y-m-d H:i:s', 'c' => array_merge($schema, ['type' => 'string', 'format' => 'date-time']),
            default => array_merge($schema, ['type' => 'string', 'example' => $format]),
        };
    }

    /**
     * Apply min rule.
     */
    protected function applyMin(array $schema, array $params): array
    {
        if (empty($params)) {
            return $schema;
        }

        $value = (int) $params[0];

        if (isset($schema['type'])) {
            return match ($schema['type']) {
                'integer', 'number' => array_merge($schema, ['minimum' => $value]),
                'string' => array_merge($schema, ['minLength' => $value]),
                'array' => array_merge($schema, ['minItems' => $value]),
                default => $schema,
            };
        }

        return $schema;
    }

    /**
     * Apply max rule.
     */
    protected function applyMax(array $schema, array $params): array
    {
        if (empty($params)) {
            return $schema;
        }

        $value = (int) $params[0];

        if (isset($schema['type'])) {
            return match ($schema['type']) {
                'integer', 'number' => array_merge($schema, ['maximum' => $value]),
                'string' => array_merge($schema, ['maxLength' => $value]),
                'array' => array_merge($schema, ['maxItems' => $value]),
                default => $schema,
            };
        }

        return $schema;
    }

    /**
     * Apply between rule.
     */
    protected function applyBetween(array $schema, array $params): array
    {
        if (count($params) < 2) {
            return $schema;
        }

        $min = (int) $params[0];
        $max = (int) $params[1];

        if (isset($schema['type'])) {
            return match ($schema['type']) {
                'integer', 'number' => array_merge($schema, ['minimum' => $min, 'maximum' => $max]),
                'string' => array_merge($schema, ['minLength' => $min, 'maxLength' => $max]),
                'array' => array_merge($schema, ['minItems' => $min, 'maxItems' => $max]),
                default => $schema,
            };
        }

        return $schema;
    }

    /**
     * Apply size rule.
     */
    protected function applySize(array $schema, array $params): array
    {
        if (empty($params)) {
            return $schema;
        }

        $value = (int) $params[0];

        if (isset($schema['type'])) {
            return match ($schema['type']) {
                'integer', 'number' => array_merge($schema, ['enum' => [$value]]),
                'string' => array_merge($schema, ['minLength' => $value, 'maxLength' => $value]),
                'array' => array_merge($schema, ['minItems' => $value, 'maxItems' => $value]),
                default => $schema,
            };
        }

        return $schema;
    }

    /**
     * Apply digits rule.
     */
    protected function applyDigits(array $schema, array $params): array
    {
        if (empty($params)) {
            return $schema;
        }

        $length = (int) $params[0];

        return array_merge($schema, [
            'type' => 'string',
            'pattern' => '^\\d{' . $length . '}$',
        ]);
    }

    /**
     * Apply digits_between rule.
     */
    protected function applyDigitsBetween(array $schema, array $params): array
    {
        if (count($params) < 2) {
            return $schema;
        }

        $min = (int) $params[0];
        $max = (int) $params[1];

        return array_merge($schema, [
            'type' => 'string',
            'pattern' => '^\\d{' . $min . ',' . $max . '}$',
        ]);
    }

    /**
     * Apply in rule (enum).
     */
    protected function applyIn(array $schema, array $params): array
    {
        if (empty($params)) {
            return $schema;
        }

        return array_merge($schema, ['enum' => $params]);
    }

    /**
     * Apply regex rule.
     */
    protected function applyRegex(array $schema, array $params): array
    {
        if (empty($params)) {
            return $schema;
        }

        // Remove PHP regex delimiters
        $pattern = $params[0];
        $pattern = preg_replace('/^\/(.*)\/[a-z]*$/', '$1', $pattern);

        return array_merge($schema, ['pattern' => $pattern]);
    }

    /**
     * Check if field is required.
     */
    protected function isRequired(array $rules): bool
    {
        foreach ($rules as $rule) {
            $ruleName = strtolower(explode(':', $rule)[0]);
            
            if ($ruleName === 'required' || $ruleName === 'required_if' || $ruleName === 'required_unless') {
                return true;
            }
            
            if ($ruleName === 'sometimes' || $ruleName === 'nullable') {
                return false;
            }
        }

        return false;
    }

    /**
     * Set a nested property in the schema.
     */
    protected function setNestedProperty(array &$properties, string $path, array $schema): void
    {
        $parts = explode('.', $path);
        $current = &$properties;

        foreach ($parts as $i => $part) {
            // Handle array notation
            if ($part === '*') {
                if (!isset($current['items'])) {
                    $current['items'] = ['type' => 'object', 'properties' => []];
                }
                $current = &$current['items']['properties'];
                continue;
            }

            if ($i === count($parts) - 1) {
                $current[$part] = $schema;
            } else {
                if (!isset($current[$part])) {
                    $current[$part] = ['type' => 'object', 'properties' => []];
                }
                $current = &$current[$part]['properties'];
            }
        }
    }

    /**
     * Handle array field notation.
     */
    protected function handleArrayField(array &$properties, string $field, array $schema): void
    {
        $parts = explode('.*', $field);
        $baseName = $parts[0];

        if (!isset($properties[$baseName])) {
            $properties[$baseName] = [
                'type' => 'array',
                'items' => ['type' => 'object', 'properties' => []],
            ];
        }

        if (count($parts) > 1 && !empty($parts[1])) {
            $nestedPath = ltrim($parts[1], '.');
            if (!empty($nestedPath)) {
                $this->setNestedProperty(
                    $properties[$baseName]['items']['properties'],
                    $nestedPath,
                    $schema
                );
            }
        } elseif (count($parts) === 1 || empty($parts[1])) {
            // Simple array like 'items.*' with type rule
            if (isset($schema['type']) && $schema['type'] !== 'object') {
                $properties[$baseName]['items'] = $schema;
            }
        }
    }
}
