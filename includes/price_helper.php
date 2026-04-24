<?php

/**
 * Price Helper - Handles tax-inclusive pricing
 */

function displayCustomerPrice($basePrice)
{
    $taxRate = getTaxRate();
    $total = $basePrice * (1 + $taxRate / 100);
    return formatPrice($total);
}

function getCustomerTotal($basePrice, $nights = 1)
{
    $taxRate = getTaxRate();
    $subtotal = $basePrice * $nights;
    $total = $subtotal * (1 + $taxRate / 100);
    return $total;
}

function getPriceBreakdown($basePrice, $nights = 1)
{
    $taxRate = getTaxRate();
    $subtotal = $basePrice * $nights;
    $taxAmount = $subtotal * ($taxRate / 100);
    $total = $subtotal + $taxAmount;

    return [
        'subtotal' => $subtotal,
        'tax_rate' => $taxRate,
        'tax_amount' => $taxAmount,
        'total' => $total,
        'per_night_with_tax' => $basePrice * (1 + $taxRate / 100)
    ];
}
