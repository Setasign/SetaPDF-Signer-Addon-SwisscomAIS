<?php

declare(strict_types=1);

namespace setasign\SetaPDF\Signer\Module\SwisscomAIS;

class BatchProcessData extends AbstractProcessData
{
    /**
     * @var DocumentData[]
     */
    protected $documentsData;

    /**
     * @var bool
     */
    protected $updateDss;

    public function __construct($pendingResponseId, $pendingRequestId, bool $updateDss, DocumentData ...$documentsData)
    {
        parent::__construct($pendingResponseId, $pendingRequestId);

        $this->documentsData = $documentsData;
        $this->updateDss = $updateDss;
    }

    /**
     * @return DocumentData[]
     */
    public function getDocumentsData(): array
    {
        return $this->documentsData;
    }

    public function getUpdateDss(): bool
    {
        return $this->updateDss;
    }
}
