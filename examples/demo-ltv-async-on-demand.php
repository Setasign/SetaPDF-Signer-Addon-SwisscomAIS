<?php
/* This demo shows you how to create a simple signature with an on-demand certificate and Step-Up authentication
 * through the Swisscom All-in Signing Service including a timestamp signature.
 *
 * It uses the signature standard "PAdES-baseline" and the revocation information of both signature and timestamp
 * are added to the Document Security Store (DSS) afterwards to have LTV enabled (PAdES Signature Level: B-LT).
 */

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\CurlHandler;
use Http\Factory\Guzzle\RequestFactory;
use Http\Factory\Guzzle\StreamFactory;
use Mjelamanov\GuzzlePsr18\Client as Psr18Wrapper;
use setasign\SetaPDF\Signer\Module\SwisscomAIS\AsyncModule;
use setasign\SetaPDF\Signer\Module\SwisscomAIS\ProcessData;
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

// let's get the document
$document = SetaPDF_Core_Document::loadByFilename('files/tektown/Laboratory-Report.pdf');

// now let's create a signer instance
$signer = new SetaPDF_Signer($document);
// create a Swisscom AIS module instance
$swisscomModule = new AsyncModule($settings['customerId'], $httpClient, new RequestFactory(), new StreamFactory());
if (!array_key_exists(__FILE__, $_SESSION)) {
    $signer->setAllowSignatureContentLengthChange(false);
    $signer->setSignatureContentLength(34000);

    // set some signature properties
    $signer->setLocation($_SERVER['SERVER_NAME']);
    $signer->setContactInfo('+01 2345 67890123');
    $signer->setReason('testing...');

    $fieldName = $signer->addSignatureField()->getQualifiedName();
    $signer->setSignatureFieldName($fieldName);

    // additionally, the signature should include a qualified timestamp
    $swisscomModule->setAddTimestamp(true);
    // set on-demand options
    $swisscomModule->setOnDemandCertificate($settings['distinguishedName']);
    if (isset($settings['stepUpAuthorisation'])) {
        $swisscomModule->setStepUpAuthorisation(
            $settings['stepUpAuthorisation']['msisdn'],
            'Please confirm to sign Laboratory-Report.pdf',
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

    $processData = $swisscomModule->initSignature($tmpDocument, $fieldName);
    // inject individual metadata into the process data
    $processData->setMetadata(['filename' => 'Swisscom.pdf']);

    // For the purpose of this demo we just serialize the processData into the session.
    // You could use e.g. a database or a dedicated directory on your server.
    $_SESSION[__FILE__]['processData'] = $processData;

    $response = $swisscomModule->getLastResponseData();
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
    window.open({$url}, '_blank', 'location=yes,height=570,width=520,scrollbars=yes,status=yes');
    window.setTimeout(function () {window.location = window.location.pathname;}, 5000);
}
</script>
HTML;

        return;
    }

    echo 'Started async signing process (via mobile number ' . $settings['stepUpAuthorisation']['msisdn'] . '). Waiting for authorisation... ';
    echo 'The page should reload every 5 seconds.';
    echo '<br/><hr/>If you want to restart the signature process click here: <a href="?restart=1">Restart</a>';
    echo '<script type="text/javascript">window.setTimeout(function () {window.location = window.location.pathname;}, 5000);</script>';
    return;
}

/** @var ProcessData $processData */
$processData = $_SESSION[__FILE__]['processData'];
$swisscomModule->setProcessData($processData);

try {
    $signResult = $swisscomModule->processPendingSignature();
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

    echo '<hr>Restart signing process here: <a href="?">Restart</a>';
    // clean up temporary file
    unlink($processData->getTmpDocument()->getWriter()->getPath());
    unset($_SESSION[__FILE__]);
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

try {
    $tmpWriter = new SetaPDF_Core_Writer_TempFile();
    $document->setWriter($tmpWriter);

    // save the signature to the temporary document
    $signer->saveSignature($processData->getTmpDocument(), $signResult);

    // add DSS
    $document = SetaPDF_Core_Document::loadByFilename($tmpWriter->getPath());
    // use the filename stored in the process data metadata to create a writer instance
    $writer = new SetaPDF_Core_Writer_Http($processData->getMetadata()['filename']);
    $document->setWriter($writer);
    $swisscomModule->updateDss($document, $processData->getFieldName());
    $document->save()->finish();

    // clean up temporary file
    unlink($processData->getTmpDocument()->getWriter()->getPath());
    unset($_SESSION[__FILE__]);
} catch (Throwable $e) {
    echo 'Error on saving the signature. If you want to restart the signature process click here: <a href="?restart=1">Restart</a>';
    var_dump($e);
    return;
}

echo '<br/><hr/>If you want to restart the signature process click here: <a href="?restart=1">Restart</a>';
