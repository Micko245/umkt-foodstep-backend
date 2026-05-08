<?php
header("Content-Type: application/json");

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

// Kita hanya memproses jika status pembayaran benar-benar sukses (settlement atau capture accept)
$pembayaranSukses = false;
if ($transactionStatus == 'settlement' || ($transactionStatus == 'capture' && $fraudStatus == 'accept')) {
    $pembayaranSukses = true;
}

if ($pembayaranSukses) {
    $firebaseDatabaseUrl = "https://umktfoodstep-27885-default-rtdb.asia-southeast1.firebasedatabase.app/";
    
    // 1. Ambil data pesanan dari simpul sementara /pending_payments/
    $get_url = $firebaseDatabaseUrl . "pending_payments/" . $orderId . ".json";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $get_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $orderDataJson = curl_exec($ch);
    curl_close($ch);
    
    $orderData = json_decode($orderDataJson, true);
    
    if ($orderData) {
        // Update status data pesanan tersebut langsung menjadi "Sedang Diproses"
        $orderData['status'] = "Sedang Diproses";
        
        // 2. Tulis data pesanan tersebut ke simpul utama /orders/ (Daftar kerja Penjual)
        $put_url = $firebaseDatabaseUrl . "orders/" . $orderId . ".json";
        $ch2 = curl_init();
        curl_setopt($ch2, CURLOPT_URL, $put_url);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode($orderData));
        curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_exec($ch2);
        curl_close($ch2);
        
        // 3. Hapus data pesanan dari simpul sementara /pending_payments/ agar bersih
        $delete_url = $firebaseDatabaseUrl . "pending_payments/" . $orderId . ".json";
        $ch3 = curl_init();
        curl_setopt($ch3, CURLOPT_URL, $delete_url);
        curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch3, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_exec($ch3);
        curl_close($ch3);
        
        echo json_encode(["status" => "success", "message" => "Pesanan $orderId sukses dipindahkan ke /orders/ dengan status Sedang Diproses."]);
    } else {
        echo json_encode(["status" => "error", "message" => "Data di pending_payments tidak ditemukan."]);
    }
} else {
    echo json_encode(["status" => "ignored", "message" => "Transaksi belum sukses / pending."]);
}
?>
