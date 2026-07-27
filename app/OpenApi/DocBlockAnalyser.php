<?php

namespace App\OpenApi;

use OpenApi\Analysis;
use OpenApi\Analysers\AnalyserInterface;
use OpenApi\Analysers\AttributeAnnotationFactory;
use OpenApi\Analysers\DocBlockAnnotationFactory;
use OpenApi\Analysers\ReflectionAnalyser;
use OpenApi\Context;
use OpenApi\Generator;

final class DocBlockAnalyser implements AnalyserInterface
{
    private ?Generator $generator = null;

    public function setGenerator(Generator $generator): static
    {
        $this->generator = $generator;

        return $this;
    }

    public function fromFile(string $filename, Context $context): Analysis
    {
        $analyser = new ReflectionAnalyser([
            new AttributeAnnotationFactory(),
            new DocBlockAnnotationFactory(),
        ]);

        if ($this->generator !== null) {
            $analyser->setGenerator($this->generator);
        }

        return $analyser->fromFile($filename, $context);
    }

    /**
     * Rebuild the stateless adapter when Laravel loads cached configuration.
     *
     * @param array<string, mixed> $properties
     */
    public static function __set_state(array $properties): self
    {
        return new self();
    }
}
