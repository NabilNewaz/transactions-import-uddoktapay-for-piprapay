<?php

header("Content-Type: application/json");

$plugin_slug = 'transactions-import-uddoktapay';

// Function to dynamically find pp-config.php
function find_pp_config(): ?string
{
    $start = __DIR__;
    for ($i = 0; $i < 8; $i++) {
        $root = dirname($start, $i + 1);
        $cfg = $root . '/pp-config.php';
        if (is_file($cfg) && is_readable($cfg)) {
            return realpath($cfg);
        }
    }
    return null;
}

// Find and include the configuration file
$config_path = find_pp_config();
if ($config_path === null) {
    die('Could not find pp-config.php file');
}

require_once $config_path;

// Try updating database
if (!isset($conn)) {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($conn->connect_error) {
        $error = "Connection failed: " . $conn->connect_error;
    }
    if (!$conn->query("SET NAMES utf8")) {
        $error = "Set names failed: " . $conn->error;
    }
    if (!empty($db_prefix)) {
        if (!$conn->query("SET sql_mode = ''")) {
            $error = "Set sql_mode failed: " . $conn->error;
        }
    }
}

// Get JSON input
$input = json_decode(file_get_contents("php://input"), true);
$auth_id = $input['auth_id'];
$data = $input['data'];

// First check auth_id validation
$sql = "SELECT * FROM {$db_prefix}plugins WHERE plugin_slug = '{$plugin_slug}'";
$result = $conn->query($sql);
if (!$result) {
    echo json_encode(["status" => "error", "message" => "Database error: " . $conn->error]);
    exit;
}

$row = $result->fetch_assoc();
if (!$row) {
    echo json_encode(["status" => "error", "message" => "Plugin not found"]);
    exit;
}

$plugin_array = json_decode($row['plugin_array'], true);
if (!$plugin_array || !isset($plugin_array['auth_id'])) {
    echo json_encode(["status" => "error", "message" => "Invalid plugin configuration"]);
    exit;
}

// Strict auth_id check
if ($plugin_array['auth_id'] !== $auth_id) {
    echo json_encode(["status" => "error", "message" => "Invalid auth_id"]);
    exit;
}

// Only proceed if auth is valid
if (!$data) {
    echo json_encode(["status" => "error", "message" => "No data received"]);
    exit;
}

// Prepare statement for safety
$stmt = $conn->prepare("INSERT INTO {$db_prefix}transaction (pp_id, c_id, c_name, c_email_mobile, payment_method_id, payment_method, payment_verify_way, payment_sender_number, payment_verify_id, transaction_amount, transaction_fee, transaction_refund_amount, transaction_refund_reason, transaction_currency, transaction_redirect_url, transaction_return_type, transaction_cancel_url, transaction_webhook_url, transaction_metadata, transaction_status, transaction_product_name, transaction_product_description, transaction_product_meta, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("ssssssssssssssssssssssss", $pp_id, $c_id, $c_name, $c_email_mobile, $payment_method_id, $payment_method, $payment_verify_way, $payment_sender_number, $payment_verify_id, $transaction_amount, $transaction_fee, $transaction_refund_amount, $transaction_refund_reason, $transaction_currency, $transaction_redirect_url, $transaction_return_type, $transaction_cancel_url, $transaction_webhook_url, $transaction_metadata, $transaction_status, $transaction_product_name, $transaction_product_description, $transaction_product_meta, $created_at);

$inserted = 0;
foreach ($data as $row) {
    $pp_id = $row["pp_id"];
    $c_id = $row["c_id"];
    $c_name = $row["c_name"];
    $c_email_mobile = $row["c_email_mobile"];
    $payment_method_id = $row["payment_method_id"];
    $payment_method = $row["payment_method"];
    $payment_verify_way = $row["payment_verify_way"];
    $payment_sender_number = $row["payment_sender_number"];
    $payment_verify_id = $row["payment_verify_id"];
    $transaction_amount = $row["transaction_amount"];
    $transaction_fee = $row["transaction_fee"];
    $transaction_refund_amount = $row["transaction_refund_amount"];
    $transaction_refund_reason = $row["transaction_refund_reason"];
    $transaction_currency = $row["transaction_currency"];
    $transaction_redirect_url = $row["transaction_redirect_url"];
    $transaction_return_type = $row["transaction_return_type"];
    $transaction_cancel_url = $row["transaction_cancel_url"];
    $transaction_webhook_url = $row["transaction_webhook_url"];
    $transaction_metadata = $row["transaction_metadata"];
    $transaction_status = $row["transaction_status"];
    $transaction_product_name = $row["transaction_product_name"];
    $transaction_product_description = $row["transaction_product_description"];
    $transaction_product_meta = $row["transaction_product_meta"];
    $created_at = $row["created_at"];

    if ($stmt->execute()) {
        $inserted++;
    }
}

if (isset($conn) && !$conn->connect_error) {
    $auth_id = '';
    $sql = "UPDATE {$db_prefix}plugins SET plugin_array = '{\"auth_id\":\"$auth_id\"}' WHERE plugin_slug = '{$plugin_slug}'";
    $result = $conn->query($sql);
}

$stmt->close();
$conn->close();

echo json_encode([
    "status" => "success",
    "inserted" => $inserted,
    "total" => count($data)
]);
