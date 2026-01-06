<?php

namespace Verge\OpenAPIGenerator\Contracts;

interface BuilderInterface
{
    /**
     * Build and return the OpenAPI component.
     *
     * @return array The built OpenAPI component
     */
    public function build(): array;

    /**
     * Reset the builder to its initial state.
     *
     * @return self
     */
    public function reset(): self;
}
