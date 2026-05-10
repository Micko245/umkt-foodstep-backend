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
    echo json_encode([
        "status" => "error", 
        "token" => "", 
        "message" => "Parameter order_id atau total_price tidak lengkap."
    ]);
    exit();
}

$orderId = $data['order_id'];
$totalPrice = (int)$data['total_price'];

// Validasi nominal transaksi tidak boleh 0 atau minus
if ($totalPrice <= 0) {
    echo json_encode([
        "status" => "error",
        "token" => "",
        "message" => "Nominal total_price tidak valid ($totalPrice)."
    ]);
    exit();
}

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
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// JIKA terjadi error HTTP dari Midtrans (misal Server Key Salah, responnya kode 401 atau 400)
if ($httpCode !== 201 && $httpCode !== 200) {
    $errorMsg = json_decode($response, true);
    $detailError = isset($errorMsg['error_messages'][0]) ? $errorMsg['error_messages'][0] : "Otorisasi ditolak / Parameter salah.";
    
    echo json_encode([
        "status" => "error",
        "token" => "",
        "message" => "Midtrans Error ($httpCode): " . $detailError
    ]);
} else {
    // Sukses, kembalikan response token asli dari Midtrans ke Android
    echo $response;
}
?>
