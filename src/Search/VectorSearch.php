<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Search;

use Survos\SearchBundle\Contract\QueryEmbedderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Common cosine-search configuration and vector validation, independent of storage. */
final class VectorSearch
{
    public static function configure(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['vectorColumn' => '', 'vectorDimensions' => 0, 'minimumSimilarity' => 0.3, 'queryEmbedder' => null, 'vectorCoverage' => null, 'vectorProfile' => '']);
        $resolver->setAllowedTypes('vectorColumn', 'string');
        $resolver->setAllowedTypes('vectorDimensions', 'int');
        $resolver->setAllowedValues('vectorDimensions', static fn (int $v): bool => $v >= 0);
        $resolver->setAllowedTypes('minimumSimilarity', ['int', 'float']);
        $resolver->setAllowedValues('minimumSimilarity', static fn ($v): bool => is_finite($v) && $v >= -1 && $v <= 1);
        $resolver->setAllowedTypes('queryEmbedder', ['null', QueryEmbedderInterface::class]);
        $resolver->setAllowedTypes('vectorCoverage', ['null', 'array']);
        $resolver->setAllowedTypes('vectorProfile', 'string');
    }

    public static function available(array $parameters): bool
    {
        return !empty($parameters['vectorColumn']) && ($parameters['vectorDimensions'] ?? 0) > 0
            && ($parameters['queryEmbedder'] ?? null) instanceof QueryEmbedderInterface;
    }

    /** @param list<float> $vector */
    public static function validate(array $vector, int $dimensions): void
    {
        if (!array_is_list($vector) || $dimensions < 1 || count($vector) !== $dimensions) {
            throw new \InvalidArgumentException('Query embedding dimensions do not match the dataset.');
        }
        $norm = 0.0;
        foreach ($vector as $value) {
            if ((!is_float($value) && !is_int($value)) || !is_finite($value)) {
                throw new \InvalidArgumentException('Query vector must contain finite numbers.');
            }
            $norm += $value * $value;
        }
        if (!is_finite($norm) || $norm <= 0) {
            throw new \InvalidArgumentException('Query vector must have a finite nonzero norm.');
        }
    }

    /** Fingerprint the exact embedding input and the model/dimensions/preprocessing profile. */
    public static function fingerprint(string $profile, string $text): string
    {
        if ('' === trim($profile) || '' === trim($text)) {
            throw new \InvalidArgumentException('An embedding fingerprint requires a profile and content.');
        }

        return hash('sha256', $profile."\0".$text);
    }
}
