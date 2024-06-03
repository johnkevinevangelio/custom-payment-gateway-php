<?php



add_filter('woocommerce_gateway_description', 'barneys_description', 20, 2);


function barneys_description ($description, $payment_id) {


    if ('barneys' != $payment_id) {
        return $description;
    }
    ob_start();

    echo 'test';
    $description .= ob_get_clean();

    return $description;

}





