<?php
/* This demo shows you how to create a signature through the Swisscom All-in Signing Service including a timestamp
 * signature.
 *
 * It uses the signature standard "PAdES-baseline" and the revocation information of both signature and timestamp
 * are added to the Document Security Store (DSS) afterwards to have LTV enabled (PAdES Signature Level: B-LT).
 */

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\CurlHandler;
use Http\Factory\Guzzle\RequestFactory;
use Http\Factory\Guzzle\StreamFactory;
use Mjelamanov\GuzzlePsr18\Client as Psr18Wrapper;
use setasign\SetaPDF\Signer\Module\SwisscomAIS\Module;
use setasign\SetaPDF\Signer\Module\SwisscomAIS\SignException;

date_default_timezone_set('Europe/Berlin');
error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 1);

// require the autoload class from Composer
require_once('../vendor/autoload.php');

if (!file_exists(__DIR__ . '/settings/settings.php')) {
    throw new RuntimeException('Missing settings/settings.php!');
}

$settings = require(__DIR__ . '/settings/settings.php');

$guzzleOptions = [
    'handler' => new CurlHandler(),
    'http_errors' => false,
    'verify' => __DIR__ . '/ais-ca-ssl.crt',
    'cert' => $settings['cert'],
    'ssl_key' => $settings['privateKey']
];

$httpClient = new GuzzleClient($guzzleOptions);
// only required if you are using guzzle < 7
$httpClient = new Psr18Wrapper($httpClient);

// create an HTTP writer
$writer = new SetaPDF_Core_Writer_Http('Swisscom-ltv.pdf');
// let's get the document
$document = SetaPDF_Core_Document::loadByFilename('files/camtown/Laboratory-Report.pdf');

// now let's create a signer instance
$signer = new SetaPDF_Signer($document);
$signer->setAllowSignatureContentLengthChange(false);
$signer->setSignatureContentLength(30000);

// set some signature properties
$signer->setLocation($_SERVER['SERVER_NAME']);
$signer->setContactInfo('+01 2345 67890123');
$signer->setReason('Testing Swisscom AIS');

$field = $signer->getSignatureField();
$signer->setSignatureFieldName($field->getQualifiedName());

// create a Swisscom AIS module instance
$module = new Module($settings['customerId'], $httpClient, new RequestFactory(), new StreamFactory());
// additionally, the signature should include a qualified timestamp
$module->setAddTimestamp(true);

try {
    $tmpWriter = new SetaPDF_Core_Writer_TempFile();
    $document->setWriter($tmpWriter);

    // sign the document with the use of the module
    $signer->sign($module);

    $document = SetaPDF_Core_Document::loadByFilename($tmpWriter->getPath(), $writer);

    $module->updateDss($document, $field->getQualifiedName());
    $document->save()->finish();
} catch (SignException $e) {
    echo 'Error in SwisscomAIS: ' . $e->getMessage() . ' with code ' . $e->getCode() . '<br />';
    /* Get the AIS Error details */
    echo "<pre>";
    var_dump($e->getResultMajor());
    var_dump($e->getResultMinor());
    echo "</pre>";
}
