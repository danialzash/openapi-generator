<?php

namespace Verge\OpenAPIGenerator\Analyzers;

use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;
use Verge\OpenAPIGenerator\Contracts\AnalyzerInterface;

class RuleAnalyzer implements AnalyzerInterface
{
    /**
     * Analyze a validation rule and return OpenAPI schema properties.
     */
    public function analyze(mixed $data): array
    {
        if (is_string($data)) {
            return $this->analyzeStringRule($data);
        }

        if (is_object($data)) {
            return $this->analyzeObjectRule($data);
        }

        if (is_array($data)) {
            return $this->analyzeArrayRules($data);
        }

        return ['type' => 'string'];
    }

    /**
     * Analyze a string rule (e.g., 'required|string|max:255').
     */
    protected function analyzeStringRule(string $rule): array
    {
        $parts = explode(':', $rule, 2);
        $ruleName = strtolower($parts[0]);
        $params = isset($parts[1]) ? explode(',', $parts[1]) : [];

        return $this->getRuleSchema($ruleName, $params);
    }

    /**
     * Analyze an object rule (e.g., Password::class, Enum::class).
     */
    protected function analyzeObjectRule(object $rule): array
    {
        $className = get_class($rule);

        // Handle Laravel Password rule
        if ($rule instanceof Password || Str::endsWith($className, 'Password')) {
            return [
                'type' => 'string',
                'format' => 'password',
                'minLength' => $this->extractPasswordMinLength($rule),
            ];
        }

        // Handle Laravel Enum rule
        if ($rule instanceof Enum || Str::endsWith($className, 'Enum')) {
            return $this->extractEnumSchema($rule);
        }

        // Handle custom rules
        if (method_exists($rule, 'passes')) {
            return [
                'type' => 'string',
                'description' => 'Custom validation rule: ' . $className,
            ];
        }

        return ['type' => 'string'];
    }

    /**
     * Analyze an array of rules.
     */
    protected function analyzeArrayRules(array $rules): array
    {
        $schema = ['type' => 'string'];

        foreach ($rules as $rule) {
            $ruleSchema = $this->analyze($rule);
            $schema = array_merge($schema, $ruleSchema);
        }

        return $schema;
    }

    /**
     * Get schema for a rule name.
     */
    protected function getRuleSchema(string $ruleName, array $params): array
    {
        return match ($ruleName) {
            // Type rules
            'string' => ['type' => 'string'],
            'integer', 'int' => ['type' => 'integer'],
            'numeric' => ['type' => 'number'],
            'boolean', 'bool' => ['type' => 'boolean'],
            'array' => ['type' => 'array', 'items' => ['type' => 'string']],
            'json' => ['type' => 'object'],
            'file', 'image' => ['type' => 'string', 'format' => 'binary'],

            // Format rules
            'email' => ['type' => 'string', 'format' => 'email'],
            'url', 'active_url' => ['type' => 'string', 'format' => 'uri'],
            'uuid' => ['type' => 'string', 'format' => 'uuid'],
            'ip' => ['type' => 'string', 'format' => 'ipv4'],
            'ipv4' => ['type' => 'string', 'format' => 'ipv4'],
            'ipv6' => ['type' => 'string', 'format' => 'ipv6'],
            'date' => ['type' => 'string', 'format' => 'date'],
            'date_format' => $this->getDateFormatSchema($params),
            'password' => ['type' => 'string', 'format' => 'password'],

            // Size constraints
            'min' => $this->getMinSchema($params),
            'max' => $this->getMaxSchema($params),
            'between' => $this->getBetweenSchema($params),
            'size' => $this->getSizeSchema($params),
            'digits' => $this->getDigitsSchema($params),
            'digits_between' => $this->getDigitsBetweenSchema($params),

            // Enum
            'in' => ['enum' => $params],
            'not_in' => [],

            // Pattern
            'regex' => $this->getRegexSchema($params),
            'alpha' => ['pattern' => '^[a-zA-Z]+$'],
            'alpha_num' => ['pattern' => '^[a-zA-Z0-9]+$'],
            'alpha_dash' => ['pattern' => '^[a-zA-Z0-9_-]+$'],

            // Modifiers
            'nullable' => ['nullable' => true],
            'required' => [],
            'sometimes' => [],

            default => [],
        };
    }

