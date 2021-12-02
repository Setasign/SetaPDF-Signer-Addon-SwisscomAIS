<?php
/* This demo shows you how to do batch signing with an on-demand certificate and Step-Up authentication
 * through the Swisscom All-in Signing Service including timestamp signatures.
 *
 * It uses the signature standard "PAdES-baseline" and the revocation information of both signature and timestamp
 * are added to the Document Security Store (DSS) afterwards to have LTV enabled (PAdES Signature Level: B-LT).
 */

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\CurlHandler;
use Http\Factory\Guzzle\RequestFactory;
use Http\Factory\Guzzle\StreamFactory;
use Mjelamanov\GuzzlePsr18\Client as Psr18Wrapper;
use setasign\SetaPDF\Signer\Module\SwisscomAIS\BatchAsyncModule;
use setasign\SetaPDF\Signer\Module\SwisscomAIS\BatchProcessData;
use setasign\SetaPDF\Signer\Module\SwisscomAIS\SignException;

date_default_timezone_set('Europe/Berlin');
error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 1);

// require the autoload class from Composer
require_once('../vendor/autoload.php');

if (!session_start()) {
    throw new RuntimeException('Couldn\'t start session.');
}

if (isset($_GET['restart']) && $_GET['restart'] === '1') {
    unset($_SESSION[__FILE__]);
}

if (!file_exists(__DIR__ . '/settings/settings-on-demand.php')) {
    throw new RuntimeException('Missing settings/settings-on-demand.php!');
}

$settings = require(__DIR__ . '/settings/settings-on-demand.php');

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

// initiate a batch instance
$batch = new BatchAsyncModule($settings['customerId'], $httpClient, new RequestFactory(), new StreamFactory());
if (!array_key_exists(__FILE__, $_SESSION)) {
    $files = [
        [
            'in' => 'files/tektown/Laboratory-Report.pdf',
            'out' => 'output/tektown-signed-on-demand.pdf',
            'tmp' => SetaPDF_Core_Writer_TempFile::createTempPath()
        ],
        [
            'in' => 'files/lenstown/Laboratory-Report.pdf',
            'out' => new SetaPDF_Core_Writer_File('output/lenstown-signed-on-demand.pdf'),
            'tmp' => SetaPDF_Core_Writer_TempFile::createTempPath()
        ],
        [
            'in' => 'files/etown/Laboratory-Report.pdf',
            'out' => 'output/etown-signed-on-demand.pdf',
            'tmp' => SetaPDF_Core_Writer_TempFile::createTempPath()
        ],
        [
            'in' => 'files/camtown/Laboratory-Report.pdf',
            'out' => new SetaPDF_Core_Writer_String(),
            'tmp' => SetaPDF_Core_Writer_TempFile::createTempPath(),
            'metadata' => [
                'filename' => 'camtown-signed-on-demand.pdf'
            ]
        ],
    ];


    // the signatures should include a timestamp, too
    $batch->setAddTimestamp(true);
    // set on-demand options
    $batch->setOnDemandCertificate($settings['distinguishedName']);
    if (isset($settings['stepUpAuthorisation'])) {
        $batch->setStepUpAuthorisation(
            $settings['stepUpAuthorisation']['msisdn'],
            'Please confirm to sign ' . count($files) . ' PDF documents.',
            $settings['stepUpAuthorisation']['language'],
            $settings['stepUpAuthorisation']['serialNumber'] ?? null
        );
    }

    $processData = $batch->initSignature($files);

    // For the purpose of this demo we just serialize the processData into the session.
    // You could use e.g. a database or a dedicated directory on your server.
    $_SESSION[__FILE__]['processData'] = $processData;

    $response = $batch->getLastResponseData();
    if (isset($response['SignResponse']['OptionalOutputs']['sc.StepUpAuthorisationInfo']['sc.Result']['sc.ConsentURL'])) {
        // The content of the website pointed by the consent URL can change over time and therefore the page
        // must be displayed as it is. The recommended methods for showing the content hosted under the consent
        // URL are:
        // • To embed an iFrame in the application (see [IFR - https://github.com/SwisscomTrustServices/AIS/wiki/SAS-iFrame-Embedding-Guide] for guidelines)
        // • To send an SMS to the user with the consent URL, so the user can open it directly on his phone browser

        $url = json_encode($response['SignResponse']['OptionalOutputs']['sc.StepUpAuthorisationInfo']['sc.Result']['sc.ConsentURL']);
        echo 'Started async signing process... <a href="#" onclick="openLink()">Please give your consent via mobile number ';
        echo $settings['stepUpAuthorisation']['msisdn'] . '.</a> (popups must be allowed)';
        echo '<br/><hr/>If you want to restart the signature process click here: <a href="?restart=1">Restart</a>';
        echo <<<HTML
<script type="text/javascript">
function openLink () {
    window.open(${url}, '_blank', 'location=yes,height=570,width=520,scrollbars=yes,status=yes');
    window.setTimeout(function () {window.location = window.location.pathname;}, 5000);
}
</script>
HTML;

        return;
    }

    echo 'Started async signing process (via mobile number ' . $settings['stepUpAuthorisation']['msisdn'] . '). Waiting for authorisation... ';
    echo 'The page should reload every 5 seconds.';
    echo '<script type="text/javascript">window.setTimeout(function () {window.location = window.location.pathname;}, 5000);</script>';
    return;
}

