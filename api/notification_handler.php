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
$fraudStatus = isset($notification['fraud_status'])
    ? $notification['fraud_status']
    : '';

$pembayaranSukses = false;

if (
    $transactionStatus == 'settlement' ||
    ($transactionStatus == 'capture' && $fraudStatus == 'accept')
) {
    $pembayaranSukses = true;
}

if ($pembayaranSukses) {

    $firebaseDatabaseUrl =
        "https://umktfoodstep-27885-default-rtdb.asia-southeast1.firebasedatabase.app/";

    // =========================================================================
    // STEP 1: AMBIL DATA DARI PENDING PAYMENTS
    // =========================================================================

    $get_url =
        $firebaseDatabaseUrl .
        "pending_payments/" .
        $orderId .
        ".json";

    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $get_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $pendingDataJson = curl_exec($ch);

    curl_close($ch);

    $pendingData = json_decode($pendingDataJson, true);

    if ($pendingData && isset($pendingData['items'])) {

        $cartItems = $pendingData['items'];

        $userId =
            isset($pendingData['user_id'])
                ? $pendingData['user_id']
                : "USR-001";

        // =========================================================================
        // STEP 2: MASUKKAN DATA KE NODE /orders/
        // =========================================================================

        $get_orders_url =
            $firebaseDatabaseUrl . "orders.json";

        $ch_order = curl_init();

        curl_setopt($ch_order, CURLOPT_URL, $get_orders_url);
        curl_setopt($ch_order, CURLOPT_RETURNTRANSFER, true);

        $existOrdersJson = curl_exec($ch_order);

        curl_close($ch_order);

        $existOrders =
            json_decode($existOrdersJson, true);

        $nextOrderIndex = 0;

        if (is_array($existOrders)) {
            $nextOrderIndex = count($existOrders);
        }

        $newOrder = [
            $nextOrderIndex => [

                "id" => $orderId,

                "user_id" => $userId,

                "kantin_id" =>
                    (int)$pendingData['kantin_id'],

                "payment_method_id" => 1,

                "status" => "Diproses",

                "subtotal" =>
                    (int)$pendingData['subtotal'],

                "service_fee" =>
                    (int)$pendingData['service_fee'],

                "total" =>
                    (int)$pendingData['total'],

                "note" =>
                    isset($pendingData['note'])
                        ? $pendingData['note']
                        : "",

                "pickup_code" =>
                    $pendingData['pickup_code'],

                "ordered_at" =>
                    $pendingData['ordered_at'],

                "ready_at" => null,

                "completed_at" => null
            ]
        ];

        $put_orders_url =
            $firebaseDatabaseUrl . "orders.json";

        $ch_order_put = curl_init();

        curl_setopt($ch_order_put, CURLOPT_URL, $put_orders_url);
        curl_setopt($ch_order_put, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_order_put, CURLOPT_CUSTOMREQUEST, "PATCH");

        curl_setopt(
            $ch_order_put,
            CURLOPT_POSTFIELDS,
            json_encode($newOrder)
        );

        curl_setopt(
            $ch_order_put,
            CURLOPT_HTTPHEADER,
            ['Content-Type: application/json']
        );

        curl_exec($ch_order_put);

        curl_close($ch_order_put);

        // =========================================================================
        // STEP 3: MASUKKAN ITEM BELANJA KE NODE /order_items/
        // =========================================================================

        $get_items_url =
            $firebaseDatabaseUrl . "order_items.json";

        $ch_get = curl_init();

        curl_setopt($ch_get, CURLOPT_URL, $get_items_url);
        curl_setopt($ch_get, CURLOPT_RETURNTRANSFER, true);

        $existItemsJson = curl_exec($ch_get);

        curl_close($ch_get);

        $existItems =
            json_decode($existItemsJson, true);

        $nextIndex = 0;

        if (is_array($existItems)) {
            $nextIndex = count($existItems);
        }

        $itemsToSave = [];

        $currentIndex = $nextIndex;

        foreach ($cartItems as $item) {

            $itemsToSave[$currentIndex] = [

                "id" => $currentIndex + 1,

                "menu_item_id" =>
                    (int)$item['menu_item_id'],

                "name_snapshot" =>
                    $item['name_snapshot'],

                "order_id" => $orderId,

                "price_snapshot" =>
                    (int)$item['price_snapshot'],

                "quantity" =>
                    (int)$item['quantity'],

                "subtotal" =>
                    (int)$item['subtotal']
            ];

            $currentIndex++;
        }

        $put_items_url =
            $firebaseDatabaseUrl . "order_items.json";

        $ch_put = curl_init();

        curl_setopt($ch_put, CURLOPT_URL, $put_items_url);
        curl_setopt($ch_put, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_put, CURLOPT_CUSTOMREQUEST, "PATCH");

        curl_setopt(
            $ch_put,
            CURLOPT_POSTFIELDS,
            json_encode($itemsToSave)
        );

        curl_setopt(
            $ch_put,
            CURLOPT_HTTPHEADER,
            ['Content-Type: application/json']
        );

        curl_exec($ch_put);

        curl_close($ch_put);

        // =========================================================================
        // STEP 4: BUAT NOTIFIKASI BARU
        // =========================================================================

        $get_notif_url =
            $firebaseDatabaseUrl . "notifications.json";

        $ch_notif = curl_init();

        curl_setopt($ch_notif, CURLOPT_URL, $get_notif_url);
        curl_setopt($ch_notif, CURLOPT_RETURNTRANSFER, true);

        $existNotifJson = curl_exec($ch_notif);

        curl_close($ch_notif);

        $existNotif =
            json_decode($existNotifJson, true);

        $nextNotifIndex = 0;

        if (is_array($existNotif)) {
            $nextNotifIndex = count($existNotif);
        }

        $orderedAt =
            gmdate("Y-m-d\TH:i:s\Z");

        $newNotification = [

            $nextNotifIndex => [

                "id" =>
                    "NOTIF-" .
                    ($nextNotifIndex + 1),

                "order_id" => $orderId,

                "user_id" => $userId,

                "title" =>
                    "Pesanan Sedang Diproses",

                "body" =>
                    "Pesanan #" .
                    $orderId .
                    " sedang diproses oleh kantin.",

                "type" => "order_process",

                "is_read" => false,

                "created_at" => $orderedAt
            ]
        ];

        $put_notif_url =
            $firebaseDatabaseUrl . "notifications.json";

        $ch_notif_put = curl_init();

        curl_setopt($ch_notif_put, CURLOPT_URL, $put_notif_url);
        curl_setopt($ch_notif_put, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_notif_put, CURLOPT_CUSTOMREQUEST, "PATCH");

        curl_setopt(
            $ch_notif_put,
            CURLOPT_POSTFIELDS,
            json_encode($newNotification)
        );

        curl_setopt(
            $ch_notif_put,
            CURLOPT_HTTPHEADER,
            ['Content-Type: application/json']
        );

        curl_exec($ch_notif_put);

        curl_close($ch_notif_put);

        // =========================================================================
        // STEP 5: HAPUS DATA PENDING PAYMENT
        // =========================================================================

        $delete_url =
            $firebaseDatabaseUrl .
            "pending_payments/" .
            $orderId .
            ".json";

        $ch_del = curl_init();

        curl_setopt($ch_del, CURLOPT_URL, $delete_url);
        curl_setopt($ch_del, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_del, CURLOPT_CUSTOMREQUEST, "DELETE");

        curl_exec($ch_del);

        curl_close($ch_del);

        echo json_encode([
            "status" => "success",
            "message" =>
                "Pesanan $orderId sukses diproses!"
        ]);

    } else {

        echo json_encode([
            "status" => "error",
            "message" =>
                "Draft belanjaan tidak ditemukan."
        ]);
    }

} else {

    if (
        $transactionStatus == 'cancel' ||
        $transactionStatus == 'deny' ||
        $transactionStatus == 'expire'
    ) {

        $firebaseDatabaseUrl =
            "https://umktfoodstep-27885-default-rtdb.asia-southeast1.firebasedatabase.app/";

        $delete_url =
            $firebaseDatabaseUrl .
            "pending_payments/" .
            $orderId .
            ".json";

        $ch_del = curl_init();

        curl_setopt($ch_del, CURLOPT_URL, $delete_url);
        curl_setopt($ch_del, CURLOPT_CUSTOMREQUEST, "DELETE");

        curl_exec($ch_del);

        curl_close($ch_del);

        echo json_encode([
            "status" => "failed",
            "message" =>
                "Transaksi $orderId gagal/dibatalkan."
        ]);

    } else {

        echo json_encode([
            "status" => "ignored",
            "message" =>
                "Transaksi pending atau status tidak dikenal."
        ]);
    }
}
?>
