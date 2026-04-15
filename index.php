<?php
declare(strict_types=1);

// ======================================================
// 1. SECURITY SETTINGS
// ======================================================
$my_secret_key = "DEVARAYAN_PAY_SECRET_2026";

// Get Authorization Header
$authHeader = '';

if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $authHeader = trim((string)$_SERVER['HTTP_AUTHORIZATION']);
} elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $authHeader = trim((string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
} elseif (function_exists('getallheaders')) {
    $headers = getallheaders();
    if (!empty($headers['Authorization'])) {
        $authHeader = trim((string)$headers['Authorization']);
    } elseif (!empty($headers['authorization'])) {
        $authHeader = trim((string)$headers['authorization']);
    }
}

// Allow both raw secret and Bearer secret
if (stripos($authHeader, 'Bearer ') === 0) {
    $authHeader = trim(substr($authHeader, 7));
}

if ($authHeader !== $my_secret_key) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Unauthorized");
}

// ======================================================
// 2. RECEIVE WEBHOOK DATA
// ======================================================
$jsonPayload = file_get_contents("php://input");

if ($jsonPayload === false || trim($jsonPayload) === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Bad Request: Empty payload");
}

$data = json_decode($jsonPayload, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Bad Request: Invalid JSON");
}

if (!isset($data['event']) || !is_array($data['event'])) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Bad Request: Missing event object");
}

$event = $data['event'];
$eventType = isset($event['type']) ? (string)$event['type'] : '';

// ======================================================
// 3. HELPER FUNCTIONS
// ======================================================
function getAttr(array $attrs, string $upperKey, string $camelKey, string $default = '0'): string
{
    $val = $default;

    if (isset($attrs[$upperKey]) && is_array($attrs[$upperKey]) && array_key_exists('value', $attrs[$upperKey])) {
        $val = $attrs[$upperKey]['value'];
    } elseif (isset($attrs[$camelKey]) && is_array($attrs[$camelKey]) && array_key_exists('value', $attrs[$camelKey])) {
        $val = $attrs[$camelKey]['value'];
    }

    if ($val === 'null' || $val === null || $val === '') {
        return $default;
    }

    return (string)$val;
}

function xmlEscape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

// ======================================================
// 4. EXTRACT DATA
// ======================================================
$transactionId = isset($event['transaction_id']) ? (string)$event['transaction_id'] : '0';
$appUserId     = isset($event['app_user_id']) ? (string)$event['app_user_id'] : '0';
$attributes    = isset($event['subscriber_attributes']) && is_array($event['subscriber_attributes'])
    ? $event['subscriber_attributes']
    : [];

$regId         = getAttr($attributes, 'REGID', 'regId', $appUserId);
$mobileNumber  = getAttr($attributes, 'MOBILE', 'mobileNo', '0000000000');
$mode          = getAttr($attributes, 'MODE', 'mode', '0');
$modeName      = getAttr($attributes, 'MODE_NAME', 'modeName', 'Unknown');
$daysOpt       = getAttr($attributes, 'DAYS_OPT', 'daysOpt', '0');
$contactsToAdd = getAttr($attributes, 'CONT_OPT', 'contOpt', '0');
$amtOpt        = getAttr($attributes, 'AMT_OPT', 'amtOpt', '0');
$disOpt        = getAttr($attributes, 'DIS_OPT', 'disOpt', '0');
$payAmount     = getAttr($attributes, 'PAY_AMOUNT', 'payAmount', '0');
$gatewayMode   = getAttr($attributes, 'GATE_WAY_MODE', 'gateWayMode', 'iOS_ApplePay');
$worldTrnId    = getAttr($attributes, 'WORL_TRN_ID', 'worlTranId', '2');
$worldOrderId  = getAttr($attributes, 'WORL_ORDER_ID', 'worlOrderId', '1');

// ======================================================
// 5. CALCULATE DUE DATE
// ======================================================
$dueDate = '';
$daysOptInt = (int)$daysOpt;

