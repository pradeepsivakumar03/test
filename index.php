<?php
// 1. SECURITY SETTINGS
$my_secret_key = "DEVARAYAN_PAY_SECRET_2026";
// Get Authorization Header
$auth_header = '';
if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $auth_header = trim($_SERVER['HTTP_AUTHORIZATION']);
} elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $auth_header = trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
}
if ($auth_header !== $my_secret_key) {
    http_response_code(401);
    exit("Unauthorized");
}
// 2. RECEIVE WEBHOOK DATA
$json_payload = file_get_contents("php://input");
$data = json_decode($json_payload, true);
if (!$data || !isset($data['event'])) {
    http_response_code(400);
    exit("Bad Request");
}
$event = $data['event'];
$eventType = isset($event['type']) ? $event['type'] : '';
// 3. EXTRACT THE REAL DATA FROM REVENUECAT
$transactionId = isset($event['transaction_id']) ? $event['transaction_id'] : '0';
$attributes = isset($event['subscriber_attributes']) ? $event['subscriber_attributes'] : [];
// 🔥 SMARTER HELPER FUNCTION: Blocks the literal string "null" from crashing your DB!
function getAttr($attrs, $upperKey, $camelKey, $default = '0') {
    $val = $default;
    
    if (isset($attrs[$upperKey]['value'])) {
        $val = $attrs[$upperKey]['value'];
    } elseif (isset($attrs[$camelKey]['value'])) {
        $val = $attrs[$camelKey]['value'];
    }
    
    // If Flutter accidentally sent the word "null", replace it with the safe default!
    if ($val === 'null' || $val === null || $val === '') {
        return $default;
    }
    
    return $val;
}
// Extract attributes safely with defaults
$regId = getAttr($attributes, 'REGID', 'regId', isset($event['app_user_id']) ? $event['app_user_id'] : '0');
$mobileNumber = getAttr($attributes, 'MOBILE', 'mobileNo', '0000000000'); // Default to zeros if missing
$mode = getAttr($attributes, 'MODE', 'mode', '0');
$modeName = getAttr($attributes, 'MODE_NAME', 'modeName', 'Unknown');
$daysOpt = getAttr($attributes, 'DAYS_OPT', 'daysOpt', '0');
$contactsToAdd = getAttr($attributes, 'CONT_OPT', 'contOpt', '0');
$amtOpt = getAttr($attributes, 'AMT_OPT', 'amtOpt', '0');
$disOpt = getAttr($attributes, 'DIS_OPT', 'disOpt', '0');
$payAmount = getAttr($attributes, 'PAY_AMOUNT', 'payAmount', '0');
$gatewayMode = getAttr($attributes, 'GATE_WAY_MODE', 'gateWayMode', 'iOS_ApplePay');
$worldOrderId = getAttr($attributes, 'WORL_ORDER_ID', 'worlOrderId', '1');
// 🔥 FIX: CALCULATE DUE DATE IN PHP 🔥
$dueDate = "";
if (intval($daysOpt) > 0) {
    $dueDate = date('d M Y', strtotime("+" . intval($daysOpt) . " days"));
}
// 4. CALCULATE ACTUAL PAYMENT STATUS
$actualPayStatus = 'P'; 
$actualStatus = 'N';    
if ($eventType === "INITIAL_PURCHASE" || $eventType === "RENEWAL" || $eventType === "PRODUCT_CHANGE") {
    $actualPayStatus = 'S'; // Success
    $actualStatus = 'Y';    // Active
} elseif ($eventType === "CANCELLATION" || $eventType === "EXPIRATION" || $eventType === "BILLING_ISSUE") {
    $actualPayStatus = 'F'; // Failed
    $actualStatus = 'N';    // Inactive
}
if ($contactsToAdd == '0') {
    http_response_code(200);
    exit("Skipping: Contacts is 0");
}
// 5. PREPARE JSON DATA FOR DB
$regIdInt = intval($regId);
$payModel = array(
    'REGID' => $regId,
    'MOBILE' => $mobileNumber,
    'MODE' => $mode,
    'MODE_NAME' => $modeName,
    'DAYS_OPT' => $daysOpt,
    'CONT_OPT' => $contactsToAdd,
    'AMT_OPT' => $amtOpt,               
    'DIS_OPT' => $disOpt,               
    'PAY_AMOUNT' => $payAmount,         
    'DUE_DATE' => $dueDate,              // PHP Generated Date
    'ONLI_PAY_REF_ID' => $transactionId, // Apple's Real Transaction ID
    'GATE_WAY_MODE' => $gatewayMode,    
    'STATUS' => $actualStatus,           // PHP Calculated Status
    'PAY_STATUS' => $actualPayStatus,    // PHP Calculated Status
    'PAY_TXNID' => $transactionId,       // Apple's Real Transaction ID
    'WORL_TRN_ID' => $transactionId,     // Apple's Real Transaction ID
    'WORL_ORDER_ID' => $worldOrderId    
);
$jsonArray = array($payModel);
$insertDataString = json_encode($jsonArray);
// 6. BUILD AND SEND SOAP REQUEST
$xml_post_string = '<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body>
    <SAVE_SUBCRIPTION_TRANS xmlns="http://tempuri.org/">
      <User_Nam>G$$_1521_TMSK</User_Nam>
      <INSERT_DATA>' . htmlspecialchars($insertDataString) . '</INSERT_DATA>
      <REGID>' . $regIdInt . '</REGID>
    </SAVE_SUBCRIPTION_TRANS>
  </soap:Body>
</soap:Envelope>';
$url = "https://trustservice.sktm.in/WebService1.asmx";
$soap_headers = array(
    "Content-Type: text/xml; charset=utf-8",
    "SOAPAction: \"http://tempuri.org/SAVE_SUBCRIPTION_TRANS\"",
    "Content-Length: " . strlen($xml_post_string)
);
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $xml_post_string);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $soap_headers); 
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
// 🔥 GET THE REAL DATABASE RESPONSE 🔥
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
// Output the real HTTP code and the raw Matrimony Database answer
http_response_code($http_code);
echo "SKTM DATABASE RESPONSE: \n";
echo $response;
?>
