<?php

declare(strict_types=1);

namespace Survos\SearchBundle\Search;

/** Portable provenance shared by SQL stores and external search projections. */
final readonly class EmbeddingProfile
{
    public function __construct(public string $model, public int $dimensions, public string $revision, public string $textVersion)
    {
        if ('' === trim($model) || $dimensions < 1 || '' === trim($revision) || '' === trim($textVersion)) {
            throw new \InvalidArgumentException('Embedding profiles require a model, dimensions, revision and text version.');
        }
    }

    public function fingerprint(string $text): string
    {
        return VectorSearch::fingerprint(json_encode([$this->model, $this->dimensions, $this->revision, $this->textVersion], JSON_THROW_ON_ERROR), $text);
    }
}
