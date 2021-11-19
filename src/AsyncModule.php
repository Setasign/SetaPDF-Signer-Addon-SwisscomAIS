<?php

declare(strict_types=1);

namespace setasign\SetaPDF\Signer\Module\SwisscomAIS;

class AsyncModule extends AbstractAsyncModule
{
    /**
     * @param AbstractProcessData $processData
     */
    public function setProcessData(AbstractProcessData $processData): void
    {
        $this->pendingResponseId = $processData->getPendingResponseId();
        $this->currentRequestId = $processData->getPendingRequestId();
    }

    /**
     * @param \SetaPDF_Signer_TmpDocument $tmpDocument
     * @param string $fieldName
     * @return ProcessData
     * @throws Exception
     * @throws SignException
     */
    public function initSignature(\SetaPDF_Signer_TmpDocument $tmpDocument, string $fieldName): ProcessData
    {
        $tmpPath = $tmpDocument->getHashFile();
        if ($this->pendingResponseId !== null) {
            throw new \BadMethodCallException(
                'Cannot use AsyncModule::initSignature() after setting process data.
                 Use AsyncModule::processPendingSignature() instead.'
            );
        }

        $digest = base64_encode($this->generateHash($tmpPath));
        $requestId = \uniqid();
        $requestData = $this->buildSignRequestData(
            $requestId,
            [['algorithm' => $this->getDigestMethod(), 'digest' => $digest]]
        );
        $responseData = $this->callUrl('https://ais.swisscom.com/AIS-Server/rs/v1.0/sign', $requestData);
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
            return new ProcessData($requestId, $this->pendingResponseId, $tmpDocument, $fieldName);
        }

        throw new SignException($requestData, $responseData);
    }

    /**
     * @return false|string False if the signature process is still pending. Otherwise, the signature will be returned.
     * @throws Exception
     * @throws SignException
     */
    public function processPendingSignature()
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

        if (!isset($responseData['SignResponse']['@RequestID'])) {
            // TODO: More details! If e.g. the passed ResponseId is unknown $responseData['Response'] is returned
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

        $signatureObject = $responseData['SignResponse']['SignatureObject'];
        $signatureResponse = $signatureObject['Base64Signature']['$'];

        $signatureValue = base64_decode($signatureResponse);
        if ($signatureValue === false) {
            throw new \RuntimeException('Invalid base64 encoded signature');
        }
        $this->currentRequestId = null;
        return $signatureValue;
    }
}
