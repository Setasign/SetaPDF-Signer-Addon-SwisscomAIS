<?php

declare(strict_types=1);

namespace setasign\SetaPDF\Signer\Module\SwisscomAIS;

abstract class AbstractProcessData
{
    /**
     * @var string
     */
    protected $pendingRequestId;

    /**
     * @var string
     */
    protected $pendingResponseId;

    /**
     * @var array
     */
    protected $metadata = [];

    public function __construct(string $pendingRequestId, string $pendingResponseId)
    {
        $this->pendingRequestId = $pendingRequestId;
        $this->pendingResponseId = $pendingResponseId;
    }

    public function getPendingResponseId(): string
    {
        return $this->pendingResponseId;
    }

    public function getPendingRequestId(): string
    {
        return $this->pendingRequestId;
    }

    public function setMetadata(array $metadata): void
    {
        $this->metadata = $metadata;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
