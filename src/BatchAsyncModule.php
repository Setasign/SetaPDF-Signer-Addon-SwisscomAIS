<?php

declare(strict_types=1);

namespace setasign\SetaPDF\Signer\Module\SwisscomAIS;

class BatchAsyncModule extends AbstractAsyncModule
{
    /**
     * @var array
     */
    protected $documentsData;

    /**
     * @var bool
     */
    protected $updateDss;

    /**
     * The byte length of the reserved space for the signature content
     *
     * @var int
     */
    protected $signatureConentLength = 36000;

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
     * @param array{pendingResponseId: string, pendingRequestId: string, updateDss: bool, documentsData: array{in: SetaPDF_Core_Reader_ReaderInterface, out: SetaPDF_Core_Writer_FileInterface, tmpDocument: SetaPDF_Signer_TmpDocument}} $processData
     */
    public function setProcessData(array $processData): void
    {
        if (
            !\array_key_exists('documentsData', $processData)
            || !\array_key_exists('updateDss', $processData)
            || !\array_key_exists('signatureFieldName', $processData)
        ) {
            throw new \InvalidArgumentException('Invalid process data.');
        }

        foreach ($processData['documentsData'] as $documentData) {
            if (
                !\array_key_exists('in', $documentData)
                || !\array_key_exists('out', $documentData)
                || !\array_key_exists('tmpDocument', $documentData)
                || !$documentData['in'] instanceof \SetaPDF_Core_Reader_ReaderInterface
                || !$documentData['out'] instanceof \SetaPDF_Core_Writer_WriterInterface
                || !$documentData['tmpDocument'] instanceof \SetaPDF_Signer_TmpDocument
            ) {
                throw new \InvalidArgumentException('Invalid process data > documentsData.');
            }
        }


        parent::setProcessData($processData);
        $this->documentsData = $processData['documentsData'];
        $this->updateDss = $processData['updateDss'];
    }

    /**
     * Signs a collection of document instances.
     *
     * The document instances need to have writer instances setup properbly.
     *
     * @param array{in:string|\SetaPDF_Core_Reader_ReaderInterface, out: string|\SetaPDF_Core_Writer_WriterInterface, tmp: string|\SetaPDF_Core_Writer_FileInterface}[] $documents
     * @param bool $updateDss Defines if the revoke information should be added to the DSS afterwards.
     * @param array $signatureProperties
     * @return array{pendingResponseId: string, pendingRequestId: string, updateDss: bool, documentsData: array{in: SetaPDF_Core_Reader_ReaderInterface, out: SetaPDF_Core_Writer_WriterInterface, tmpDocument: SetaPDF_Signer_TmpDocument}}
     * @throws Exception
     * @throws \SetaPDF_Core_Exception
     * @throws \SetaPDF_Signer_Exception
     * @throws \SetaPDF_Signer_Exception_ContentLength
     */
    public function initSignature(array $documents, bool $updateDss = false, array $signatureProperties = []): array
    {
        if ($this->pendingResponseId !== null) {
            throw new \BadMethodCallException(
                'Cannot use AsyncModule::initSignature() after setting process data.
                 Use AsyncModule::processPendingSignature() instead.'
            );
        }

        $digestMethod = $this->getDigestMethod();

        $data = [];
        $files = [];

        $no = 0;
        foreach ($documents as $documentData) {
            if (!$documentData['in'] instanceof \SetaPDF_Core_Reader_ReaderInterface) {
                $documentData['in'] = new \SetaPDF_Core_Reader_File($documentData['in']);
            }

            if (!$documentData['out'] instanceof \SetaPDF_Core_Writer_WriterInterface) {
                $documentData['out'] = new \SetaPDF_Core_Writer_File($documentData['out']);
            }

            if (!$documentData['tmp'] instanceof \SetaPDF_Core_Writer_FileInterface) {
                $documentData['tmp'] = new \SetaPDF_Core_Writer_File($documentData['tmp']);
            }

            $document = \SetaPDF_Core_Document::load($documentData['in'], $documentData['out']);
            $signer = new \SetaPDF_Signer($document);
            $signer->setSignatureContentLength($this->getSignatureContentLength());

            foreach ($signatureProperties as $name => $value) {
                $signer->setSignatureProperty($name, $value);
            }

            // prepare the PDF
            $tmpDocument = $signer->preSign($documentData['tmp'], $this);

            $data[$no] = [
                'in' => $documentData['in'],
                'out' => $documentData['out'],
                'tmpDocument' => $tmpDocument,
            ];

            $files[] = [
                'algorithm' => $digestMethod,
                'digest' => \base64_encode($this->generateHash($tmpDocument->getHashFile()))
            ];

            $no++;
        }

        $requestId = uniqid();
        $requestData = $this->buildSignRequestData(
            $requestId,
            $files
        );

        $responseData = $this->callUrl('https://ais.swisscom.com/AIS-Server/rs/v1.0/sign', $requestData);
        $this->lastResponseData = $responseData;
        if ($responseData['SignResponse']['@RequestID'] !== $requestId) {
            throw new Exception(\sprintf(
                'Invalid request id from response! Expected "%s" but got "%s"',
                $requestId,
                $responseData['SignResponse']['@RequestID']
            ));
        }

        $result = $responseData['SignResponse']['Result']['ResultMajor'];
        if ($result === 'urn:oasis:names:tc:dss:1.0:profiles:asynchronousprocessing:resultmajor:Pending') {
            $this->pendingResponseId = $responseData['SignResponse']['OptionalOutputs']['async.ResponseID'];
            return [
                'pendingRequestId' => $requestId,
                'pendingResponseId' => $this->pendingResponseId,
                'documentsData' => $data,
                'updateDss' => $updateDss,
            ];
        }

        throw new SignException($requestData, $responseData);
    }

