<?php
/**
 * FIXED VERSION - FDE API Integration
 * File: /OMS/dist/api/fde_new_parcel_api.php
 */

/**
 * Make cURL request to FDE API (Following FDE's sample code structure)
 * @param string $url API endpoint URL
 * @param array $postData Data to send via POST
 * @return string API response
 */
function makeCurlRequest($url, $postData) {
    // Set the cURL options (simplified to match FDE sample)
    $curlOptions = array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
    );

    // Initialize cURL session
    $ch = curl_init();
    curl_setopt_array($ch, $curlOptions);

    // Execute cURL session and get the response
    $response = curl_exec($ch);

    // Check for cURL errors (simplified to match FDE sample)
    if (curl_errno($ch)) {
        $response = 'Curl error: ' . curl_error($ch);
    }

    // Close cURL session
    curl_close($ch);

    return $response;
}

/**
 * Call FDE API to create new parcel (Following FDE's sample structure)
 * @param array $apiData Array containing all required API parameters
 * @return string Raw API response from FDE
 */
function callFdeApi($apiData) {
    // FDE API endpoint (from their sample)
    $apiEndpoint = "https://www.fdedomestic.com/api/parcel/new_api_v1.php";
    
    // Make the API call directly with the provided data (following FDE sample)
    $response = makeCurlRequest($apiEndpoint, $apiData);
    
    return $response;
}
?>