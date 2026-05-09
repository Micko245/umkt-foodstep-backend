<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Menggunakan Server Key milikmu
$serverKey = "SB-Mid-server-H7PHMOW2JoVCx1cpm45RsNeQ";

$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!isset($data['order_id']) || !isset($data['total_price'])) {
    echo json_encode(["status" => "error", "message" => "Parameter order_id atau total_price tidak lengkap."]);
    exit();
}

$orderId = $data['order_id'];
$totalPrice = (int)$data['total_price'];

// 1. Detail Transaksi Utama
$transaction_details = [
    'order_id' => $orderId,
    'gross_amount' => $totalPrice,
];

// 2. Detail Pelanggan (Formalitas)
$customer_details = [
    'first_name' => "Mahasiswa UMKT",
    'email' => "mahasiswa@umkt.ac.id"
];

// SOLUSI: Hapus callbacks agar SDK kembali ke pengaturan default native Android
$transaction_data = [
    'transaction_details' => $transaction_details,
    'customer_details' => $customer_details
];

$payload = json_encode($transaction_data);

// Request ke server Midtrans Sandbox
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://app.sandbox.midtrans.com/snap/v1/transactions");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
curl_setopt($ch, CURLOPT_HEADER, FALSE);
curl_setopt($ch, CURLOPT_POST, TRUE);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Accept: application/json",
    "Authorization: Basic " . base64_encode($serverKey . ":")
]);

$response = curl_exec($ch);
curl_close($ch);

// Kembalikan token ke Android
echo $response;
?>
