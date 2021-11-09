<?php

declare(strict_types=1);

namespace setasign\SetaPDF\Signer\Module\SwisscomAIS;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

abstract class AbstractModule implements \SetaPDF_Signer_Signature_DocumentInterface, \SetaPDF_Signer_Signature_DictionaryInterface
{
    /**
     * @var ClientInterface PSR-18 HTTP Client implementation.
     */
    protected $httpClient;

    /**
     * @var RequestFactoryInterface PSR-17 HTTP Factory implementation.
     */
    protected $requestFactory;

    /**
     * @var StreamFactoryInterface PSR-17 HTTP Factory implementation.
     */
    protected $streamFactory;

    /**
     * @var string
     */
    protected $identity;

    /**
     * The message digest
     *
     * @var string
     */
    protected $digest = \SetaPDF_Signer_Digest::SHA_512;

    /**
     * Flag identicating if a timestamp should be added to the signature.
     *
     * @var bool
     */
    protected $addTimestamp = false;

    /**
     * @var array
     */
    protected $lastResponseData = [];

    /**
     * @var bool
     */
    protected $onDemandCertificate = false;

    /**
     * @var null|string
     */
    protected $onDemandCertificateDistinguishedName = null;

    /**
     * @var string
     */
    protected $signatureStandard = 'PAdES-Baseline';

    public function __construct(
        string $identity,
        ClientInterface $httpClient,
        RequestFactoryInterface $requestFactory,
        StreamFactoryInterface $streamFactory
    ) {
        $this->identity = $identity;
        $this->httpClient = $httpClient;
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
    }

    /**
     * Set the digest algorithm to use.
     *
     * @see \SetaPDF_Signer_Digest
     * @param string $digest Possible values are defined in {@link \SetaPDF_Signer_Digest}
     */
    public function setDigest(string $digest)
    {
        if (!\in_array($digest, ['sha256', 'sha384', 'sha512'])) {
            throw new \InvalidArgumentException(\sprintf('Unsupported digest "%s"', $digest));
        }

        $this->digest = $digest;
    }

    /**
     * Get the digest algorithm.
     *
     * @return string
     */
    public function getDigest(): string
    {
        return $this->digest;
    }

    /**
     * Helper method to get the digest method uri.
     *
     * @return string
     */
    protected function getDigestMethod(): string
    {
        switch ($this->getDigest()) {
            case \SetaPDF_Signer_Digest::SHA_256:
                return 'http://www.w3.org/2001/04/xmlenc#sha256';
            case \SetaPDF_Signer_Digest::SHA_384:
                return 'http://www.w3.org/2001/04/xmldsig-more#sha384';
            case \SetaPDF_Signer_Digest::SHA_512:
                return 'http://www.w3.org/2001/04/xmlenc#sha512';
            default:
                // should not be possible
                throw new \RuntimeException(\sprintf('Unsupported digest: %s', $this->getDigest()));
        }
    }

    /**
     * @param string $signatureStandard
     */
    public function setSignatureStandard(string $signatureStandard): void
    {
        if (!\in_array($this->signatureStandard, ['PAdES-Baseline', 'PDF'], true)) {
            throw new \InvalidArgumentException(\sprintf('Unsupported signature standard "%s"', $signatureStandard));
        }

        $this->signatureStandard = $signatureStandard;
    }

    public function getSignatureStandard(): string
    {
        return $this->signatureStandard;
    }

    /**
     * Get the hash that should be timestamped.
     *
     * @param string|\SetaPDF_Core_Reader_FilePath $data The hash of the main signature
     * @return string
     */
    protected function generateHash($data): string
    {
        if ($data instanceof \SetaPDF_Core_Reader_FilePath) {
            return hash_file($this->getDigest(), $data->getPath(), true);
        }

        return hash($this->getDigest(), $data, true);
    }

    /**
     * Set the flag wether the signature should include a timestamp or not.
     *
     * @param bool $addTimestamp
     */
    public function setAddTimestamp(bool $addTimestamp = true): void
    {
        $this->addTimestamp = $addTimestamp;
    }

    /**
     * Set the on-demand.
     *
     * @param null|string $distinguishedName
     */
    public function setOnDemandCertificate(?string $distinguishedName = null)
    {
        $this->onDemandCertificate = true;
        $this->onDemandCertificateDistinguishedName = $distinguishedName;
    }

    /**
     * json_decode wrapper to handle invalid json. Can be removed with php7.3 and JSON_THROW_ON_ERROR
     *
     * @param string $json The json string being decoded. This function only works with UTF-8 encoded strings.
     * @param bool $assoc When TRUE, returned objects will be converted into associative arrays.
     * @param int $depth
     * @param int $options
     * @return mixed
     */
    protected function json_decode(string $json, bool $assoc = false, int $depth = 512, int $options = 0)
    {
        // Clear json_last_error()
        \json_encode(null);

        $data = @\json_decode($json, $assoc, $depth, $options);

        if (\json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException(\sprintf(
                'Unable to decode JSON: %s',
                \json_last_error_msg()
            ));
        }
        return $data;
    }

