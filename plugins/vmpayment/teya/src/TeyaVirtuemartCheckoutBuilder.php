<?php
/**
 * @package    Teya Payments for VirtueMart
 * @license    GNU General Public License version 3; see LICENSE.txt
 */
defined('_JEXEC') or die('Restricted access');

final class TeyaVirtuemartCheckoutBuilder
{
    public static function build($cart, array $order, $method, array $urls)
    {
        $details = $order['details']['BT'];
        $currency = self::currencyCode($method, $details);
        $amount = isset($details->order_total) ? $details->order_total : (isset($cart->cartPrices['billTotal']) ? $cart->cartPrices['billTotal'] : 0);
        if ($currency === 'HUF' && abs((float) $amount - round((float) $amount)) > 0.000001) {
            throw new RuntimeException('Teya requires whole HUF amounts. Configure VirtueMart to round the final order total to whole forints before payment.');
        }
        $payload = array(
            'amount' => array(
                'currency' => $currency,
                'value' => TeyaVirtuemartUtils::toMinorUnits($amount, $currency),
            ),
            'type' => 'SALE',
            'merchant_reference' => 'joomla_virtuemart#' . $details->order_number,
            'success_url' => $urls['success_url'],
            'failure_url' => $urls['failure_url'],
            'cancel_url' => $urls['cancel_url'],
            'line_items' => self::lineItems($order, $amount, $currency, !empty($method->total_line_item)),
            'language' => TeyaVirtuemartUtils::checkoutLanguage(),
            'post_success_payment' => isset($method->post_success_payment) && $method->post_success_payment === 'show_success_page' ? 'SHOW_SUCCESS_PAGE' : 'REDIRECT',
        );
        $customer = self::customer($details);
        if (!empty($customer)) {
            $payload['customer'] = (object) $customer;
            if (!empty($method->customer_success_email) && !empty($customer['email'])) {
                $payload['customer_success_email'] = $customer['email'];
            }
        }
        $billingAddress = self::billingAddress($details);
        if (!empty($billingAddress)) {
            $payload['billing_address'] = (object) $billingAddress;
        }
        if (!empty($method->merchant_success_email)) {
            $payload['merchant_success_email'] = $method->merchant_success_email;
        }
        return $payload;
    }

    public static function currencyCode($method, $details)
    {
        // Totals and item prices use order_currency, not the configured payment currency.
        $currencyId = isset($details->order_currency) ? $details->order_currency : 0;
        if (is_string($currencyId) && preg_match('/^[A-Za-z]{3}$/D', $currencyId)) {
            return strtoupper($currencyId);
        }
        if ($currencyId && class_exists('shopFunctions')) {
            $currency = shopFunctions::getCurrencyByID($currencyId, 'currency_code_3');
            if ($currency) {
                return $currency;
            }
        }
        throw new RuntimeException('Unable to determine the order currency.');
    }

    private static function customer($details)
    {
        $name = trim(self::scalar(@$details->first_name) . ' ' . self::scalar(@$details->last_name));
        return array_filter(array('name' => $name, 'email' => self::scalar(@$details->email)));
    }

    private static function scalar($value)
    {
        if (is_array($value)) {
            $value = reset($value);
        }
        if (is_object($value)) {
            if (isset($value->value)) {
                $value = $value->value;
            } elseif (method_exists($value, '__toString')) {
                $value = (string) $value;
            } else {
                return '';
            }
        }
        return trim((string) $value);
    }

    private static function billingAddress($details)
    {
        $country = '';
        if (!empty($details->virtuemart_country_id) && class_exists('VirtueMartModelCountry')) {
            $country = VirtueMartModelCountry::getCountryFieldByID($details->virtuemart_country_id, 'country_2_code');
        }
        return array_filter(array(
            'street_address_line1' => self::scalar(@$details->address_1),
            'street_address_line2' => self::scalar(@$details->address_2),
            'city' => self::scalar(@$details->city),
            'state' => self::scalar(@$details->virtuemart_state_id),
            'zipcode' => self::scalar(@$details->zip),
            'country' => self::scalar($country),
        ));
    }

    private static function lineItems(array $order, $amount, $currency, $singleLine)
    {
        $details = $order['details']['BT'];
        if ($singleLine || empty($order['items'])) {
            return array(array(
                'description' => TeyaVirtuemartUtils::truncateDescription('Order ' . $details->order_number),
                'quantity' => 1,
                'unit_price' => TeyaVirtuemartUtils::toMinorUnits($amount, $currency),
            ));
        }
        $items = array();
        foreach ($order['items'] as $item) {
            $quantity = isset($item->product_quantity) ? (int) $item->product_quantity : 1;
            if ($quantity < 1) {
                continue;
            }
            $unitPrice = isset($item->product_final_price) ? $item->product_final_price : (isset($item->product_item_price) ? $item->product_item_price : 0);
            $items[] = array(
                'description' => TeyaVirtuemartUtils::truncateDescription(isset($item->order_item_name) ? $item->order_item_name : @$item->product_name),
                'quantity' => $quantity,
                'unit_price' => TeyaVirtuemartUtils::toMinorUnits($unitPrice, $currency),
            );
        }
        if (empty($items)) {
            $items[] = array(
                'description' => TeyaVirtuemartUtils::truncateDescription('Order ' . $details->order_number),
                'quantity' => 1,
                'unit_price' => TeyaVirtuemartUtils::toMinorUnits($amount, $currency),
            );
        }
        $total = TeyaVirtuemartUtils::toMinorUnits($amount, $currency);
        $itemsTotal = array_sum(array_map(function ($item) {
            return $item['quantity'] * $item['unit_price'];
        }, $items));
        if ($itemsTotal !== $total) {
            $items[] = array('description' => 'Shipping, fees, discounts and rounding', 'quantity' => 1, 'unit_price' => $total - $itemsTotal);
        }
        return $items;
    }
}