/** @var BatchProcessData $processData */
$processData = $_SESSION[__FILE__]['processData'];
$batch->setProcessData($processData);

try {
    $signResult = $batch->processPendingSignature();
} catch (SignException $e) {
    $minorResult = $e->getResultMinor();
    if ($minorResult === 'http://ais.swisscom.ch/1.1/resultminor/subsystem/StepUp/timeout') {
        echo 'StepUp authentification timeout.';
    } elseif ($minorResult === 'http://ais.swisscom.ch/1.1/resultminor/subsystem/StepUp/cancel') {
        echo 'StepUp authentification was canceled';
    } else {
        echo 'An error occurred: ' . htmlspecialchars($e->getMessage()) . '<br/>';
        var_dump($e->getResultMajor(), $e->getResultMinor());
    }

    $batch->cleanupTemporaryFiles();
    unset($_SESSION[__FILE__]);
    echo '<hr>Restart signing process here: <a href="?">Restart</a>';
    return;
} catch (Throwable $e) {
    echo 'Error on signing. If you want to restart the signature process click here: <a href="?restart=1">Restart</a>';
    var_dump($e);
    return;
}

if ($signResult === false) {
    echo 'Still pending! ';
    echo 'Waiting for authorisation via mobile number ' . $settings['stepUpAuthorisation']['msisdn'] . '. ';
    echo 'The page should reload every 5 seconds.';
    echo '<br/><hr/>If you want to restart the signature process click here: <a href="?restart=1">Restart</a>';
    echo '<script type="text/javascript">window.setTimeout(function () {window.location = window.location.pathname;}, 5000);</script>';
    return;
}

unset($_SESSION[__FILE__]);

$files = [];
foreach ($processData->getDocumentsData() as $documentData) {
    $writer = $documentData->getWriter();
    if ($writer instanceof SetaPDF_Core_Writer_File) {
        $files[basename($writer->getPath())] = $writer->getPath();
    // for demonstration purpose we show how to use individual metadata to store e.g. a filename
    } elseif ($writer instanceof SetaPDF_Core_Writer_String) {
        $files[$documentData->getMetadata()['filename']] = 'data:application/pdf;base64,' . base64_encode($writer->getBuffer());
    }
}
?>
 Signed documents:<br />

<ul>
    <?php foreach ($files as $name => $file): ?>
    <li><a href="<?php echo $file;?>" download="<?php echo htmlspecialchars($name);?>" target="_blank"><?php echo htmlspecialchars($name);?></a></li>
    <?php endforeach; ?>
</ul>


<br/><hr/>If you want to restart the signature process click here: <a href="?restart=1">Restart</a>
