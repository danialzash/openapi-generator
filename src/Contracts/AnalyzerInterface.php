<?php

namespace Verge\OpenAPIGenerator\Contracts;

interface AnalyzerInterface
{
    /**
     * Analyze the given data and return structured results.
     *
     * @param mixed $data The data to analyze
     * @return array The analysis results
     */
    public function analyze(mixed $data): array;
}
