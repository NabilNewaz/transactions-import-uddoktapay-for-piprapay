<?php

header("Content-Type: application/json");

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
$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    echo json_encode(["status" => "error", "message" => "No data received"]);
    exit;
}

// Check if create=true parameter is set
if (isset($conn) && !$conn->connect_error) {
    // SQL to create the payment table
    $sql = "CREATE TABLE IF NOT EXISTS {$db_prefix}payment (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pp_id VARCHAR(755),
        c_id VARCHAR(255),
        c_name VARCHAR(255),
        c_email_mobile VARCHAR(255),
        payment_method_id VARCHAR(255),
        payment_method VARCHAR(255),
        payment_verify_way VARCHAR(255),
        payment_sender_number VARCHAR(255),
        payment_verify_id VARCHAR(255),
        transaction_amount VARCHAR(255),
        transaction_fee VARCHAR(255),
        transaction_refund_amount VARCHAR(755),
        transaction_refund_reason VARCHAR(755),
        transaction_currency VARCHAR(755),
        transaction_redirect_url VARCHAR(755),
        transaction_return_type VARCHAR(155),
        transaction_cancel_url VARCHAR(755),
        transaction_webhook_url VARCHAR(755),
        transaction_metadata VARCHAR(755),
        transaction_status VARCHAR(755),
        transaction_product_name VARCHAR(255),
        transaction_product_description VARCHAR(755),
        transaction_product_meta VARCHAR(1755),
        created_at VARCHAR(255)
    )";

    try {
        if ($conn->query($sql)) {
            $message = '<div class="alert alert-success">Payment table created successfully!</div>';
        } else {
            $message = '<div class="alert alert-danger">Error creating payment table: ' . $conn->error . '</div>';
        }
    } catch (Exception $e) {
        $message = '<div class="alert alert-danger">Error: ' . $e->getMessage() . '</div>';
    }
}

// Prepare statement for safety
$stmt = $conn->prepare("INSERT INTO {$db_prefix}payment (pp_id, c_id, c_name, c_email_mobile, payment_method_id, payment_method, payment_verify_way, payment_sender_number, payment_verify_id, transaction_amount, transaction_fee, transaction_refund_amount, transaction_refund_reason, transaction_currency, transaction_redirect_url, transaction_return_type, transaction_cancel_url, transaction_webhook_url, transaction_metadata, transaction_status, transaction_product_name, transaction_product_description, transaction_product_meta, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
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

$stmt->close();
$conn->close();

echo json_encode([
    "status" => "success",
    "inserted" => $inserted,
    "total" => count($data)
]);
