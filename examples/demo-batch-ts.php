<?php
/* This demo shows you how to do add timestamp signatures in a batch process
 * through a single webservice call of the Swisscom All-in Signing Service.
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
    // your <customer name>
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
        'out' => 'output/tektown-timestamped.pdf'
    ),
    array(
        'in' => 'files/lenstown/Laboratory-Report.pdf',
        'out' => 'output/lenstown-timestamped.pdf'
    ),
    array(
        'in' => 'files/etown/Laboratory-Report.pdf',
        'out' => 'output/etown-timestamped.pdf'
    ),
    array(
        'in' => 'files/camtown/Laboratory-Report.pdf',
        'out' => 'output/camtown-timestamped.pdf'
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
try {
    // timestamp the documents and add the revoke information to the DSS of the documents
    $batch->timestamp($documents, true);
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
 Timestamp-Signatures created.<br />

<ul>
    <?php foreach ($files AS $file): ?>
    <li><a href="<?php echo $file['out'];?>"><?php echo basename($file['out']);?></a></li>
    <?php endforeach; ?>
</ul>

