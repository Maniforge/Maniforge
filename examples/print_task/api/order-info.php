<?php

require dirname(__DIR__) . '/src/bootstrap.php';

$orderNomer = isset($_POST['post_data']) ? (int) $_POST['post_data'] : 0;
if ($orderNomer <= 0) {
    json_response(['error' => 'post_data required'], 400);
}

$data = order_service()->getOrderModalData($orderNomer);
if ($data === null) {
    json_response(['error' => 'Заказ не найден'], 404);
}

ob_start();
view('partials/order-modal', ['data' => $data]);
$html = ob_get_clean();

json_response(['html' => $html]);