    /**
     * @param string $requestId
     * @param array{algorithm: string, digest: string}[] $documents
     * @return array[]
     */
    protected function buildSignRequestData(string $requestId, array $documents): array
    {
        $requestData = [
            'SignRequest' => [
                '@Profile' => 'http://ais.swisscom.ch/1.1',
                '@RequestID' => $requestId,
                'OptionalInputs' => [
                    'ClaimedIdentity' => [
                        'Name' => $this->identity
                    ],
                    'SignatureType' => 'urn:ietf:rfc:3369',
                    'sc.SignatureStandard' => $this->signatureStandard,
                    'sc.AddRevocationInformation' => ['@Type' => $this->signatureStandard]
                ],
                'InputDocuments' => [
                    'DocumentHash' => \array_map(function ($document, $no) use ($requestId) {
                        return [
                            '@ID' => $requestId . '-' . $no,
                            'dsig.DigestMethod' => ['@Algorithm' => $document['algorithm']],
                            'dsig.DigestValue' => $document['digest']
                        ];
                    }, $documents, \array_keys($documents))
                ]
            ]
        ];


        $optionalInputs = &$requestData['SignRequest']['OptionalInputs'];
        if (\count($documents) > 1) {
            $optionalInputs['AdditionalProfile'][] = 'http://ais.swisscom.ch/1.0/profiles/batchprocessing';
        }

        if ($this->addTimestamp) {
            $optionalInputs['AddTimestamp'] = ['@Type' => 'urn:ietf:rfc:3161'];
        }

        if ($this->onDemandCertificate) {
            $optionalInputs['AdditionalProfile'][] = 'http://ais.swisscom.ch/1.0/profiles/ondemandcertificate';
            if ($this->onDemandCertificateDistinguishedName !== null) {
                $optionalInputs['sc.CertificateRequest']['sc.DistinguishedName'] = $this->onDemandCertificateDistinguishedName;
            }
        }

        return $requestData;
    }

    /**
     * Updates the document security store by the last received revoke information.
     *
     * @param \SetaPDF_Core_Document $document
     * @param string $fieldName The signature field, that was signed.
     * @throws \SetaPDF_Signer_Asn1_Exception
     */
    public function updateDss(\SetaPDF_Core_Document $document, string $fieldName)
    {
        if (!isset($this->lastResponseData['SignResponse']['OptionalOutputs']['sc.RevocationInformation'])) {
            throw new \BadMethodCallException('No verification data collected.');
        }

        $ocsps = [];
        $certificates = [];
        $crls = [];

        $data = $this->lastResponseData['SignResponse']['OptionalOutputs']['sc.RevocationInformation'];

        if (isset($data['sc.CRLs']['sc.CRL'])) {
            $crlEntries = $data['sc.CRLs']['sc.CRL'];
            if (!is_array($crlEntries)) {
                $crlEntries = [$crlEntries];
            }

            foreach ($crlEntries as $crlEntry) {
                $crls[] = \base64_decode($crlEntry);
            }
        }

        if (isset($data['sc.OCSPs']['sc.OCSP'])) {
            $ocspEntries = $data['sc.OCSPs']['sc.OCSP'];
            if (!is_array($ocspEntries)) {
                $ocspEntries = [$ocspEntries];
            }
            foreach ($ocspEntries as $ocspEntry) {
                $ocsps[] = \base64_decode($ocspEntry);
            }
        }

        $dss = new \SetaPDF_Signer_DocumentSecurityStore($document);
        $dss->addValidationRelatedInfoByFieldName($fieldName, $crls, $ocsps, $certificates);
    }

    public function getLastResponseData(): array
    {
        return $this->lastResponseData;
    }

    /**
     * @param \SetaPDF_Core_Type_Dictionary $dictionary
     * @throws \SetaPDF_Signer_Exception
     */
    public function updateSignatureDictionary(\SetaPDF_Core_Type_Dictionary $dictionary)
    {
        if ($this->signatureStandard === 'PAdES-Baseline') {
            /* do some checks:
             * - entry with the key M in the Signature Dictionary
             */
            if (!$dictionary->offsetExists('M')) {
                throw new \SetaPDF_Signer_Exception(
                    'The key M (the time of signing) shall be present in the signature dictionary to conform with PAdES.'
                );
            }

            $dictionary['SubFilter'] = new \SetaPDF_Core_Type_Name('ETSI.CAdES.detached', true);
            $dictionary['Filter'] = new \SetaPDF_Core_Type_Name('Adobe.PPKLite', true);
        }
    }

    /**
     * @inheritDoc
     */
    public function updateDocument(\SetaPDF_Core_Document $document)
    {
        if ($this->signatureStandard === 'PAdES-Baseline') {
            $extensions = $document->getCatalog()->getExtensions();
            $extensions->setExtension('ESIC', '1.7', 2);
        }
    }

    /**
     * @param string $url
     * @param array $requestData
     * @return array
     * @throws Exception
     */
    protected function callUrl(string $url, array $requestData): array
    {
        try {
            $response = $this->httpClient->sendRequest(
                $this->requestFactory->createRequest('POST', $url)
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Accept', 'application/json')
                ->withBody($this->streamFactory->createStream(\json_encode($requestData)))
            );
        } catch (ClientExceptionInterface $e) {
            throw new Exception('Connection error!', 0, $e);
        }

        $responseBody = (string) $response->getBody();
        if ($response->getStatusCode() !== 200) {
            throw new Exception(\sprintf(
                'Unexpected response status code (%d). Response: %s',
                $response->getStatusCode(),
                $responseBody
            ));
        }

        $responseData = $this->json_decode($responseBody, true);
        $this->lastResponseData = $responseData;
        return $responseData;
    }
}