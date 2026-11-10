<?php
/**
 * @package    Teya Payments for VirtueMart
 * @license    GNU General Public License version 3; see LICENSE.txt
 */
defined('_JEXEC') or die('Restricted access');

/** Durable refund ledger, stored in the existing refund response column. */
final class TeyaVirtuemartRefundState
{
    public static function load($payment)
    {
        $state = json_decode((string) ($payment->teya_refund_response ?? ''), true);
        if (is_array($state) && isset($state['refunds'])) {
            return $state;
        }
        $ledger = array('refunds' => array());
        if (($payment->teya_refund_status ?? '') === 'success') {
            $ledger['refunds'][] = array('key' => $payment->teya_refund_key,
                'amount' => (int) $payment->teya_refund_amount, 'response' => $state);
        } elseif (($payment->teya_refund_status ?? '') === 'pending') {
            $ledger['pending'] = array('key' => $payment->teya_refund_key, 'next_attempt' => 0,
                'payload' => array('transaction_id' => $payment->teya_transaction_id,
                    'amount' => (int) $payment->teya_refund_amount,
                    'source_reference_id' => substr('VirtueMartOrder' . $payment->virtuemart_order_id, 0, 30),
                    'reason' => 'VirtueMart refund'));
            // Preserve the precise request when an older version saved it.
            if (isset($state['payload'])) {
                $ledger['pending']['payload'] = $state['payload'];
            }
        }
        return $ledger;
    }

    public static function refunded(array $state)
    {
        return array_sum(array_column($state['refunds'], 'amount'));
    }

    public static function amount($input, $currency, $remaining)
    {
        if (!is_string($input) || !preg_match('/^\d+(?:\.\d{1,3})?$/D', $input)) {
            throw new RuntimeException('Enter a positive refund amount using a decimal point.');
        }
        $minor = TeyaVirtuemartUtils::toMinorUnits($input, $currency);
        $factor = TeyaVirtuemartUtils::toMinorUnits(1, $currency);
        if ($minor <= 0 || $minor > $remaining || abs((float) $input * $factor - $minor) > 0.000001) {
            throw new RuntimeException('Refund must fit the currency precision and remaining paid balance.');
        }
        return $minor;
    }
}
