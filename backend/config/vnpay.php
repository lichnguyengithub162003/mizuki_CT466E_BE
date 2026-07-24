<?php

return [
    'tmn_code' => env('VNPAY_TMN_CODE'),
    'hash_secret' => env('VNPAY_HASH_SECRET'),
    'payment_url' => env(
        'VNPAY_PAYMENT_URL',
        'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html',
    ),
    'return_url' => env(
        'VNPAY_RETURN_URL',
        'http://localhost:5173/payment/vnpay/return',
    ),
    'ipn_url' => env(
        'VNPAY_IPN_URL',
        'http://localhost:8000/api/v1/payments/vnpay/ipn',
    ),
    'version' => env('VNPAY_VERSION', '2.1.0'),
    'command' => env('VNPAY_COMMAND', 'pay'),
    'order_type' => env('VNPAY_ORDER_TYPE', 'other'),
    'locale' => env('VNPAY_LOCALE', 'vn'),
    'currency' => env('VNPAY_CURRENCY', 'VND'),
    'expire_minutes' => (int) env('VNPAY_EXPIRE_MINUTES', 15),
    'timezone' => 'Asia/Ho_Chi_Minh',
];
