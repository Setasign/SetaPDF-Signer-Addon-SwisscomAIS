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
    SetaPDF_Signer_Timestamp_Module_ModuleInterface
{
    /**
     * Implementation of the createSignautre() method.
     *
     * @param SetaPDF_Core_Reader_FilePath|string $tmpPath
     * @return mixed
     * @throws SetaPDF_Signer_Exception
     */
    public function createSignature($tmpPath)
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
            throw new SetaPDF_Signer_Exception(sprintf('Swisscom AIS webservice returned an error: %s',
                $signResult->ResultMessage->_
            ));
        }

        return $this->_lastResult->SignResponse->SignatureObject->Base64Signature->_;
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

            throw new SetaPDF_Signer_Exception(sprintf('Swisscom AIS webservice returned an error: %s',
                $signResult->ResultMessage->_
            ));
        }

        return $this->_lastResult->SignResponse->SignatureObject->Timestamp->RFC3161TimeStampToken;
    }
}