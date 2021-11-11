<?php

declare(strict_types=1);

namespace setasign\SetaPDF\Signer\Module\SwisscomAIS;

class BatchTimestampModule extends AbstractModule
{
    /**
     * The byte length of the reserved space for the signature content
     *
     * @var int
     */
    protected $signatureConentLength = 20000;

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

    public function updateSignatureDictionary(\SetaPDF_Core_Type_Dictionary $dictionary)
    {
    }

    public function updateDocument(\SetaPDF_Core_Document $document)
    {
    }

    protected function buildSignRequestData(string $requestId, array $documents): array
    {
        $requestData = parent::buildSignRequestData($requestId, $documents);

        $optionalInputs = &$requestData['SignRequest']['OptionalInputs'];
        unset($optionalInputs['sc.SignatureStandard']);
        $optionalInputs['SignatureType'] = 'urn:ietf:rfc:3161';

        $optionalInputs['AdditionalProfile'][] = 'urn:oasis:names:tc:dss:1.0:profiles:timestamping';

        return $requestData;
    }

    /**
     * Timestamps a collection of document instances.
     *
     * The document instances need to have writer instances setup properbly.
     *
     * @param array{in:string|\SetaPDF_Core_Reader_ReaderInterface, out: string|\SetaPDF_Core_Writer_WriterInterface, tmp: string|\SetaPDF_Core_Writer_FileInterface}[] $documents
     * @param bool $updateDss Defines wether the revocation information should be added via DSS or not.
     * @return bool
     * @throws \SetaPDF_Core_Exception
     * @throws \SetaPDF_Signer_Exception
     * @throws \SetaPDF_Signer_Exception_ContentLength
     * @throws Exception
     */
    public function timestamp(array $documents, bool $updateDss = true): bool
    {
        $digestMethod = $this->getDigestMethod();

        $data = [];

        $files = [];

        $no = 0;
        foreach ($documents as $documentData) {
            if (!$documentData['in'] instanceof \SetaPDF_Core_Reader_ReaderInterface) {
                $reader = new \SetaPDF_Core_Reader_File($documentData['in']);
            } else {
                $reader = $documentData['in'];
            }

            if (!$documentData['out'] instanceof \SetaPDF_Core_Writer_WriterInterface) {
                $documentData['out'] = new \SetaPDF_Core_Writer_File($documentData['out']);
            }

            if (!$documentData['tmp'] instanceof \SetaPDF_Core_Writer_FileInterface) {
                $documentData['tmp'] = new \SetaPDF_Core_Writer_File($documentData['tmp']);
            }

            $document = \SetaPDF_Core_Document::load($reader);
            $signer = new \SetaPDF_Signer($document);
            $signer->setSignatureContentLength($this->getSignatureContentLength());
            $tmpDocument = $signer->preTimestamp($documentData['tmp'], $this);

            $data[$no] = [
                'serializedReader' => \serialize($reader),
                'out' => $documentData['out'],
                'tmpDocument' => $tmpDocument,
            ];

            $files[] = [
                'algorithm' => $digestMethod,
                'digest' => \base64_encode($this->generateHash($tmpDocument->getHashFile()))
            ];

            $no++;
        }

        $requestId = \uniqid();
        $requestData = $this->buildSignRequestData($requestId, $files);

        $responseData = $this->callUrl('https://ais.swisscom.com/AIS-Server/rs/v1.0/sign', $requestData);
        if ($responseData['SignResponse']['@RequestID'] !== $requestId) {
            throw new Exception(\sprintf(
                'Invalid request id from response! Expected "%s" but got "%s"',
                $requestId,
                $responseData['SignResponse']['@RequestID']
            ));
        }

        $result = $responseData['SignResponse']['Result']['ResultMajor'];
        if ($result !== 'urn:oasis:names:tc:dss:1.0:resultmajor:Success') {
            throw new SignException($requestData, $responseData);
        }

        if (count($data) > 1) {
            $timestampObjects = $responseData['SignResponse']['SignatureObject']['Other']['sc.SignatureObjects']['sc.ExtendedSignatureObject'];
            $multipleTimestamps = true;
        } else {
            $timestampObjects = [$responseData['SignResponse']['SignatureObject']];
            $multipleTimestamps = false;
        }
        foreach ($timestampObjects as $timestampObject) {
            if ($multipleTimestamps) {
                if (!\preg_match('~^' . \preg_quote($requestId, '~') . '-(?P<no>\d+)' . '~',
                    $timestampObject['@WhichDocument'], $matches)) {
                    throw new Exception(\sprintf('Unknown document id "%s"', $timestampObject['@WhichDocument']));
                }
                $no = $matches['no'];
            } else {
                $no = 0;
            }
            $documentData = $data[$no];

            $timestamp = \base64_decode($timestampObject['Timestamp']['RFC3161TimeStampToken']);

            $reader = \unserialize($documentData['serializedReader'], [
                'allowed_classes' => [
                    \SetaPDF_Core_Reader_String::class,
                    \SetaPDF_Core_Reader_File::class
                ]
            ]);

            $document = \SetaPDF_Core_Document::load($reader);
            $signer = new \SetaPDF_Signer($document);

            if (!$updateDss) {
                $document->setWriter($documentData['out']);
                $signer->saveSignature($documentData['tmpDocument'], $timestamp);
            } else {
                $tempWriter  = new \SetaPDF_Core_Writer_TempFile();
                $document->setWriter($tempWriter);
                $signer->saveSignature($documentData['tmpDocument'], $timestamp);

                $document = \SetaPDF_Core_Document::loadByFilename($tempWriter->getPath(), $documentData['out']);
                $this->updateDss($document, $signer->getSignatureField()->getQualifiedName());
                $document->save()->finish();
            }
        }

        return true;
    }
}
