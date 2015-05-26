<?php
/**
 * This file is part of the demo pacakge of the SetaPDF-Signer Component
 *
 * @copyright  Copyright (c) 2015 Setasign - Jan Slabon (http://www.setasign.com)
 * @category   SetaPDF
 * @package    SetaPDF_Signer
 * @license    http://www.apache.org/licenses/LICENSE-2.0
 * @version    $Id$
 */

/**
 * Abstract class representing common methods for working with the Swisscom All-in Signing Service.
 *
 * @link https://www.swisscom.ch/en/business/enterprise/offer/security/identity-access-security/signing-service.html
 * @copyright  Copyright (c) 2015 Setasign - Jan Slabon (http://www.setasign.com)
 * @category   SetaPDF
 * @package    SetaPDF_Signer
 * @license    http://www.apache.org/licenses/LICENSE-2.0
 */
abstract class SetaPDF_Signer_SwisscomAIS_AbstractModule extends SetaPDF_Signer_Timestamp_Module_AbstractModule
{
    /**
     * Path to the WSDL file of the AIS SOAP-Webservice
     *
     * @var null|string
     */
    protected $_wsdl;

    /**
     * The customername and key entity.
     *
     * @var string
     */
    protected $_customerId;

    /**
     * Additional client options which will be passed to the SoapClient instance
     *
     * @var array
     */
    protected $_clientOptions;

    /**
     * The last result of a webservice call.
     *
     * @var stdClass
     */
    protected $_lastResult;

    /**
     * Flag identicating if a timestamp should be added to the signature.
     *
     * @var bool
     */
    protected $_addTimestamp = false;

    /**
     * Flag identicating that the response should include revoke information.
     *
     * @var bool
     */
    protected $_revokeInformation = false;

    /**
     * Data for an on-demand signature request.
     *
     * @var array
     */
    protected $_onDemandOptions = array();

    /**
     * @param string $customerId YOUR_CUSTOMER_NAME:YOUR_KEY_ENTITY
     * @param array $clientOptions Additional SoapClient {@link http://php.net/manual/de/soapclient.soapclient.php options}.
     * @param null $wsdl An alternative path to a WSDL file
     */
    public function __construct($customerId, array $clientOptions = array(), $wsdl = null)
    {
        $this->_customerId = $customerId;
        $this->_clientOptions = $clientOptions;
        $this->_wsdl = $wsdl === null ? dirname(__FILE__) . '/aisService.wsdl' : $wsdl;
    }

    /**
     * Set the flag wether the signature should include a timestamp or not.
     *
     * @param boolean $addTimestamp
     */
    public function setAddTimestamp($addTimestamp)
    {
        $this->_addTimestamp = (boolean)$addTimestamp;
    }

    /**
     * Define the level of revoke information.
     *
     * Possible values are: PADES, CADES or BOTH
     *
     * @param string $level
     */
    public function setAddRevokeInformation($level)
    {
        $level = strtoupper($level);
        switch ($level) {
            case 'PADES':
            case 'CADES':
            case 'BOTH':
                $this->_revokeInformation = $level;
                break;
            default:
                $this->_revokeInformation = false;
        }
    }

    /**
     * Seth the on-demand options.
     *
     * @param string $DN
     * @param string $msisdn
     * @param string $msg
     * @param string $lang
     * @param string $serialNumber
     */
    public function setOnDemandOptions($DN, $msisdn = '', $msg = '', $lang = '', $serialNumber = '')
    {
        $this->_onDemandOptions = array(
            'DN' => (string)$DN,
            'msisdn' => (string)$msisdn,
            'msg' => str_replace('#TRANSID#', $this->_generateTransactionId(), $msg),
            'lang' => (string)$lang,
            'serialNumber' => (string)$serialNumber
        );
    }

    /**
     * Get the last result.
     *
     * @return stdClass
     */
    public function getLastResult()
    {
        return $this->_lastResult;
    }

