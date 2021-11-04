<?php
/* This demo shows you how to do batch signing through the Swisscom All-in Signing Service.
 *
 * More information about AIS are available here:
 * https://documents.swisscom.com/product/1000255-Digital_Signing_Service/Documents/Reference_Guide/Reference_Guide-All-in-Signing-Service-en.pdf
 */

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\CurlHandler;
use Http\Factory\Guzzle\RequestFactory;
use Http\Factory\Guzzle\StreamFactory;
use Mjelamanov\GuzzlePsr18\Client as Psr18Wrapper;
use setasign\SetaPDF\Signer\Module\SwisscomAIS\BatchModule;
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

// create a re-usable array of filenames (in/out)
$files = [
    [
        'in' => 'files/tektown/Laboratory-Report.pdf',
        'out' => 'output/tektown-signed.pdf',
        'tmp' => new SetaPDF_Core_Writer_TempFile()
    ],
    [
        'in' => 'files/lenstown/Laboratory-Report.pdf',
        'out' => 'output/lenstown-signed.pdf',
        'tmp' => new SetaPDF_Core_Writer_TempFile()
    ],
    [
        'in' => 'files/etown/Laboratory-Report.pdf',
        'out' => 'output/etown-signed.pdf',
        'tmp' => new SetaPDF_Core_Writer_TempFile()
    ],
    [
        'in' => 'files/camtown/Laboratory-Report.pdf',
        'out' => 'output/camtown-signed.pdf',
        'tmp' => new SetaPDF_Core_Writer_TempFile()
    ],
];

// initiate a batch instance
$batch = new BatchModule($settings['customerId'], $httpClient, new RequestFactory(), new StreamFactory());
$batch->setSignatureContentLength(60000);
// let's add PADES revoke information to the resulting signatures
$batch->setAddRevokeInformation('PADES');
// the signatures should include a timestamp, too
$batch->setAddTimestamp(true);

try {
    // sign the documents and add the revoke information to the DSS of the documents
    $batch->sign($files, true, [
        SetaPDF_Signer::PROP_LOCATION => $_SERVER['SERVER_NAME'],
        SetaPDF_Signer::PROP_REASON => 'Testing'
    ]);
} catch (SignException $e) {
    echo 'Error in SwisscomAIS: ' . $e->getMessage() . ' with code ' . $e->getCode() . '<br />';
    /* Get the AIS Error details */
    echo "<pre>";
    var_dump($e->getResultMajor());
    var_dump($e->getResultMinor());
    echo "</pre>";
    die();
}

// get access to the last result object
?>
 Signed documents:<br />

<ul>
    <?php foreach ($files as $file): ?>
    <li><a href="<?php echo $file['out'];?>" download target="_blank"><?php echo basename($file['out']);?></a></li>
    <?php endforeach; ?>
</ul>