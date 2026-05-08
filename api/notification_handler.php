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
$orderId = $notification['order_id']; // ID pesanan, contoh: FS-1778261170328
$fraudStatus = isset($notification['fraud_status']) ? $notification['fraud_status'] : '';

$pembayaranSukses = false;
if ($transactionStatus == 'settlement' || ($transactionStatus == 'capture' && $fraudStatus == 'accept')) {
    $pembayaranSukses = true;
}

if ($pembayaranSukses) {
    $firebaseDatabaseUrl = "https://umktfoodstep-27885-default-rtdb.asia-southeast1.firebasedatabase.app/";
    
    // =========================================================================
    // STEP 1: AMBIL DATA DARI PENDING PAYMENTS (DRAFT BELANJAAN DARI HP)
    // =========================================================================
    $get_url = $firebaseDatabaseUrl . "pending_payments/" . $orderId . ".json";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $get_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $pendingDataJson = curl_exec($ch);
    curl_close($ch);
    
    $pendingData = json_decode($pendingDataJson, true);
    
    if ($pendingData && isset($pendingData['items'])) {
        $cartItems = $pendingData['items']; // Ini daftar item belanja yang dikirim dari HP
        
        // =========================================================================
        // STEP 2: MASUKKAN ITEM BELANJA KE NODE /order_items/ MENGGUNAKAN INDEX URUT
        // =========================================================================
        
        // Ambil data order_items yang sudah ada terlebih dahulu untuk menentukan indeks berikutnya
        $get_items_url = $firebaseDatabaseUrl . "order_items.json";
        $ch_get = curl_init();
        curl_setopt($ch_get, CURLOPT_URL, $get_items_url);
        curl_setopt($ch_get, CURLOPT_RETURNTRANSFER, true);
        $existItemsJson = curl_exec($ch_get);
        curl_close($ch_get);
        
        $existItems = json_decode($existItemsJson, true);
        $nextIndex = 0;
        if (is_array($existItems)) {
            $nextIndex = count($existItems); // Menentukan indeks array kelanjutan (0, 1, 2...)
        }
        
        $itemsToSave = [];
        $currentIndex = $nextIndex;
        
        foreach ($cartItems as $item) {
            $itemsToSave[$currentIndex] = [
                "id" => $currentIndex + 1, // ID Int unik urut (seperti gambar 2)
                "menu_item_id" => (int)$item['menu_item_id'],
                "name_snapshot" => $item['name_snapshot'],
                "order_id" => $orderId,
                "price_snapshot" => (int)$item['price_snapshot'],
                "quantity" => (int)$item['quantity'],
                "subtotal" => (int)$item['subtotal']
            ];
            $currentIndex++;
        }
        
        // Push semua item baru ini ke node /order_items
        $put_items_url = $firebaseDatabaseUrl . "order_items.json";
        $ch_put = curl_init();
        curl_setopt($ch_put, CURLOPT_URL, $put_items_url);
        curl_setopt($ch_put, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_put, CURLOPT_CUSTOMREQUEST, "PATCH"); // Menggunakan PATCH agar tidak menimpa item lama
        curl_setopt($ch_put, CURLOPT_POSTFIELDS, json_encode($itemsToSave));
        curl_setopt($ch_put, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_exec($ch_put);
        curl_close($ch_put);
        
        // =========================================================================
        // STEP 3: BUAT NOTIFIKASI BARU UNTUK USER DI NODE /notifications/
        // =========================================================================
        
        // Ambil data notification yang ada untuk menentukan indeks urut berikutnya
        $get_notif_url = $firebaseDatabaseUrl . "notifications.json";
        $ch_notif = curl_init();
        curl_setopt($ch_notif, CURLOPT_URL, $get_notif_url);
        curl_setopt($ch_notif, CURLOPT_RETURNTRANSFER, true);
        $existNotifJson = curl_exec($ch_notif);
        curl_close($ch_notif);
        
        $existNotif = json_decode($existNotifJson, true);
        $nextNotifIndex = 0;
        if (is_array($existNotif)) {
            $nextNotifIndex = count($existNotif);
        }
        
        $orderedAt = gmdate("Y-m-d\TH:i:s\Z"); // format timestamp ISO UTC
        $userId = isset($pendingData['user_id']) ? $pendingData['user_id'] : "USR-001";
        
        // Struktur Notifikasi sesuai Gambar 3
        $newNotification = [
            $nextNotifIndex => [
                "id" => "NOTIF-" . ($nextNotifIndex + 1),
                "order_id" => $orderId,
                "user_id" => $userId,
                "title" => "Pesanan Sedang Diproses",
                "body" => "Pesanan #" . $orderId . " sedang diproses oleh kantin.",
                "type" => "order_process",
                "is_read" => false,
                "created_at" => $orderedAt
            ]
        ];
        
        // Push notifikasi ke node /notifications
        $put_notif_url = $firebaseDatabaseUrl . "notifications.json";
        $ch_notif_put = curl_init();
        curl_setopt($ch_notif_put, CURLOPT_URL, $put_notif_url);
        curl_setopt($ch_notif_put, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_notif_put, CURLOPT_CUSTOMREQUEST, "PATCH");
        curl_setopt($ch_notif_put, CURLOPT_POSTFIELDS, json_encode($newNotification));
        curl_setopt($ch_notif_put, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_exec($ch_notif_put);
        curl_close($ch_notif_put);
        
        // =========================================================================
        // STEP 4: HAPUS DATA SEMENTARA DI /pending_payments/ AGAR BERSIH
        // =========================================================================
        $delete_url = $firebaseDatabaseUrl . "pending_payments/" . $orderId . ".json";
        $ch_del = curl_init();
        curl_setopt($ch_del, CURLOPT_URL, $delete_url);
        curl_setopt($ch_del, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_del, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_exec($ch_del);
        curl_close($ch_del);
        
        echo json_encode([
            "status" => "success", 
            "message" => "Pesanan $orderId sukses masuk antrean kantin & notifikasi terkirim!"
        ]);
    } else {
        echo json_encode([
            "status" => "error", 
            "message" => "Draft belanjaan di pending_payments tidak valid atau tidak ditemukan."
        ]);
    }
} else {
    // Jika transaksi gagal / expired / cancel oleh user
    if ($transactionStatus == 'cancel' || $transactionStatus == 'deny' || $transactionStatus == 'expire') {
        $firebaseDatabaseUrl = "https://umktfoodstep-27885-default-rtdb.asia-southeast1.firebasedatabase.app/";
        
        // Cukup hapus draf dari pending_payments karena belum diproses ke kantin
        $delete_url = $firebaseDatabaseUrl . "pending_payments/" . $orderId . ".json";
        $ch_del = curl_init();
        curl_setopt($ch_del, CURLOPT_URL, $delete_url);
        curl_setopt($ch_del, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_exec($ch_del);
        curl_close($ch_del);

        echo json_encode(["status" => "failed", "message" => "Transaksi $orderId gagal/dibatalkan. Draft dihapus."]);
    } else {
        echo json_encode(["status" => "ignored", "message" => "Transaksi pending atau status tidak dikenal."]);
    }
}
?>
