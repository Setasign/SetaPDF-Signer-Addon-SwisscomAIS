<?php
/* This demo shows you how to add a document timestamp signature to a PDF document
 * through the Swisscom All-in Signing Service.
 *
 * The revocation information of the timestamp signature is added to the Document Security Store (DSS) afterwards to
 * have LTV enabled.
 */

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\CurlHandler;
use Http\Factory\Guzzle\RequestFactory;
use Http\Factory\Guzzle\StreamFactory;
use Mjelamanov\GuzzlePsr18\Client as Psr18Wrapper;
use setasign\SetaPDF\Signer\Module\SwisscomAIS\SignException;
use setasign\SetaPDF\Signer\Module\SwisscomAIS\TimestampModule;

date_default_timezone_set('Europe/Berlin');
error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 1);

// require the autoload class from Composer
require_once('../vendor/autoload.php');

if (!file_exists(__DIR__ . '/settings/settings-ts.php')) {
    throw new RuntimeException('Missing settings/settings-ts.php!');
}

$settings = require(__DIR__ . '/settings/settings-ts.php');

$guzzleOptions = [
    'handler' => new CurlHandler(),
    'http_errors' => false,
    'verify' => __DIR__ . '/ais-ca-ssl.crt',
    'cert' => $settings['cert'],
    'ssl_key' => $settings['privateKey']
];

$httpClient = new GuzzleClient($guzzleOptions);

// create an HTTP writer
$writer = new SetaPDF_Core_Writer_Http('Swisscom-Ts-Ltv.pdf');
$tempWriter = new SetaPDF_Core_Writer_TempFile();
// let's get the document
$document = SetaPDF_Core_Document::loadByFilename('files/tektown/Laboratory-Report.pdf', $tempWriter);

// now let's create a signer instance
$signer = new SetaPDF_Signer($document);
$signer->setAllowSignatureContentLengthChange(false);
$signer->setSignatureContentLength(17500);

$fieldName = $signer->getSignatureField()->getQualifiedName();
$signer->setSignatureFieldName($fieldName);

// set some signature properties
$signer->setLocation($_SERVER['SERVER_NAME']);
$signer->setContactInfo('+01 2345 67890123');
$signer->setReason('testing...');

// create a Swisscom AIS module instance
$module = new TimestampModule($settings['customerId'], $httpClient, new RequestFactory(), new StreamFactory());
// pass the timestamp module to the signer instance
$signer->setTimestampModule($module);

try {
    // create the document timestamp
    $signer->timestamp();
} catch (SignException $e) {
    echo 'Error in SwisscomAIS: ' . $e->getMessage() . ' with code ' . $e->getCode() . '<br />';
    /* Get the AIS Error details */
    echo "<pre>";
    var_dump($e->getResultMajor());
    var_dump($e->getResultMinor());
    echo "</pre>";
    return;
}

// get a document instance of the temporary result
$document = SetaPDF_Core_Document::loadByFilename($tempWriter->getPath(), $writer);

// update the DSS with the revoke information of the last response
$module->updateDss($document, $fieldName);

// save and finish
$document->save()->finish();
