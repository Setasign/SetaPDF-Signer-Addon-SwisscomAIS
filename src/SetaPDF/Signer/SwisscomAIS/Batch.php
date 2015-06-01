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
 * Class for batch processing through the All-in Signing Service.
 *
 * @link https://www.swisscom.ch/en/business/enterprise/offer/security/identity-access-security/signing-service.html
 * @copyright  Copyright (c) 2015 Setasign - Jan Slabon (http://www.setasign.com)
 * @category   SetaPDF
 * @package    SetaPDF_Signer
 * @license    http://www.apache.org/licenses/LICENSE-2.0
 */
class SetaPDF_Signer_SwisscomAIS_Batch extends SetaPDF_Signer_SwisscomAIS_AbstractModule
{
    /**
     * The byte length of the reserved space for the signature content
     *
     * @var int
     */
    protected $_signatureConentLength = 32000;

    /**
     * The signature field name
     *
     * @var string
     */
    protected $_fieldName = 'Signature';

    /**
     * Set the signature content length that will be used to reserve space for the final signature.
     *
     * @param integer $length The length of the signature content.
     */
    public function setSignatureContentLength($signatureContentLength)
    {
        $this->_signatureConentLength = (int)$signatureContentLength;
    }

    /**
     * Get the signature content length that will be used to reserve space for the final signature.
     *
     * @return integer
     */
    public function getSignatureContentLength()
    {
        return $this->_signatureConentLength;
    }

    /**
     * Set the signature field name.
     *
     * This can be the name of an existing signature field or an individual name which will be used to create a
     * hidden field automatically.
     *
     * @param string $fieldName The field name in UTF-8 encoding
     */
    public function setSignatureFieldName($fieldName)
    {
        $this->_fieldName = $fieldName;
    }

    /**
     * Get the signature field name.
     *
     * @return string
     */
    public function getSignatureFieldName()
    {
        return $this->_fieldName;
    }

    /**
     * Signs a collection of document instances.
     *
     * The document instances need to have writer instances setup properbly.
     *
     * @param SetaPDF_Core_Document[] $documents
     * @param bool $updateDss Defines if the revoke information should be added to the DSS afterwards.
     * @return bool
     * @throws SetaPDF_Core_Exception
     * @throws SetaPDF_Signer_Exception
     * @throws SetaPDF_Signer_Exception_ContentLength
     */
    public function sign(array $documents, $updateDss = false, $signatureProperties = array())
    {
        $digestMethod = $this->_getDigestMethod();

        $data = array();

        $no = 0;
        foreach ($documents AS $document) {
            $signer = new SetaPDF_Signer($document);
            $signer->setSignatureContentLength($this->getSignatureContentLength());
            $signer->setSignatureFieldName($this->getSignatureFieldName());

            foreach ($signatureProperties AS $name => $value) {
                $signer->setSignatureProperty($name, $value);
            }

            $tmpDocument = $signer->preSign(new SetaPDF_Core_Writer_TempFile());

            $data[$no] = array(
                'document' => $document,
                'signer' => $signer,
                'tmpDocument' => $tmpDocument,
                'digestValue' => $this->_getHash($tmpDocument->getHashFile())
            );

            $no++;
        }

        $req = array(
            'SignRequest' => array(
                'RequestID' => $this->_createRequestId(),
                'Profile' => 'http://ais.swisscom.ch/1.0',
                'OptionalInputs' => array(
                    'SignatureType' => 'urn:ietf:rfc:3369',
                    'ClaimedIdentity' => array(
                        'Name' => $this->_customerId
                    ),
                    'AdditionalProfile' => array('http://ais.swisscom.ch/1.0/profiles/batchprocessing')
                ),
                'InputDocuments' => array()
            )
        );

        foreach ($data AS $no => $documentData) {
            $req['SignRequest']['InputDocuments'][] = array(
                'ID' => $no,
                'DigestMethod' => array('Algorithm' => $digestMethod),
                'DigestValue' => $documentData['digestValue']
            );
        }

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
            $exception = new SetaPDF_Signer_SwisscomAIS_Exception(sprintf(
                'Swisscom AIS webservice returned an error: %s',
                $signResult->ResultMessage->_
            ));

            $exception->setRequest($req);
            $exception->setResult($this->_lastResult);

            throw $exception;
        }

        $signatures = $this->_lastResult->SignResponse->SignatureObject->Other->SignatureObjects->ExtendedSignatureObject;
        if (!is_array($signatures)) {
            $signatures = array($signatures);
        }

        foreach ($signatures AS $signatureData) {
            $signature = $signatureData->Base64Signature->_;
            $no = $signatureData->WhichDocument;

            $documentData = $data[$no];
            /**
             * @var $signer SetaPDF_Signer
             */
            $signer = $documentData['signer'];

            if (!$updateDss || !$this->_revokeInformation) {
                $signer->saveSignature($documentData['tmpDocument'], $signature);
            } else {
                $tempWriter  = new SetaPDF_Core_Writer_TempFile();
                $writer = $documentData['document']->getWriter();
                $documentData['document']->setWriter($tempWriter);
                $signer->saveSignature($documentData['tmpDocument'], $signature);

                $document = SetaPDF_Core_Document::loadByFilename($tempWriter->getPath(), $writer);
                if ($this->_addTimestamp && $this->_revokeInformation) {
                    $this->updateDss($document, $this->getSignatureFieldName());
                }

                $document->save()->finish();
            }
        }

        return true;
    }

    /**
     * Timestamps a collection of document instances.
     *
     * The document instances need to have writer instances setup properbly.
     *
     * @param SetaPDF_Core_Document[] $documents
     * @param bool $updateDss
     * @return bool
     * @throws SetaPDF_Core_Exception
     * @throws SetaPDF_Signer_Exception
     * @throws SetaPDF_Signer_Exception_ContentLength
     * @throws SetaPDF_Signer_SwisscomAIS_Exception
     */
    public function timestamp(array $documents, $updateDss = false)
    {
        $digestMethod = $this->_getDigestMethod();

        $data = array();

        $no = 0;
        foreach ($documents AS $document) {
            $signer = new SetaPDF_Signer($document);
            $signer->setSignatureContentLength($this->getSignatureContentLength());
            $signer->setSignatureFieldName($this->getSignatureFieldName());
            $tmpDocument = $signer->preTimestamp(new SetaPDF_Core_Writer_TempFile());

            $data[$no] = array(
                'document' => $document,
                'signer' => $signer,
                'tmpDocument' => $tmpDocument,
                'digestValue' => $this->_getHash($tmpDocument->getHashFile())
            );

            $no++;
        }

        $req = array(
            'SignRequest' => array(
                'RequestID' => $this->_createRequestId(),
                'Profile' => 'http://ais.swisscom.ch/1.0',
                'OptionalInputs' => array(
                    'SignatureType' => 'urn:ietf:rfc:3161',
                    'ClaimedIdentity' => array(
                        'Name' => $this->_customerId
                    ),
                    'AdditionalProfile' => array(
                        'http://ais.swisscom.ch/1.0/profiles/batchprocessing',
                        'urn:oasis:names:tc:dss:1.0:profiles:timestamping'
                    ),
                ),
                'InputDocuments' => array()
            )
        );

        foreach ($data AS $no => $documentData) {
            $req['SignRequest']['InputDocuments'][] = array(
                'ID' => $no,
                'DigestMethod' => array('Algorithm' => $digestMethod),
                'DigestValue' => $documentData['digestValue']
            );
        }

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
            $exception = new SetaPDF_Signer_SwisscomAIS_Exception(sprintf('Swisscom AIS webservice returned an error: %s',
                $signResult->ResultMessage->_
            ));

            $exception->setRequest($req);
            $exception->setResult($this->_lastResult);

            throw $exception;
        }

        $timestamps = $this->_lastResult->SignResponse->SignatureObject->Other->SignatureObjects->ExtendedSignatureObject;
        if (!is_array($timestamps)) {
            $timestamps = array($timestamps);
        }

        foreach ($timestamps AS $timestampData) {
            $timestamp = $timestampData->Timestamp->RFC3161TimeStampToken;
            $no = $timestampData->WhichDocument;

            $documentData = $data[$no];
            /**
             * @var $signer SetaPDF_Signer
             */
            $signer = $documentData['signer'];

            if (!$updateDss || !$this->_revokeInformation) {
                $signer->saveSignature($documentData['tmpDocument'], $timestamp);
            } else {
                $tempWriter  = new SetaPDF_Core_Writer_TempFile();
                $writer = $documentData['document']->getWriter();
                $documentData['document']->setWriter($tempWriter);
                $signer->saveSignature($documentData['tmpDocument'], $timestamp);

                $document = SetaPDF_Core_Document::loadByFilename($tempWriter->getPath(), $writer);
                $this->updateDss($document, $this->getSignatureFieldName());
                $document->save()->finish();
            }
        }

        return true;
    }
}