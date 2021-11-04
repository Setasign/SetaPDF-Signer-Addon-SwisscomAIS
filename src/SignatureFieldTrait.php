<?php

declare(strict_types=1);

namespace setasign\SetaPDF\Signer\Module\SwisscomAIS;

trait SignatureFieldTrait
{
    /**
     * The byte length of the reserved space for the signature content
     *
     * @var int
     */
    protected $signatureConentLength = 36000;

    /**
     * The signature field name
     *
     * @var string
     */
    protected $fieldName = 'Signature';

    /**
     * Set the signature content length that will be used to reserve space for the final signature.
     *
     * @param int $signatureContentLength The length of the signature content.
     */
    public function setSignatureContentLength(int $signatureContentLength)
    {
        $this->signatureConentLength = $signatureContentLength;
    }

    /**
     * Get the signature content length that will be used to reserve space for the final signature.
     *
     * @return int
     */
    public function getSignatureContentLength(): int
    {
        return $this->signatureConentLength;
    }

    /**
     * Set the signature field name.
     *
     * This can be the name of an existing signature field or an individual name which will be used to create a
     * hidden field automatically.
     *
     * @param string $fieldName The field name in UTF-8 encoding
     */
    public function setSignatureFieldName(string $fieldName)
    {
        $this->fieldName = $fieldName;
    }

    /**
     * Get the signature field name.
     *
     * @return string
     */
    public function getSignatureFieldName(): string
    {
        return $this->fieldName;
    }
}
