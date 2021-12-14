<?php

declare(strict_types=1);

namespace setasign\SetaPDF\Signer\Module\SwisscomAIS;

class ProcessData extends AbstractProcessData
{
    /**
     * @var \SetaPDF_Signer_TmpDocument
     */
    protected $tmpDocument;

    /**
     * @var string
     */
    protected $fieldName;

    public function __construct(
        $pendingRequestId, $pendingResponseId, \SetaPDF_Signer_TmpDocument $tmpDocument, string $fieldName
    ) {
        parent::__construct($pendingRequestId, $pendingResponseId);
        $this->tmpDocument = $tmpDocument;
        $this->fieldName = $fieldName;
    }

    public function getTmpDocument(): \SetaPDF_Signer_TmpDocument
    {
        return $this->tmpDocument;
    }

    public function getFieldName(): string
    {
        return $this->fieldName;
    }
}
