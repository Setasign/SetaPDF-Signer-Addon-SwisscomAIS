<?php
/**
 * This file is part of the demo pacakge of the SetaPDF-Signer Component
 *
 * @copyright  Copyright (c) 2015 Setasign - Jan Slabon (http://www.setasign.com)
 * @category   SetaPDF
 * @package    SetaPDF_Signer
 * @license    http://www.apache.org/licenses/LICENSE-2.0
 */

/**
 * Signaure module class for the Swisscom All-in Signing Service.
 *
 * @link https://www.swisscom.ch/en/business/enterprise/offer/security/identity-access-security/signing-service.html
 * @copyright  Copyright (c) 2015 Setasign - Jan Slabon (http://www.setasign.com)
 * @category   SetaPDF
 * @package    SetaPDF_Signer
 * @license    http://www.apache.org/licenses/LICENSE-2.0
 */
class SetaPDF_Signer_SwisscomAIS_Module extends SetaPDF_Signer_SwisscomAIS_AbstractModule implements
    SetaPDF_Signer_Signature_Module_ModuleInterface,
    SetaPDF_Signer_Timestamp_Module_ModuleInterface,
    SetaPDF_Signer_Signature_DictionaryInterface,
    SetaPDF_Signer_Signature_DocumentInterface
{
    /**
     * The last signature/timestamp result.
     *
     * @var string
     */
    protected $_signature;

    /**
     * Updates the signature dictionary.
     *
     * PAdES requires special Filter and SubFilter entries in the signature dictionary.
     *
     * @param SetaPDF_Core_Type_Dictionary $dictionary
     * @throws SetaPDF_Signer_Exception
     */
    public function updateSignatureDictionary(SetaPDF_Core_Type_Dictionary $dictionary)
    {
        // break if the instance is used as a time stamp module
        if ($dictionary->getValue('Type')->getValue() == 'DocTimeStamp') {
            return;
        }

        /* do some checks:
         * - entry with the key M in the Signature Dictionary
         */
        if (!$dictionary->offsetExists('M')) {
            throw new SetaPDF_Signer_Exception(
                'The key M (the time of signing) shall be present in the signature dictionary to conform with PAdES.'
            );
        }

        $dictionary['SubFilter'] = new SetaPDF_Core_Type_Name('ETSI.CAdES.detached', true);
        $dictionary['Filter'] = new SetaPDF_Core_Type_Name('Adobe.PPKLite', true);
    }

    /**
     * Updates the document instance.
     *
     * @param SetaPDF_Core_Document $document
     * @see ETSI TS 102 778-3 V1.2.1 - 4.7 Extensions Dictionary
     * @see ETSI EN 319 142-1 V1.1.0 - 5.6 Extension dictionary
     */
    public function updateDocument(SetaPDF_Core_Document $document)
    {
        $extensions = $document->getCatalog()->getExtensions();
        $extensions->setExtension('ESIC', '1.7', 2);
    }

    /**
     * Implementation of the createSignautre() method.
     *
     * @param SetaPDF_Core_Reader_FilePath $tmpPath
     * @return mixed
     * @throws SetaPDF_Signer_Exception
     */
    public function createSignature(SetaPDF_Core_Reader_FilePath $tmpPath)
    {
        if (!file_exists($tmpPath) || !is_readable($tmpPath)) {
            throw new InvalidArgumentException('Signature template file cannot be read.');
        }

        $digestMethod = $this->_getDigestMethod();
        $digestValue = $this->_getHash($tmpPath);
        
        $req = array(
            'SignRequest' => array(
                'RequestID' => $this->_createRequestId(),
                'Profile' => 'http://ais.swisscom.ch/1.0',
                'OptionalInputs' => array(
                    'SignatureType' => 'urn:ietf:rfc:3369',
                    'ClaimedIdentity' => array(
                        'Name' => $this->_customerId
                    ),
                ),
                'InputDocuments' => array(
                    'DocumentHash' => array(
                        'DigestMethod' => array('Algorithm' => $digestMethod),
                        'DigestValue' => $digestValue
                    )
                )
            )
        );

        if (true === $this->_addTimestamp) {
            $req['SignRequest']['OptionalInputs']['AddTimestamp'] = array('Type' => 'urn:ietf:rfc:3161');
        }

        if ($this->_revokeInformation) {
            $req['SignRequest']['OptionalInputs']['AddRevocationInformation'] = array('Type' => $this->_revokeInformation);
        }

        $this->_addOnDemandParameter($req);

        $client = new SoapClient($this->_wsdl, array_merge(
            $this->_clientOptions,
            array('trace' => true, 'encoding' => 'UTF-8', 'soap_version' => SOAP_1_1)
        ));

        $this->_lastResult = $client->sign($req);

        $signResult = $this->_lastResult->SignResponse->Result;
        if ($signResult->ResultMajor !== 'urn:oasis:names:tc:dss:1.0:resultmajor:Success') {
            $exception = new SetaPDF_Signer_SwisscomAIS_Exception($signResult->ResultMessage->_);
            $exception->setRequest($req);
            $exception->setResult($this->_lastResult);

            throw $exception;
        }

        $this->_signature = $this->_lastResult->SignResponse->SignatureObject->Base64Signature->_;
        return $this->_signature;
    }

    /**
     * Create the timestamp signature.
     *
     * @param string|SetaPDF_Core_Reader_FilePath $data
     * @return SetaPDF_Signer_Asn1_Element
     * @throws SetaPDF_Signer_Exception
     */
    public function createTimestamp($data)
    {
        $digestMethod = $this->_getDigestMethod();
        $digestValue = $this->_getHash($data);

        $req = array(
            'SignRequest' => array(
                'RequestID' => $this->_createRequestId(),
                'Profile' => 'http://ais.swisscom.ch/1.0',
                'OptionalInputs' => array(
                    'SignatureType' => 'urn:ietf:rfc:3161',
                    'AdditionalProfile' => array('urn:oasis:names:tc:dss:1.0:profiles:timestamping'),
                    'ClaimedIdentity' => array(
                        'Name' => $this->_customerId
                    ),
                ),
                'InputDocuments' => array(
                    'DocumentHash' => array(
                        'DigestMethod' => array('Algorithm' => $digestMethod),
                        'DigestValue' => $digestValue
                    )
                )
            )
        );

        if ($this->_revokeInformation) {
            $req['SignRequest']['OptionalInputs']['AddRevocationInformation'] = array('Type' => $this->_revokeInformation);
        }

        $client = new SoapClient($this->_wsdl, array_merge(
            $this->_clientOptions,
            array('trace' => true, 'encoding' => 'UTF-8', 'soap_version' => SOAP_1_1)
        ));

        $this->_lastResult = $client->sign($req);
        
        $signResult = $this->_lastResult->SignResponse->Result;
        if ($signResult->ResultMajor !== 'urn:oasis:names:tc:dss:1.0:resultmajor:Success') {
            $exception = new SetaPDF_Signer_SwisscomAIS_Exception($signResult->ResultMessage->_);
            $exception->setRequest($req);
            $exception->setResult($this->_lastResult);

            throw $exception;
        }

        $this->_signature = $this->_lastResult->SignResponse->SignatureObject->Timestamp->RFC3161TimeStampToken;
        return $this->_signature;
    }

    /**
     * Get the last signature/timestamp.
     *
     * @return string
     */
    public function getSignature()
    {
        return $this->_signature;
    }
}