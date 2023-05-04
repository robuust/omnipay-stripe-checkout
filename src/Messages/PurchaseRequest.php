<?php

namespace DigiTickets\Stripe\Messages;

class PurchaseRequest extends AbstractCheckoutRequest
{
    private function nullIfEmpty(string $value = null)
    {
        return empty($value) ? null : $value;
    }

    public function getData()
    {
        // Just validate the parameters.
        $this->validate('apiKey', 'transactionId', 'returnUrl', 'cancelUrl');

        return null;
    }

    public function sendData($data)
    {
        // We use Stripe's SDK to initialise a (Stripe) session. The session gets passed through the process and is
        // used to identify this transaction.
        \Stripe\Stripe::setApiKey($this->getApiKey());

        // Initiate the session.
        // Unfortunately (and very, very annoyingly), the API does not allow negative- or zero value items in the
        // cart, so we have to filter them out (and re-index them) before we build the line items array.
        // Beware because the amount the customer pays is the sum of the values of the remaining items, so if you
        // supply negative-valued items, they will NOT be deducted from the payment amount.
        $session = \Stripe\Checkout\Session::create(
            [
                'client_reference_id' => $this->getTransactionId(),
                'customer_email' => $this->getCustomerEmail(),
                'payment_intent_data' => [
                    'description' => $this->getDescription(),
                ],
                'line_items' => array_map(
                    function (\Omnipay\Common\Item $item) {
                        // Sometimes PHP can't hold the item price accurately, which is why we have to use round()
                        // after multiplying by 100. Eg, 9.95 is stored as 9.9499999999999993 and without round() it
                        // ends up as 994 when it should be 995.
                        return [
                            'price_data' => [
                                'currency' => $this->getCurrency(),
                                'unit_amount' => (int)round((100 * $item->getPrice())), // @TODO: The multiplier depends on the currency
                                'product_data' => [
                                    'name' => $item->getName(),
                                    'description' => $this->nullIfEmpty($item->getDescription()),
                                ],
                            ],
                            'quantity' => $item->getQuantity(),
                        ];
                    },
                    array_values(
                        array_filter(
                            $this->getItems()->all(),
                            function (\Omnipay\Common\Item $item) {
                                return $item->getPrice() > 0;
                            }
                        )
                    )
                ),
                'mode' => 'payment',
                'success_url' => $this->getReturnUrl(),
                'cancel_url' => $this->getCancelUrl(),
            ]
        );

        return $this->response = new PurchaseResponse($this, ['session' => $session]);
    }
}
