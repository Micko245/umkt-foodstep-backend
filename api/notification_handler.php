<?php

header("Content-Type: application/json");

$serverKey = "SB-Mid-server-H7PHMOW2JoVCx1cpm45RsNeQ";

$json = file_get_contents('php://input');
$notification = json_decode($json, true);

if (!$notification) {
    echo json_encode([
        "status" => "error",
        "message" => "Tidak ada data notifikasi masuk."
    ]);
    exit();
}

$transactionStatus = $notification['transaction_status'];
$orderId = $notification['order_id'];
$fraudStatus = isset($notification['fraud_status']) ? $notification['fraud_status'] : '';

$pembayaranSukses = false;

if (
    $transactionStatus == 'settlement' ||
    ($transactionStatus == 'capture' && $fraudStatus == 'accept')
) {
    $pembayaranSukses = true;
}

if ($pembayaranSukses) {

    $firebaseDatabaseUrl = "https://umktfoodstep-27885-default-rtdb.asia-southeast1.firebasedatabase.app/";

    // =========================================================================
    // STEP 1 : AMBIL DATA PENDING PAYMENT
    // =========================================================================
    $get_url = $firebaseDatabaseUrl . "pending_payments/" . $orderId . ".json";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $get_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $pendingDataJson = curl_exec($ch);
    curl_close($ch);

    $pendingData = json_decode($pendingDataJson, true);

    if ($pendingData && isset($pendingData['items'])) {

        $cartItems = $pendingData['items'];
        $userId = isset($pendingData['user_id']) ? $pendingData['user_id'] : "USR-001";
        $kantinId = isset($pendingData['kantin_id']) ? (int)$pendingData['kantin_id'] : 1;

        // =========================================================================
        // STEP 2 : SIMPAN ORDER (Sangat Bersih & Hanya Menyimpan ID Referensi)
        // =========================================================================
        $newOrder = [
            "id" => $orderId,
            "user_id" => $userId,
            "kantin_id" => $kantinId,
            "payment_method_id" => 1, // 🛠️ Langsung di-set ke 1 (Midtrans)
            "status" => "Diproses",
            "subtotal" => (int)$pendingData['subtotal'],
            "service_fee" => (int)$pendingData['service_fee'],
            "total" => (int)$pendingData['total'],
            "note" => isset($pendingData['note']) ? $pendingData['note'] : "",
            "pickup_code" => $pendingData['pickup_code'],
            "ordered_at" => $pendingData['ordered_at'],
            "ready_at" => null,
            "completed_at" => null
        ];

        $chOrder = curl_init();
        curl_setopt(
            $chOrder,
            CURLOPT_URL,
            $firebaseDatabaseUrl . "orders/" . $orderId . ".json"
        );
        curl_setopt($chOrder, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt(
            $chOrder,
            CURLOPT_POSTFIELDS,
            json_encode($newOrder)
        );
        curl_setopt(
            $chOrder,
            CURLOPT_HTTPHEADER,
            ['Content-Type: application/json']
        );
        curl_setopt($chOrder, CURLOPT_RETURNTRANSFER, true);
        curl_exec($chOrder);
        curl_close($chOrder);

        // =========================================================================
        // STEP 3 : SIMPAN ORDER ITEMS
        // =========================================================================
        $itemCounter = 1;
        foreach ($cartItems as $item) {

            $singleItem = [
                "id" => $itemCounter,
                "menu_item_id" => (int)$item['menu_item_id'],
                "name_snapshot" => $item['name_snapshot'],
                "order_id" => $orderId,
                "price_snapshot" => (int)$item['price_snapshot'],
                "quantity" => (int)$item['quantity'],
                "subtotal" => (int)$item['subtotal']
            ];

            $chItem = curl_init();
            curl_setopt(
                $chItem,
                CURLOPT_URL,
                $firebaseDatabaseUrl . "order_items/" . $orderId . "_item_" . $itemCounter . ".json"
            );
            curl_setopt($chItem, CURLOPT_CUSTOMREQUEST, "PUT");
            curl_setopt(
                $chItem,
                CURLOPT_POSTFIELDS,
                json_encode($singleItem)
            );
            curl_setopt(
                $chItem,
                CURLOPT_HTTPHEADER,
                ['Content-Type: application/json']
            );
            curl_setopt($chItem, CURLOPT_RETURNTRANSFER, true);
            curl_exec($chItem);
            curl_close($chItem);

            $itemCounter++;
        }

        // =========================================================================
        // STEP 4 : BUAT NOTIFIKASI
        // =========================================================================
        $orderedAt = gmdate("Y-m-d\TH:i:s\Z");
        $notifId = "NOTIF-" . time();

        $newNotification = [
            "id" => $notifId,
            "user_id" => $userId,
            "order_id" => $orderId,
            "type" => "order_process",
            "title" => "Pesanan Sedang Diproses",
            "body" => "Pesanan #" . $orderId . " sedang diproses oleh kantin.",
            "is_read" => false,
            "created_at" => $orderedAt
        ];

        $chNotif = curl_init();
        curl_setopt(
            $chNotif,
            CURLOPT_URL,
            $firebaseDatabaseUrl . "notifications/" . $notifId . ".json"
        );
        curl_setopt($chNotif, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt(
            $chNotif,
            CURLOPT_POSTFIELDS,
            json_encode($newNotification)
        );
        curl_setopt(
            $chNotif,
            CURLOPT_HTTPHEADER,
            ['Content-Type: application/json']
        );
        curl_setopt($chNotif, CURLOPT_RETURNTRANSFER, true);
        curl_exec($chNotif);
        curl_close($chNotif);

        // =========================================================================
        // STEP 5 : HAPUS PENDING PAYMENT
        // =========================================================================
        $delete_url = $firebaseDatabaseUrl . "pending_payments/" . $orderId . ".json";

        $chDel = curl_init();
        curl_setopt($chDel, CURLOPT_URL, $delete_url);
        curl_setopt($chDel, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_setopt($chDel, CURLOPT_RETURNTRANSFER, true);
        curl_exec($chDel);
        curl_close($chDel);

        echo json_encode([
            "status" => "success",
            "message" => "Pesanan berhasil diproses dengan struktur minimalis."
        ]);

    } else {
        echo json_encode([
            "status" => "error",
            "message" => "Pending payment tidak ditemukan atau struktur rusak."
        ]);
    }

} else {

    if (
        $transactionStatus == 'cancel' ||
        $transactionStatus == 'deny' ||
        $transactionStatus == 'expire'
    ) {

        $firebaseDatabaseUrl = "https://umktfoodstep-27885-default-rtdb.asia-southeast1.firebasedatabase.app/";
        $delete_url = $firebaseDatabaseUrl . "pending_payments/" . $orderId . ".json";

        $chDel = curl_init();
        curl_setopt($chDel, CURLOPT_URL, $delete_url);
        curl_setopt($chDel, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_setopt($chDel, CURLOPT_RETURNTRANSFER, true);
        curl_exec($chDel);
        curl_close($chDel);

        echo json_encode([
            "status" => "failed",
            "message" => "Pembayaran gagal / dibatalkan."
        ]);

    } else {
        echo json_encode([
            "status" => "ignored",
            "message" => "Status transaksi tidak diproses."
        ]);
    }
}
?>
