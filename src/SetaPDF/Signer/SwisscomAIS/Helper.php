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
 * A class offering various helper methods.
 *
 * @link https://www.swisscom.ch/en/business/enterprise/offer/security/identity-access-security/signing-service.html
 * @copyright  Copyright (c) 2015 Setasign - Jan Slabon (http://www.setasign.com)
 * @category   SetaPDF
 * @package    SetaPDF_Signer
 * @license    http://www.apache.org/licenses/LICENSE-2.0
 */
class SetaPDF_Signer_SwisscomAIS_Helper
{
    /**
     * Get signature data from a single signature container or by a module instance.
     *
     * @param string|SetaPDF_Signer_SwisscomAIS_AbstractModule $signature
     * @return array
     * @throws SetaPDF_Signer_Asn1_Exception|InvalidArgumentException
     */
    static public function getSignatureData($signature)
    {
        if ($signature instanceof SetaPDF_Signer_SwisscomAIS_Batch) {
            $result = array();

            $lastResult = $signature->getLastResult();
            $signatures = $lastResult->SignResponse->SignatureObject->Other->SignatureObjects->ExtendedSignatureObject;
            if (!is_array($signatures)) {
                $signatures = array($signatures);
            }

            foreach ($signatures AS $signatureData) {
                if (isset($signatureData->Timestamp->RFC3161TimeStampToken)) {
                    $signature = $signatureData->Timestamp->RFC3161TimeStampToken;;
                } else {
                    $signature = $signatureData->Base64Signature->_;
                }

                $no = $signatureData->WhichDocument;

                $result[$no] = self::_getSignatureData($signature);
            }

            return $result;

        } elseif ($signature instanceof SetaPDF_Signer_SwisscomAIS_Module) {
            $lastResult = $signature->getLastResult();
            // signature
            if (isset($lastResult->SignResponse->SignatureObject->Base64Signature)) {
                $signature = $lastResult->SignResponse->SignatureObject->Base64Signature->_;
            } elseif (isset($lastResult->SignResponse->SignatureObject->Timestamp->RFC3161TimeStampToken)) {
                $signature = $lastResult->SignResponse->SignatureObject->Timestamp->RFC3161TimeStampToken;
            } else {
                throw new InvalidArgumentException('Unable to get signature from module.');
            }
        }

        return self::_getSignatureData($signature);
    }

    /**
     * Get signature data from a single signature container.
     *
     * @param string $signature
     * @return array
     * @throws SetaPDF_Signer_Asn1_Exception
     */
    static private function _getSignatureData($signature)
    {
        $data = array(
            'certificates' => array(),
            'signerCertificate' => null,
            'subject' => null,
            'MIDSN' => null
        );

        $asn1 = SetaPDF_Signer_Asn1_Element::parse($signature);
        $certificates = SetaPDF_Signer_Asn1_Element::findByPath('1/0/3', $asn1);
        $certificates = $certificates->getChildren();

        $lastValidToTime = PHP_INT_MAX;

        for ($no = 0; $no < count($certificates); $no++) {
            $certificate = $certificates[$no];
            $certificate = $certificate->__toString();
            $certificate = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($certificate)) . "-----END CERTIFICATE-----";

            $certificateInfo = openssl_x509_parse($certificate);

            $data['certificates'][] = $certificateInfo;

            if (isset($certificateInfo['validTo_time_t']) && $certificateInfo['validTo_time_t'] <= $lastValidToTime) {
                $lastValidToTime = $certificateInfo['validTo_time_t'];
                $data['signerCertificate'] = $certificateInfo;
            }
        }

        $data['subject'] = $data['signerCertificate']['name'];

        // extract MIDSN
        if (isset($data['signerCertificate']['extensions']['subjectAltName'])) {
            $subjectAltName = $data['signerCertificate']['extensions']['subjectAltName'];
            // Format: 'DirName: serialNumber = ID-16981fa2-8998-4125-9a93-5fecbff74515, name = "+41798...", description = test.ch: Signer le document?, pseudonym = MIDCHEGU8GSH6K83'
            $subjectAltNameArray = explode(', ', $subjectAltName);
            foreach ($subjectAltNameArray as $value) {
                if (preg_match("/pseudonym = (.*)/", $value, $match))
                    $data['MIDSN'] = $match[1];
            }

            // isn't this the same?
            // if (preg_match("/pseudonym = (.*)/", $subjectAltName, $match)) {
            //    $data['MIDSN'] = $match[1];
            // }
        }

        return $data;
    }
}