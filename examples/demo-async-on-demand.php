<?php
/* This demo shows you how to create a simple signature with an on-demand certificate
 * through the Swisscom All-in Signing Service.
 *
 * More information about AIS are available here:
 * https://documents.swisscom.com/product/1000255-Digital_Signing_Service/Documents/Reference_Guide/Reference_Guide-All-in-Signing-Service-en.pdf
 */

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\CurlHandler;
use Http\Factory\Guzzle\RequestFactory;
use Http\Factory\Guzzle\StreamFactory;
use Mjelamanov\GuzzlePsr18\Client as Psr18Wrapper;
use setasign\SetaPDF\Signer\Module\SwisscomAIS\AsyncModule;
use setasign\SetaPDF\Signer\Module\SwisscomAIS\PendingException;
use setasign\SetaPDF\Signer\Module\SwisscomAIS\SignException;

date_default_timezone_set('Europe/Berlin');
error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 1);

// require the autoload class from Composer
require_once('../vendor/autoload.php');

session_start();
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

// create an HTTP writer
$writer = new SetaPDF_Core_Writer_Http('Swisscom.pdf');
// let's get the document
$document = SetaPDF_Core_Document::loadByFilename('files/tektown/Laboratory-Report.pdf', $writer);

// now let's create a signer instance
$signer = new SetaPDF_Signer($document);
// create a Swisscom AIS module instance
$swisscomModule = new AsyncModule($settings['customerId'], $httpClient, new RequestFactory(), new StreamFactory());
if (!array_key_exists(__FILE__, $_SESSION)) {
    $signer->setAllowSignatureContentLengthChange(false);
    $signer->setSignatureContentLength(100000);

    // set some signature properties
    $signer->setLocation($_SERVER['SERVER_NAME']);
    $signer->setContactInfo('+01 2345 67890123');
    $signer->setReason('testing...');

    // let's add PADES revoke information to the resulting signatures
    $swisscomModule->setAddRevokeInformation('PADES');
    // additionally, the signature should include a qualified timestamp
    $swisscomModule->setAddTimestamp(true);
    // set on-demand options
    $swisscomModule->setOnDemandCertificate($settings['distinguishedName']);
    if (isset($settings['stepUpAuthorisation'])) {
        $swisscomModule->setStepUpAuthorisation(
            $settings['stepUpAuthorisation']['msisdn'],
            $settings['stepUpAuthorisation']['message'],
            $settings['stepUpAuthorisation']['language'],
            $settings['stepUpAuthorisation']['serialNumber'] ?? null
        );
    }

    // you may use an own temporary file handler
    $tempPath = SetaPDF_Core_Writer_TempFile::createTempPath();

    // prepare the PDF
    $tmpDocument = $signer->preSign(
        new SetaPDF_Core_Writer_File($tempPath),
        $swisscomModule
    );

    $processData = $swisscomModule->initSignature($tmpDocument->getHashFile());

    // For the purpose of this demo we just serialize the processData into the session.
    // You could use e.g. a database or a dedicated directory on your server.
    $_SESSION[__FILE__] = [
        'tmpDocument' => $tmpDocument,
        'processData' => $processData
    ];

    $response = $swisscomModule->getLastResponseData();
    if (isset($response['SignResponse']['OptionalOutputs']['sc.StepUpAuthorisationInfo']['sc.Result']['sc.ConsentURL'])) {
        // The content of the website pointed by the consent URL can change over time and therefore the page
        // must be displayed as it is. The recommended methods for showing the content hosted under the consent
        // URL are:
        // • To embed an iFrame in the application (see [IFR - https://github.com/SCS-CBU-CED-IAM/AIS/wiki/SAS-iFrame-Embedding-Guide] for guidelines)
        // • To send an SMS to the user with the consent URL, so the user can open it directly on his phone browser

        $url = json_encode($response['SignResponse']['OptionalOutputs']['sc.StepUpAuthorisationInfo']['sc.Result']['sc.ConsentURL']);
        echo 'Started async signing process... <a href="#" onclick="openLink()">Please give your consent.</a> (popups must be allowed)';
        echo '<br/><hr/>If you want to restart the signature process click here: <a href="?restart=1">Restart</a>';
        echo <<<HTML
<script type="text/javascript">
function openLink () {
    console.info(window.open(${url}, '_blank', 'location=yes,height=570,width=520,scrollbars=yes,status=yes'));
    window.setTimeout(function () {window.location = window.location.pathname;}, 5000);
}
</script>
HTML;

        return;
    }

    echo 'Started async signing process... The page should reload every 5 seconds.';
    echo '<br/><hr/>If you want to restart the signature process click here: <a href="?restart=1">Restart</a>';
    echo '<script type="text/javascript">window.setTimeout(function () {window.location = window.location.pathname;}, 5000);</script>';
    return;
}

$tmpDocument = $_SESSION[__FILE__]['tmpDocument'];
$processData = $_SESSION[__FILE__]['processData'];
$swisscomModule->setProcessData($processData);

try {
    $cms = $swisscomModule->processPendingSignature();
} catch (PendingException $e) {
    echo 'Still pending! The page should reload every 5 seconds.';
    echo '<br/><hr/>If you want to restart the signature process click here: <a href="?restart=1">Restart</a>';
    echo '<script type="text/javascript">window.setTimeout(function () {window.location = window.location.pathname;}, 5000);</script>';
    return;
} catch (SignException $e) {
    $minorResult = $e->getResultMinor();
    if ($minorResult === 'http://ais.swisscom.ch/1.1/resultminor/subsystem/StepUp/timeout') {
        echo 'StepUp authentification timeout.';
    } elseif ($minorResult === 'http://ais.swisscom.ch/1.1/resultminor/subsystem/StepUp/cancel') {
        echo 'StepUp authentification was canceled';
    } else {
        var_dump($e->getResultMajor(), $e->getResultMinor());
    }
    echo '<br/>Canceled signature process';
    echo '<hr>Restart signing process here: <a href="?">Restart</a>';
    // clean up temporary file
    unlink($tmpDocument->getWriter()->getPath());
    unlink('test.tmp');
    return;
} catch (Throwable $e) {
    echo 'Error on signing. If you want to restart the signature process click here: <a href="?restart=1">Restart</a>';
    var_dump($e);
    return;
}

// save the signature to the temporary document
$signer->saveSignature($tmpDocument, $cms);
// clean up temporary file
unlink($tmpDocument->getWriter()->getPath());
unlink('test.tmp');

echo '<br/><hr/>If you want to restart the signature process click here: <a href="?restart=1">Restart</a>';