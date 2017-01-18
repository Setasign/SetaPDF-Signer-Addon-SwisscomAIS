<?php
/* This demo shows you how to create a simple signature with an on-demand certificate
 * through the Swisscom All-in Signing Service.
 *
 * More information about AIS are available here:
 * https://www.swisscom.ch/en/business/enterprise/offer/security/identity-access-security/signing-service.html
 */
date_default_timezone_set('Europe/Berlin');
error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 1);

// require the autoload class from Composer
require_once('../vendor/autoload.php');

// prepare on-demand data
$signerMail = 'demo@setasign.com';
$signerDn = 'cn=' . $signerMail . ', ou=For test purposes only!, o=Setasign TEST, c=de';
$signerLocation = 'Helmstedt';
$signerReason = 'I agree to the terms and condidtions in this document';
// optional step up
$approvalNo = ''; // '+41791234567'; // Set to empty for no step up authentication
$approvalLang = 'en';
$approvalMsg = 'Sign Laboratory-Report.pdf as ' . $signerMail . '?';
$approvalMsg .= ' (#TRANSID#)'; // Add the unique transaction ID placeholder at the end
// set the Mobile ID SerialNumber if needed (example: MIDCHEGU8GSH6K83)
$approvalSn = '';

if (file_exists('credentials-on-demand.php')) {
    // The vars are defined in this file for privacy reason.
    require('credentials-on-demand.php');
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

// create a HTTP writer
$writer = new SetaPDF_Core_Writer_Http('Swisscom.pdf');
// let's get the document
$document = SetaPDF_Core_Document::loadByFilename('files/tektown/Laboratory-Report.pdf', $writer);

// now let's create a signer instance
$signer = new SetaPDF_Signer($document);
$signer->setAllowSignatureContentLengthChange(false);
$signer->setSignatureContentLength(32000);

// set some signature properties
$signer->setLocation($_SERVER['SERVER_NAME']);
$signer->setContactInfo('+01 2345 67890123');
$signer->setReason('testing...');

// create an Swisscom AIS module instance
$module = new SetaPDF_Signer_SwisscomAIS_Module($customerId, $clientOptions);
// let's add PADES revoke information to the resulting signatures
$module->setAddRevokeInformation('PADES');
// additionally the signature should include a qualified timestamp
$module->setAddTimestamp(true);
// set on-demand options
$module->setOnDemandOptions($signerDn);
if ($approvalNo !== '') {
    $module->setOnDemandOptions($signerDn, $approvalNo, $approvalMsg, $approvalLang, $approvalSn);
}

try {
    // sign the document with the use of the module
    $signer->sign($module);

    // get information about the signing certificate:
    // $signatureData = SetaPDF_Signer_SwisscomAIS_Helper::getSignatureData($module);
    // echo("Signed by: " . $signatureData['subject'] . PHP_EOL);
    // echo("Unique Mobile ID serial number: " . $signatureData['MIDSN'] . PHP_EOL);


} catch (SetaPDF_Signer_SwisscomAIS_Exception $e) {
    echo 'Error in SwisscomAIS: ' . $e->getMessage() . ' with code ' . $e->getCode() . '<br />';
    /* Get the AIS Error details */
    echo "<pre>";
    var_dump($e->getResultMajor());
    var_dump($e->getResultMinor());
    // Mobile ID user assistance URL in case online help is available
    var_dump($e->getMobileIdUserAssistanceUrl());
    echo "</pre>";
}