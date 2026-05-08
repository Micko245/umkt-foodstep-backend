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
$orderId = $notification['order_id']; // Mengambil ID pesanan (misal: FS-xxxx)
$fraudStatus = isset($notification['fraud_status']) ? $notification['fraud_status'] : '';

$pembayaranSukses = false;
if ($transactionStatus == 'settlement' || ($transactionStatus == 'capture' && $fraudStatus == 'accept')) {
    $pembayaranSukses = true;
}

if ($pembayaranSukses) {
    $firebaseDatabaseUrl = "https://umktfoodstep-27885-default-rtdb.asia-southeast1.firebasedatabase.app/";
    
    // 1. Ambil data transaksi sementara dari pending_payments
    $get_url = $firebaseDatabaseUrl . "pending_payments/" . $orderId . ".json";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $get_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $orderDataJson = curl_exec($ch);
    curl_close($ch);
    
    $orderData = json_decode($orderDataJson, true);
    
    if ($orderData) {
        // Update status sesuai alur sistemmu
        $orderData['status'] = "Sedang Diproses";
        
        // 2. Pindahkan dan tulis ke simpul utama /orders/FS-xxxx
        $put_url = $firebaseDatabaseUrl . "orders/" . $orderId . ".json";
        $ch2 = curl_init();
        curl_setopt($ch2, CURLOPT_URL, $put_url);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode($orderData));
        curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_exec($ch2);
        curl_close($ch2);
        
        // 3. Hapus data dari simpul sementara /pending_payments/FS-xxxx
        $delete_url = $firebaseDatabaseUrl . "pending_payments/" . $orderId . ".json";
        $ch3 = curl_init();
        curl_setopt($ch3, CURLOPT_URL, $delete_url);
        curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch3, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_exec($ch3);
        curl_close($ch3);
        
        echo json_encode([
            "status" => "success", 
            "message" => "Pesanan $orderId berhasil dipindahkan ke /orders/ dengan status Sedang Diproses."
        ]);
    } else {
        echo json_encode([
            "status" => "error", 
            "message" => "Data draf di pending_payments tidak ditemukan."
        ]);
    }
} else {
    // Jika transaksi gagal, kedaluwarsa, atau dibatalkan
    if ($transactionStatus == 'cancel' || $transactionStatus == 'deny' || $transactionStatus == 'expire') {
        $firebaseDatabaseUrl = "https://umktfoodstep-27885-default-rtdb.asia-southeast1.firebasedatabase.app/";
        
        // 1. Hapus dari pending_payments
        $delete_pending_url = $firebaseDatabaseUrl . "pending_payments/" . $orderId . ".json";
        $ch_del1 = curl_init();
        curl_setopt($ch_del1, CURLOPT_URL, $delete_pending_url);
        curl_setopt($ch_del1, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_exec($ch_del1);
        curl_close($ch_del1);

        // 2. Cari dan hapus item belanja yang terkait di /order_items/
        $get_items_url = $firebaseDatabaseUrl . "order_items.json";
        $ch_get = curl_init();
        curl_setopt($ch_get, CURLOPT_URL, $get_items_url);
        curl_setopt($ch_get, CURLOPT_RETURNTRANSFER, true);
        $allItemsJson = curl_exec($ch_get);
        curl_close($ch_get);

        $allItems = json_decode($allItemsJson, true);
        if ($allItems) {
            foreach ($allItems as $key => $item) {
                if (isset($item['order_id']) && $item['order_id'] == $orderId) {
                    $delete_item_url = $firebaseDatabaseUrl . "order_items/" . $key . ".json";
                    $ch_del2 = curl_init();
                    curl_setopt($ch_del2, CURLOPT_URL, $delete_item_url);
                    curl_setopt($ch_del2, CURLOPT_CUSTOMREQUEST, "DELETE");
                    curl_exec($ch_del2);
                    curl_close($ch_del2);
                }
            }
        }
        echo json_encode(["status" => "failed", "message" => "Transaksi $orderId dibatalkan. Draf dihapus."]);
    } else {
        echo json_encode(["status" => "ignored", "message" => "Transaksi pending atau tidak dikenal."]);
    }
}
?>
