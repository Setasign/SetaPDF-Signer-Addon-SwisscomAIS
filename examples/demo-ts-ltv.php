<?php
/* This demo shows you how to add a document timestamp signature to a PDF document
 * through the Swisscom All-in Signing Service. Additionally the revoke information
 * will be added to the Document Security Store (DSS).
 *
 * More information about AIS are available here:
 * https://www.swisscom.ch/en/business/enterprise/offer/security/identity-access-security/signing-service.html
 */
date_default_timezone_set('Europe/Berlin');
error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 1);

// require the autoload class from Composer
require_once('../vendor/autoload.php');

if (file_exists('credentials-ts.php')) {
    // The vars are defined in this file for privacy reason.
    require('credentials-ts.php');
} else {
    // path to your certificate and private key
    $cert = realpath('mycertandkey.crt');
    $passphrase = 'Passphrase for the private key in $cert';
    // your <customer name>:<key entity>
    $customerId = "";
}

// options for the SoapClient instance
$clientOptions = array(
    'stream_context' => stream_context_create(array(
        'ssl' => array(
            'verify_peer' => true,
            'cafile' => __DIR__ . '/ais-ca-ssl.crt',
            'peer_name' => 'ais.swisscom.com'
        )
    )),
    'local_cert' => $cert,
    'passphrase' => $passphrase
);

// the signature field name
$signatureFieldName = 'Signature';

// create a HTTP writer
$writer = new SetaPDF_Core_Writer_Http('Swisscom-Ts-Ltv.pdf');
$tempWriter = new SetaPDF_Core_Writer_TempFile();
// let's get the document
$document = SetaPDF_Core_Document::loadByFilename('files/tektown/Laboratory-Report.pdf', $tempWriter);

// now let's create a signer instance
$signer = new SetaPDF_Signer($document);
$signer->setAllowSignatureContentLengthChange(false);
$signer->setSignatureContentLength(32000);
$signer->setSignatureFieldName($signatureFieldName);

// set some signature properties
$signer->setLocation($_SERVER['SERVER_NAME']);
$signer->setContactInfo('+01 2345 67890123');
$signer->setReason('testing...');

// create an Swisscom AIS module instance
$module = new SetaPDF_Signer_SwisscomAIS_Module($customerId, $clientOptions);
// let's add PADES revoke information to the resulting signatures
$module->setAddRevokeInformation('PADES');
// pass the timestamp module to the signer instance
$signer->setTimestampModule($module);

try {
    // create the document timestamp
    $signer->timestamp();
} catch (SetaPDF_Signer_SwisscomAIS_Exception $e) {
    echo 'Error in SwisscomAIS: ' . $e->getMessage() . ' with code ' . $e->getCode() . '<br />';
    /* Get the AIS Error details */
    echo "<pre>";
    var_dump($e->getResultMajor());
    var_dump($e->getResultMinor());
    echo "</pre>";
    die();
}

// get a document instance of the temporary result
$document = SetaPDF_Core_Document::loadByFilename($tempWriter->getPath(), $writer);

// update the DSS with the revoke information of the last response
$module->updateDss($document, $signatureFieldName);

// save and finish
$document->save()->finish();