    /**
     * Get date format schema.
     */
    protected function getDateFormatSchema(array $params): array
    {
        $format = $params[0] ?? 'Y-m-d';

        return match ($format) {
            'Y-m-d' => ['type' => 'string', 'format' => 'date'],
            'Y-m-d H:i:s', 'c', 'Y-m-d\TH:i:s\Z' => ['type' => 'string', 'format' => 'date-time'],
            'H:i:s', 'H:i' => ['type' => 'string', 'format' => 'time'],
            default => ['type' => 'string', 'example' => $format],
        };
    }

    /**
     * Get min constraint schema.
     */
    protected function getMinSchema(array $params): array
    {
        if (empty($params)) {
            return [];
        }

        $value = (int) $params[0];

        return [
            'minimum' => $value,
            'minLength' => $value,
        ];
    }

    /**
     * Get max constraint schema.
     */
    protected function getMaxSchema(array $params): array
    {
        if (empty($params)) {
            return [];
        }

        $value = (int) $params[0];

        return [
            'maximum' => $value,
            'maxLength' => $value,
        ];
    }

    /**
     * Get between constraint schema.
     */
    protected function getBetweenSchema(array $params): array
    {
        if (count($params) < 2) {
            return [];
        }

        $min = (int) $params[0];
        $max = (int) $params[1];

        return [
            'minimum' => $min,
            'maximum' => $max,
            'minLength' => $min,
            'maxLength' => $max,
        ];
    }

    /**
     * Get size constraint schema.
     */
    protected function getSizeSchema(array $params): array
    {
        if (empty($params)) {
            return [];
        }

        $value = (int) $params[0];

        return [
            'minLength' => $value,
            'maxLength' => $value,
        ];
    }

    /**
     * Get digits schema.
     */
    protected function getDigitsSchema(array $params): array
    {
        if (empty($params)) {
            return [];
        }

        $length = (int) $params[0];

        return [
            'type' => 'string',
            'pattern' => '^\\d{' . $length . '}$',
        ];
    }

    /**
     * Get digits between schema.
     */
    protected function getDigitsBetweenSchema(array $params): array
    {
        if (count($params) < 2) {
            return [];
        }

        $min = (int) $params[0];
        $max = (int) $params[1];

        return [
            'type' => 'string',
            'pattern' => '^\\d{' . $min . ',' . $max . '}$',
        ];
    }

    /**
     * Get regex schema.
     */
    protected function getRegexSchema(array $params): array
    {
        if (empty($params)) {
            return [];
        }

        $pattern = $params[0];
        // Remove PHP regex delimiters
        $pattern = preg_replace('/^\/(.*)\/[a-z]*$/', '$1', $pattern);

        return ['pattern' => $pattern];
    }

    /**
     * Extract minimum length from Password rule.
     */
    protected function extractPasswordMinLength(object $rule): int
    {
        // Try to access the min property
        try {
            $reflection = new \ReflectionClass($rule);
            if ($reflection->hasProperty('min')) {
                $prop = $reflection->getProperty('min');
                $prop->setAccessible(true);
                return $prop->getValue($rule) ?? 8;
            }
        } catch (\Throwable $e) {
            // Ignore
        }

        return 8; // Default Laravel password min length
    }

    /**
     * Extract enum schema from Enum rule.
     */
    protected function extractEnumSchema(object $rule): array
    {
        try {
            $reflection = new \ReflectionClass($rule);
            
            if ($reflection->hasProperty('type')) {
                $prop = $reflection->getProperty('type');
                $prop->setAccessible(true);
                $enumClass = $prop->getValue($rule);

                if (enum_exists($enumClass)) {
                    $enumReflection = new \ReflectionEnum($enumClass);
                    $cases = $enumReflection->getCases();
                    $values = [];

                    foreach ($cases as $case) {
                        if ($enumReflection->isBacked()) {
                            $values[] = $case->getBackingValue();
                        } else {
                            $values[] = $case->getName();
                        }
                    }

                    $backingType = 'string';
                    if ($enumReflection->isBacked()) {
                        $backingType = $enumReflection->getBackingType()?->getName() ?? 'string';
                    }

                    return [
                        'type' => $backingType === 'int' ? 'integer' : 'string',
                        'enum' => $values,
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Ignore
        }

        return ['type' => 'string'];
    }
}
