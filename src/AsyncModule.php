<?php

declare(strict_types=1);

namespace setasign\SetaPDF\Signer\Module\SwisscomAIS;

class AsyncModule extends AbstractAsyncModule
{
    /**
     * @param \SetaPDF_Core_Reader_FilePath $tmpPath
     * @return array{pendingResponseId: string, pendingRequestId: string}
     * @throws Exception
     * @throws SignException
     */
    public function initSignature(\SetaPDF_Core_Reader_FilePath $tmpPath): array
    {
        if ($this->pendingResponseId !== null) {
            throw new \BadMethodCallException(
                'Cannot use AsyncModule::initSignature() after setting process data.
                 Use AsyncModule::processPendingSignature() instead.'
            );
        }

        $digest = base64_encode($this->generateHash($tmpPath));
        $requestId = uniqid();
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
            return [
                'pendingRequestId' => $requestId,
                'pendingResponseId' => $this->pendingResponseId
            ];
        }

        throw new SignException($requestData, $responseData);
    }

    /**
     * @return string
     * @throws Exception
     * @throws SignException
     */
    public function processPendingSignature(): string
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
            throw new PendingException();
        }

        if ($result !== 'urn:oasis:names:tc:dss:1.0:resultmajor:Success') {
            throw new SignException($requestData, $responseData);
        }

        $signatureObject = $responseData['SignResponse']['SignatureObject'];
        $signatureResponse = $signatureObject['Base64Signature']['$'];

        $signatureValue = base64_decode($signatureResponse);
        $this->currentRequestId = null;
        return (string) $signatureValue;
    }
}
