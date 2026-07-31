<?php

return [
    'quote_ttl_minutes' => (int) env('SHIPPING_QUOTE_TTL_MINUTES', 10),

    'package' => [
        // Product dimensions are not stored yet; these defaults describe one normal parcel.
        'default_length_cm' => 20,
        'default_width_cm' => 15,
        'default_height_cm' => 10,
        'max_dimension_cm' => 200,
        'max_weight_grams' => 30_000,
        'max_insurance_value' => 5_000_000,
    ],
];
