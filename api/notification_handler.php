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

// 1. Validasi status pembayaran sukses dari Midtrans
$pembayaranSukses = false;
if ($transactionStatus == 'settlement' || ($transactionStatus == 'capture' && $fraudStatus == 'accept')) {
    $pembayaranSukses = true;
}

if ($pembayaranSukses) {
    $firebaseDatabaseUrl = "https://umktfoodstep-27885-default-rtdb.asia-southeast1.firebasedatabase.app/";
    
    // SESUAI HP ANDROID: Kita cari dan update langsung data di node /order_items/
    $get_url = $firebaseDatabaseUrl . "order_items/" . $orderId . ".json";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $get_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $orderDataJson = curl_exec($ch);
    curl_close($ch);
    
    $orderData = json_decode($orderDataJson, true);
    
    if ($orderData) {
        // 2. Ubah status pesanan di Firebase menjadi "Sedang Diproses" (atau "Sudah Bayar" sesuai kebutuhan sistemmu)
        $orderData['status'] = "Sedang Diproses";
        
        // 3. Simpan kembali data yang sudah di-update ke dalam node /order_items/ ID tersebut
        $put_url = $firebaseDatabaseUrl . "order_items/" . $orderId . ".json";
        $ch2 = curl_init();
        curl_setopt($ch2, CURLOPT_URL, $put_url);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode($orderData));
        curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_exec($ch2);
        curl_close($ch2);
        
        echo json_encode([
            "status" => "success", 
            "message" => "Pesanan $orderId berhasil diperbarui di /order_items/ dengan status Sedang Diproses."
        ]);
    } else {
        echo json_encode([
            "status" => "error", 
            "message" => "Data order $orderId tidak ditemukan di node order_items."
        ]);
    }
} else {
    // Menangani kondisi jika transaksi digagalkan/expired/dibatalkan oleh pengguna
    if ($transactionStatus == 'cancel' || $transactionStatus == 'deny' || $transactionStatus == 'expire') {
        $firebaseDatabaseUrl = "https://umktfoodstep-27885-default-rtdb.asia-southeast1.firebasedatabase.app/";
        $delete_url = $firebaseDatabaseUrl . "order_items/" . $orderId . ".json";
        
        // Hapus draf transaksi gagal dari database agar tidak menumpuk sampah pesanan
        $ch3 = curl_init();
        curl_setopt($ch3, CURLOPT_URL, $delete_url);
        curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch3, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_exec($ch3);
        curl_close($ch3);

        echo json_encode(["status" => "failed", "message" => "Transaksi $orderId gagal/dibatalkan. Data dihapus dari database."]);
    } else {
        echo json_encode(["status" => "ignored", "message" => "Transaksi pending atau status tidak dikenal."]);
    }
}
?>
