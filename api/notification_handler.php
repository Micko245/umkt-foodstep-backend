<?php
header("Content-Type: application/json");

// Menggunakan Server Key milikmu
$serverKey = "SB-Mid-server-H7PHMOW2JoVCx1cpm45RsNeQ";

$json = file_get_contents('php://input');
$notification = json_decode($json, true);

if (!$notification) {
    echo json_encode(["status" => "error", "message" => "Tidak ada data notifikasi masuk."]);
    exit();
}

$transactionStatus = $notification['transaction_status'];
$orderId = $notification['order_id'];
$fraudStatus = isset($notification['fraud_status']) ? $notification['fraud_status'] : '';

$statusBaru = "";

// Cek status transaksi dari Midtrans
if ($transactionStatus == 'capture') {
    if ($fraudStatus == 'challenge') {
        $statusBaru = "Belum Bayar";
    } else if ($fraudStatus == 'accept') {
        $statusBaru = "Sedang Diproses";
    }
} else if ($transactionStatus == 'settlement') {
    $statusBaru = "Sedang Diproses"; // Pembayaran QRIS/Gopay/Transfer VA sukses!
} else if ($transactionStatus == 'pending') {
    $statusBaru = "Belum Bayar";
} else if (in_array($transactionStatus, ['deny', 'expire', 'cancel'])) {
    $statusBaru = "Gagal";
}

if (!empty($statusBaru)) {
    // Menghubungkan ke Firebase Realtime Database punyamu
    $firebaseDatabaseUrl = "https://umktfoodstep-27885-default-rtdb.asia-southeast1.firebasedatabase.app/";
    $firebaseUrl = $firebaseDatabaseUrl . "orders/" . $orderId . "/status.json";
    
    // Kirim pembaruan status ke Firebase menggunakan REST API (PUT)
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $firebaseUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($statusBaru));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode == 200) {
        echo json_encode(["status" => "success", "message" => "Status pesanan $orderId berhasil di-update ke: $statusBaru"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Gagal mengupdate database Firebase."]);
    }
} else {
    echo json_encode(["status" => "ignored", "message" => "Status diabaikan."]);
}
?>