    /**
     * @return bool False if the signature process is still pending.
     * @throws Exception
     * @throws SignException
     * @throws \SetaPDF_Core_Exception
     * @throws \SetaPDF_Signer_Asn1_Exception
     * @throws \SetaPDF_Signer_Exception
     * @throws \SetaPDF_Signer_Exception_ContentLength
     */
    public function processPendingSignature(): bool
    {
        if ($this->pendingResponseId === null) {
            throw new \BadMethodCallException(
                'Cannot use AsyncModule::processPendingSignature() without setting process data.'
            );
        }

        $requestData = [
            'async.PendingRequest' => [
                '@Profile' => 'http://ais.swisscom.ch/1.1',
                'OptionalInputs' => [
                    'ClaimedIdentity' => [
                        'Name' => $this->identity
                    ],
                    'async.ResponseID' => $this->pendingResponseId
                ]
            ]
        ];

        $responseData = $this->callUrl('https://ais.swisscom.com/AIS-Server/rs/v1.0/pending', $requestData);
        if ($responseData['SignResponse']['@RequestID'] !== $this->currentRequestId) {
            throw new Exception(\sprintf(
                'Invalid request id from response! Expected "%s" but got "%s"',
                $this->currentRequestId,
                $responseData['SignResponse']['@RequestID']
            ));
        }

        $result = $responseData['SignResponse']['Result']['ResultMajor'];
        if ($result === 'urn:oasis:names:tc:dss:1.0:profiles:asynchronousprocessing:resultmajor:Pending') {
            return false;
        }

        if ($result !== 'urn:oasis:names:tc:dss:1.0:resultmajor:Success') {
            throw new SignException($requestData, $responseData);
        }

        if (count($this->documentsData) > 1) {
            $signatureObjects = $responseData['SignResponse']['SignatureObject']['Other']['sc.SignatureObjects']['sc.ExtendedSignatureObject'];
            $multipleSignatures = true;
        } else {
            $signatureObjects = [$responseData['SignResponse']['SignatureObject']];
            $multipleSignatures = false;
        }

        foreach ($signatureObjects as $signatureObject) {
            if ($multipleSignatures) {
                if (!\preg_match('~^' . \preg_quote($this->currentRequestId, '~') . '-(?P<no>\d+)' . '~', $signatureObject['@WhichDocument'], $matches)) {
                    throw new Exception(\sprintf('Unknown document id "%s"', $signatureObject['@WhichDocument']));
                }
                $no = $matches['no'];
            } else {
                $no = 0;
            }
            $documentData = $this->documentsData[$no];

            $signatureResponse = $signatureObject['Base64Signature']['$'];
            $signatureValue = base64_decode($signatureResponse);

            $document = \SetaPDF_Core_Document::load($documentData['in'], $documentData['out']);
            $signer = new \SetaPDF_Signer($document);

            if (!$this->updateDss) {
                $signer->saveSignature($documentData['tmpDocument'], $signatureValue);
            } else {
                $tempWriter = new \SetaPDF_Core_Writer_TempFile();
                $writer = $document->getWriter();
                $document->setWriter($tempWriter);
                $signer->saveSignature($documentData['tmpDocument'], $signatureValue);

                $document = \SetaPDF_Core_Document::loadByFilename($tempWriter->getPath(), $writer);
                if ($this->addTimestamp) {
                    $this->updateDss($document, $signer->getSignatureField()->getQualifiedName());
                }

                $document->save()->finish();
            }

            // clean up temporary file
            unlink($documentData['tmpDocument']->getWriter()->getPath());
        }
        return true;
    }

    public function cleanupTemporaryFiles()
    {
        if ($this->pendingResponseId === null) {
            throw new \BadMethodCallException(
                'Cannot use AsyncModule::cleanupTemporaryFiles() without setting process data.'
            );
        }

        foreach ($this->documentsData as $documentData) {
            unlink($documentData['tmpDocument']->getWriter()->getPath());
        }
    }
}
