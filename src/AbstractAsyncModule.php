<?php

declare(strict_types=1);

namespace setasign\SetaPDF\Signer\Module\SwisscomAIS;

abstract class AbstractAsyncModule extends AbstractModule
{
    /**
     * @var null|string
     */
    protected $pendingResponseId = null;

    /**
     * @var null|string
     */
    protected $currentRequestId = null;

    /**
     * @var array
     */
    protected $stepUpAuthorisationData = null;

    /**
     * @param string $msisdn The Mobile phone number of the user.
     * @param string $message The text displayed to the user.
     * @param string $language The Language of the <Message> content.
     * @param null|string $serialNumber This number is a unique serial number (ID) associated to the user’s phone number
     *                                  by the backend step-up service. If provided in the request, AIS will only
     *                                  compute and return a signature if it equals the one returned by the step-up
     *                                  service (see 5.8.1).
     */
    public function setStepUpAuthorisation(
        string $msisdn,
        string $message,
        string $language,
        ?string $serialNumber = null
    ) {
        if ($msisdn === '') {
            throw new \InvalidArgumentException('Missing msisdn.');
        }
        if ($message === '') {
            throw new \InvalidArgumentException('Missing message.');
        }
        if ($language === '') {
            throw new \InvalidArgumentException('Missing language.');
        }

        $this->stepUpAuthorisationData = [
            'msisdn' => $msisdn,
            'message' => $message,
            'language' => $language,
            'serialNumber' => $serialNumber
        ];
    }

    /**
     * @param string $requestId
     * @param array{algorithm: string, digest: string}[] $documents
     * @return array[]
     */
    protected function buildSignRequestData(string $requestId, array $documents): array
    {
        $requestData = parent::buildSignRequestData($requestId, $documents);
        $optionalInputs = &$requestData['SignRequest']['OptionalInputs'];
        $optionalInputs['AdditionalProfile'][] = 'urn:oasis:names:tc:dss:1.0:profiles:asynchronousprocessing';
        $optionalInputs['AdditionalProfile'][] = 'http://ais.swisscom.ch/1.1/profiles/redirect';

        if ($this->onDemandCertificateDistinguishedName !== null && $this->stepUpAuthorisationData !== null) {
            $optionalInputs['sc.CertificateRequest']['sc.StepUpAuthorisation']['sc.Phone'] = [
                'sc.MSISDN' => $this->stepUpAuthorisationData['msisdn'],
                'sc.Message' => $this->stepUpAuthorisationData['message'],
                'sc.Language' => $this->stepUpAuthorisationData['language'],
                'sc.SerialNumber' => $this->stepUpAuthorisationData['serialNumber']
            ];
        }

        return $requestData;
    }
}
