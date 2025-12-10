<?php

/**
 * Call FDE Existing Parcel API
 * @param array $api_data - Array containing API parameters
 * @return string - JSON response from API
 */
function callFdeExistingParcelApi($api_data) {
    // Set the API endpoint URL for existing parcel
    $apiEndpoint = "https://www.fdedomestic.com/api/parcel/existing_waybill_api_v1.php";

    // Use the input data directly as POST data since keys match API requirements

    // Set the cURL options and make request
    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL => $apiEndpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $api_data,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ));

    // Execute cURL session and get the response
    $response = curl_exec($ch);

    // Check for cURL errors
    if (curl_errno($ch)) {
        $response = 'Curl error: ' . curl_error($ch);
    }

    // Close cURL session
    curl_close($ch);

    return $response;
}

?>