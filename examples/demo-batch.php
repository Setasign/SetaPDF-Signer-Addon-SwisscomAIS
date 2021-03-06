<?php
/* This demo shows you how to do batch signing through a single webservice
 * call of the Swisscom All-in Signing Service.
 *
 * More information about AIS are available here:
 * https://www.swisscom.ch/en/business/enterprise/offer/security/identity-access-security/signing-service.html
 */
date_default_timezone_set('Europe/Berlin');
error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 1);

// require the autoload class from Composer
require_once('../vendor/autoload.php');

if (file_exists('credentials.php')) {
    // The vars are defined in this file for privacy reason.
    require('credentials.php');
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

// create a re-usable array of filenames (in/out)
$files = array(
    array(
        'in' => 'files/tektown/Laboratory-Report.pdf',
        'out' => 'output/tektown-signed.pdf'
    ),
    array(
        'in' => 'files/lenstown/Laboratory-Report.pdf',
        'out' => 'output/lenstown-signed.pdf'
    ),
    array(
        'in' => 'files/etown/Laboratory-Report.pdf',
        'out' => 'output/etown-signed.pdf'
    ),
    array(
        'in' => 'files/camtown/Laboratory-Report.pdf',
        'out' => 'output/camtown-signed.pdf'
    ),
);

// create document instances by the filenames
$documents = array();
foreach ($files AS $file) {
    $documents[] = SetaPDF_Core_Document::loadByFilename(
        $file['in'],
        new SetaPDF_Core_Writer_File($file['out'])
    );
}

// initiate a batch instance
$batch = new SetaPDF_Signer_SwisscomAIS_Batch($customerId, $clientOptions);
// let's add PADES revoke information to the resulting signatures
$batch->setAddRevokeInformation('PADES');
// the signatures should include a timestamp, too
$batch->setAddTimestamp(true);

try {
    // sign the documents and add the revoke information to the DSS of the documents
    $batch->sign($documents, true, array(
        SetaPDF_Signer::PROP_LOCATION => $_SERVER['SERVER_NAME'],
        SetaPDF_Signer::PROP_REASON => 'Testing'
    ));
} catch (SetaPDF_Signer_SwisscomAIS_Exception $e) {
    echo 'Error in SwisscomAIS: ' . $e->getMessage() . ' with code ' . $e->getCode() . '<br />';
    /* Get the AIS Error details */
    echo "<pre>";
    var_dump($e->getResultMajor());
    var_dump($e->getResultMinor());
    echo "</pre>";
    die();
}

// get access to the last result object
$result = $batch->getLastResult();

echo count($result->SignResponse->SignatureObject->Other->SignatureObjects->ExtendedSignatureObject);
?>
 Signatures created.<br />

<ul>
    <?php foreach ($files AS $file): ?>
    <li><a href="<?php echo $file['out'];?>"><?php echo basename($file['out']);?></a></li>
    <?php endforeach; ?>
</ul>