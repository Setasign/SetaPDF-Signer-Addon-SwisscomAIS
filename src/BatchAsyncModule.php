<?php

declare(strict_types=1);

namespace setasign\SetaPDF\Signer\Module\SwisscomAIS;

class BatchAsyncModule extends AbstractAsyncModule
{
    /**
     * @var DocumentData[]
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
     * @param BatchProcessData $processData
     */
    public function setProcessData(BatchProcessData $processData): void
    {
        $this->pendingResponseId = $processData->getPendingResponseId();
        $this->currentRequestId = $processData->getPendingRequestId();
        $this->documentsData = $processData->getDocumentsData();
        $this->updateDss = $processData->getUpdateDss();
    }

    /**
     * Inititates an asynchronous signature batch process.
     *
     * The documents have to be passed as an array with the following structure:
     *
     * <code>
     * $documents = [
     *     [
     *         'in' => 'local/path/non-signed.pdf', // or an instance of \SetaPDF_Core_Reader_String|\SetaPDF_Core_Reader_File
     *         'out' => 'local/path/to/resulting.pdf', // or an instance implementing \SetaPDF_Core_Writer_WriterInterface
     *         'tmp' => 'local/path/to/a/temporary.file', // or an instance of \SetaPDF_Core_Writer_FileInterface
     *         'metadata' => [ // you can pass any foreign data related to your own process which can be accessed later
     *             'custom' => 'data',
     *             'document-id' => 12345
     *         ]
     *     ],
     *     // ...
     * ];
     * </code>
     *
     * @param array{in:string|\SetaPDF_Core_Reader_String|\SetaPDF_Core_Reader_File, out: string|\SetaPDF_Core_Writer_WriterInterface, tmp: string|\SetaPDF_Core_Writer_FileInterface, metadata: array}[] $documents
     * @param bool $updateDss Defines wether the revocation information should be added via DSS or not.
     * @param array $signatureProperties
     * @return BatchProcessData
     * @throws Exception
     * @throws \SetaPDF_Core_Exception
     * @throws \SetaPDF_Signer_Exception
     * @throws \SetaPDF_Signer_Exception_ContentLength
     */
    public function initSignature(array $documents, bool $updateDss = true, array $signatureProperties = []): BatchProcessData
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
                $reader = new \SetaPDF_Core_Reader_File($documentData['in']);
            } else {
                $reader = $documentData['in'];
            }

            $serializedReader = \serialize($reader);

            if (!$documentData['out'] instanceof \SetaPDF_Core_Writer_WriterInterface) {
                $documentData['out'] = new \SetaPDF_Core_Writer_File($documentData['out']);
            }

            if (!$documentData['tmp'] instanceof \SetaPDF_Core_Writer_FileInterface) {
                $documentData['tmp'] = new \SetaPDF_Core_Writer_File($documentData['tmp']);
            }

            $document = \SetaPDF_Core_Document::load($reader);
            $signer = new \SetaPDF_Signer($document);
            $signer->setAllowSignatureContentLengthChange(false);
            $signer->setSignatureContentLength($this->getSignatureContentLength());
            $fieldName = $signer->addSignatureField()->getQualifiedName();
            $signer->setSignatureFieldName($fieldName);

            foreach ($signatureProperties as $name => $value) {
                $signer->setSignatureProperty($name, $value);
            }

            // prepare the PDF
            $tmpDocument = $signer->preSign($documentData['tmp'], $this);

            $data[$no] = new DocumentData($serializedReader, $documentData['out'], $tmpDocument, $fieldName);
            if (isset($documentData['metadata'])) {
                $data[$no]->setMetadata($documentData['metadata']);
            }

            $files[] = [
                'algorithm' => $digestMethod,
                'digest' => \base64_encode($this->generateHash($tmpDocument->getHashFile()))
            ];

            $no++;
        }

        $requestId = \uniqid('Seta', true);
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
            return new BatchProcessData($requestId, $this->pendingResponseId, $updateDss, ...$data);
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

        if (isset($responseData['Response'])) {
            throw new Exception(\sprintf(
                'Unexpected response! Result major "%s". Result minor "%s". Result message: %s',
                $responseData['Response']['Result']['ResultMajor'],
                $responseData['Response']['Result']['ResultMinor'],
                $responseData['Response']['Result']['ResultMessage']['$']
            ));
        }

        if (!isset($responseData['SignResponse']['@RequestID'])) {
            throw new Exception('Invalid response!');
        }

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

        if (\count($this->documentsData) > 1) {
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
            $signatureValue = \base64_decode($signatureResponse);

            $document = \SetaPDF_Core_Document::load($documentData->getReader());
            $signer = new \SetaPDF_Signer($document);

            if (!$this->updateDss) {
                $document->setWriter($documentData->getWriter());
                $signer->saveSignature($documentData->getTmpDocument(), $signatureValue);
            } else {
                $tempWriter  = new \SetaPDF_Core_Writer_TempFile();
                $document->setWriter($tempWriter);
                $signer->saveSignature($documentData->getTmpDocument(), $signatureValue);

                $document = \SetaPDF_Core_Document::loadByFilename($tempWriter->getPath(), $documentData->getWriter());
                $this->updateDss($document, $documentData->getFieldName());
                $document->save()->finish();
            }

            // clean up temporary file
            \unlink($documentData->getTmpDocument()->getWriter()->getPath());
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
            @\unlink($documentData->getTmpDocument()->getWriter()->getPath());
        }
    }
}