    /**
     * Merges the on-demand options into the request data array.
     *
     * @param array $request
     */
    protected function _addOnDemandParameter(&$request)
    {
        if (isset($this->_onDemandOptions['DN']) && $this->_onDemandOptions['DN'] !== '') {
            $request['SignRequest']['OptionalInputs']
            ['AdditionalProfile'][] = 'http://ais.swisscom.ch/1.0/profiles/ondemandcertificate';
            $request['SignRequest']['OptionalInputs']['CertificateRequest']['DistinguishedName'] = $this->_onDemandOptions['DN'];
        }

        if (isset($this->_onDemandOptions['msisdn']) && $this->_onDemandOptions['msisdn'] !== '') {
            $request['SignRequest']['OptionalInputs']['CertificateRequest']['StepUpAuthorisation']['MobileID'] = array(
                'Type' => 'http://ais.swisscom.ch/1.0/auth/mobileid/1.0',
                'MSISDN' => $this->_onDemandOptions['msisdn'],
                'Message' => $this->_onDemandOptions['msg'],
                'Language' => $this->_onDemandOptions['lang']
            );

            if (isset($this->_onDemandOptions['serialNumber']) && $this->_onDemandOptions['serialNumber'] !== '') {
                $request['SignRequest']['OptionalInputs']['CertificateRequest']
                ['StepUpAuthorisation']['MobileID']['SerialNumber'] = $this->_onDemandOptions['serialNumber'];
            }
        }
    }

    /**
     * Updates the document security store by the last received revoke information.
     *
     * @param SetaPDF_Core_Document $document
     * @param string $fieldName The signature field, that was signed.
     */
    public function updateDss(SetaPDF_Core_Document $document, $fieldName)
    {
        if (!isset($this->_lastResult->SignResponse->OptionalOutputs->RevocationInformation)) {
            throw new BadMethodCallException('No verification data collected.');
        }

        $ocsps = array();
        $certificates = array();
        $crls = array();

        $data = $this->_lastResult->SignResponse->OptionalOutputs->RevocationInformation;

        if (isset($data->CRLs->CRL)) {
            $crls[] = $data->CRLs->CRL;
        }

        if (isset($data->OCSPs->OCSP)) {
            $ocsps[] = $data->OCSPs->OCSP;
        }

        $dss = new SetaPDF_Signer_DocumentSecurityStore($document);
        $dss->addValidationRelatedInfoByField($fieldName, $crls, $ocsps, $certificates);
    }

    /**
     * Get the hash that should be timestamped.
     *
     * @param string|SetaPDF_Core_Reader_FilePath $data The hash of the main signature
     * @return string
     */
    protected function _getHash($data)
    {
        if ($data instanceof SetaPDF_Core_Reader_FilePath) {
            return hash_file($this->getDigest(), $data->getPath(), true);
        } else {
            return hash($this->getDigest(), $data, true);
        }
    }

    /**
     * Helper method to get the digest method uri.
     *
     * @return string
     * @throws SetaPDF_Signer_Exception
     */
    protected function _getDigestMethod()
    {
        switch ($this->getDigest()) {
            case SetaPDF_Signer_Digest::SHA_256:
                return 'http://www.w3.org/2001/04/xmlenc#sha256';
            case SetaPDF_Signer_Digest::SHA_384:
                return 'http://www.w3.org/2001/04/xmldsig-more#sha384';
            case SetaPDF_Signer_Digest::SHA_512:
                return 'http://www.w3.org/2001/04/xmlenc#sha512';
            default:
                throw new SetaPDF_Signer_Exception(sprintf('Unsupported digest: %s', $this->getDigest()));
        }
    }

    /**
     * Generates a random transaction id.
     *
     * @return string Transaction ID with a length of 6
     */
    protected function _generateTransactionId()
    {
        $pattern = '1234567890abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $maxLen = strlen($pattern) - 1;
        $id = '';
        for ($i = 1; $i <= 6; $i++) {
            $id .= $pattern{mt_rand(0, $maxLen)};
        }

        return $id;
    }

    /**
     * Generate a randotm request id.
     *
     * @return string
     */
    protected function _createRequestId()
    {
        return 'AIS.PHP.' . rand(89999, 10000) . '.' . rand(8999, 1000);
    }
}