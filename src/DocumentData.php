<?php

declare(strict_types=1);

namespace setasign\SetaPDF\Signer\Module\SwisscomAIS;

class DocumentData
{
    /**
     * @var string
     */
    protected $serializedReader;

    /**
     * @var \SetaPDF_Core_Writer_WriterInterface
     */
    protected $writer;

    /**
     * @var \SetaPDF_Signer_TmpDocument
     */
    protected $tmpDocument;

    /**
     * @var string
     */
    protected $fieldName;

    /**
     * @var array
     */
    protected $metadata = [];

    public function __construct(
        string $serializedReader,
        \SetaPDF_Core_Writer_WriterInterface $writer,
        \SetaPDF_Signer_TmpDocument $tmpDocument,
        string $fieldName
    )
    {
        $this->serializedReader = $serializedReader;
        $this->writer = $writer;
        $this->tmpDocument = $tmpDocument;
        $this->fieldName = $fieldName;
    }

    public function getReader(): \SetaPDF_Core_Reader_ReaderInterface
    {
        return \unserialize($this->serializedReader,  [
            'allowed_classes' => [
                \SetaPDF_Core_Reader_String::class,
                \SetaPDF_Core_Reader_File::class
            ]
        ]);
    }

    public function getWriter(): \SetaPDF_Core_Writer_WriterInterface
    {
        return $this->writer;
    }

    public function getTmpDocument(): \SetaPDF_Signer_TmpDocument
    {
        return $this->tmpDocument;
    }

    public function getFieldName(): string
    {
        return $this->fieldName;
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
