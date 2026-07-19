<?php

return [
    /*
    | Platform commission taken from each sub-order on payment.
    | "0.10" = 10%. Kept as a string — brick/math needs exact decimals, not floats.
    | Admin-configurable later; a config value for now.
    */
    'commission_rate' => (string) env('BAZAAR_COMMISSION_RATE', '0.10'),
];
