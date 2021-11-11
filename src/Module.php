<?php

declare(strict_types=1);

namespace setasign\SetaPDF\Signer\Module\SwisscomAIS;

class Module extends AbstractModule implements \SetaPDF_Signer_Signature_Module_ModuleInterface
{
    /**
     * @param \SetaPDF_Core_Reader_FilePath $tmpPath
     * @return string
     * @throws Exception
     * @throws SignException
     */
    public function createSignature(\SetaPDF_Core_Reader_FilePath $tmpPath): string
    {
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
        if ($result !== 'urn:oasis:names:tc:dss:1.0:resultmajor:Success') {
            throw new SignException($requestData, $responseData);
        }

        $signatureObject = $responseData['SignResponse']['SignatureObject'];
        $signatureResponse = $signatureObject['Base64Signature']['$'];

        $signatureValue = base64_decode($signatureResponse);
        return (string) $signatureValue;
    }
}
