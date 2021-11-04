<?php

declare(strict_types=1);

namespace setasign\SetaPDF\Signer\Module\SwisscomAIS;

class TimestampModule extends AbstractModule implements \SetaPDF_Signer_Timestamp_Module_ModuleInterface
{
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
        $optionalInputs['SignatureType'] = 'urn:ietf:rfc:3161';

        $optionalInputs['AdditionalProfile'][] = 'urn:oasis:names:tc:dss:1.0:profiles:timestamping';

        return $requestData;
    }

    /**
     * Create the timestamp signature.
     *
     * @param string|\SetaPDF_Core_Reader_FilePath $data
     * @return string
     * @throws \SetaPDF_Signer_Exception
     */
    public function createTimestamp($data): string
    {
        $digest = \base64_encode($this->generateHash($data));
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

        return \base64_decode($responseData['SignResponse']['SignatureObject']['Timestamp']['RFC3161TimeStampToken']);
    }
}