if ($daysOptInt > 0) {
    $dueDate = date('d M Y', strtotime('+' . $daysOptInt . ' days'));
}

// ======================================================
// 6. CALCULATE PAYMENT STATUS
// ======================================================
$actualPayStatus = 'P';

if (in_array($eventType, ['INITIAL_PURCHASE', 'NON_RENEWING_PURCHASE', 'PRODUCT_CHANGE'], true)) {
    $actualPayStatus = 'S';
} elseif (in_array($eventType, ['CANCELLATION', 'EXPIRATION', 'BILLING_ISSUE'], true)) {
    $actualPayStatus = 'F';
}

// Skip if contacts count is 0
if ((string)$contactsToAdd === '0') {
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Skipping: Contacts is 0");
}

// ======================================================
// 7. PREPARE PAYMENT MODEL
// ======================================================
$regIdInt = (int)$regId;

$payModel = [
    'REGID'           => $regId,
    'MOBILE'          => $mobileNumber,
    'MODE'            => $mode,
    'MODE_NAME'       => $modeName,
    'DAYS_OPT'        => $daysOpt,
    'CONT_OPT'        => $contactsToAdd,
    'AMT_OPT'         => $amtOpt,
    'DIS_OPT'         => $disOpt,
    'PAY_AMOUNT'      => $payAmount,
    'DUE_DATE'        => $dueDate,
    'ONLI_PAY_REF_ID' => $transactionId,
    'GATE_WAY_MODE'   => $gatewayMode,
    'STATUS'          => 'N',
    'PAY_STATUS'      => $actualPayStatus,
    'PAY_TXNID'       => $transactionId,
    'WORL_TRN_ID'     => $worldTrnId,
    'WORL_ORDER_ID'   => $worldOrderId
];

// ======================================================
// 8. BUILD SOAP XML REQUEST
// ======================================================
$url = "https://trustservice.sktm.in/WebService1.asmx";
$userName = 'G$$_1521_TMSK';

$xmlPostString = '<?xml version="1.0" encoding="utf-8"?>' .
'<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" ' .
'xmlns:xsd="http://www.w3.org/2001/XMLSchema" ' .
'xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">' .
'<soap:Body>' .
'<SUBS_Tran_Payment_StatusUpdate xmlns="http://tempuri.org/">' .
'<User_Nam>' . xmlEscape($userName) . '</User_Nam>' .
'<PAY_STATUS>' . xmlEscape($payModel['PAY_STATUS']) . '</PAY_STATUS>' .
'<PAY_TXNID>' . xmlEscape($payModel['PAY_TXNID']) . '</PAY_TXNID>' .
'<ONLI_PAY_REF_ID>' . xmlEscape($payModel['ONLI_PAY_REF_ID']) . '</ONLI_PAY_REF_ID>' .
'<WORL_TRN_ID>' . xmlEscape($payModel['WORL_TRN_ID']) . '</WORL_TRN_ID>' .
'<REGID>' . $regIdInt . '</REGID>' .
'</SUBS_Tran_Payment_StatusUpdate>' .
'</soap:Body>' .
'</soap:Envelope>';

// ======================================================
// 9. SEND SOAP REQUEST
// ======================================================
$soapHeaders = [
    "Content-Type: text/xml; charset=utf-8",
    'SOAPAction: "http://tempuri.org/SUBS_Tran_Payment_StatusUpdate"',
    "Content-Length: " . strlen($xmlPostString)
];

$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $xmlPostString,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => $soapHeaders,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
]);

$response = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);

curl_close($ch);

// ======================================================
// 10. PRINT SOAP RESPONSE
// ======================================================
header('Content-Type: text/plain; charset=utf-8');

if ($response === false) {
    http_response_code(502);
    echo "SOAP REQUEST:\n";
    echo $xmlPostString;
    echo "\n\n==============================\n\n";
    echo "SOAP ERROR:\n";
    echo $curlError;
    exit;
}

http_response_code($httpCode > 0 ? $httpCode : 200);

echo "SOAP RESPONSE:\n";
echo $response;
?>
