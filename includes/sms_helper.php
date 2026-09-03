<?php
// includes/sms_helper.php

class SMSHelper {
    private static string $apiKey = 'MOCK_FAST2SMS_KEY'; // Fast2SMS / MSG91 API key
    private static bool $testMode = true;
    private static array $logs = [];

    public static function sendSMS(string $phone, string $message): bool {
        // Log SMS dispatch
        self::$logs[] = [
            'phone' => $phone,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s')
        ];

        if (self::$testMode) {
            // Simulated successful SMS dispatch in test/local environment
            error_log("[SMS SENT to {$phone}]: {$message}");
            return true;
        }

        // Fast2SMS API Endpoint Implementation
        $url = "https://www.fast2sms.com/dev/bulkV2";
        $fields = [
            "message" => $message,
            "language" => "english",
            "route" => "q",
            "numbers" => $phone,
        ];

        $headers = [
            "authorization: " . self::$apiKey,
            "Content-Type: application/json"
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            error_log("Fast2SMS Error: " . $err);
            return false;
        }

        $resData = json_decode($response, true);
        return isset($resData['return']) && $resData['return'] === true;
    }

    public static function getLogs(): array {
        return self::$logs;
    }
}
