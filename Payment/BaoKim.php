<?php

namespace Truonglv\PaymentBaoKim\Payment;

use XF\Http\Request;
use XF\Mvc\Controller;
use XF\PrintableException;
use XF\Purchasable\Purchase;
use XF\Entity\PaymentProfile;
use XF\Payment\CallbackState;
use XF\Entity\PurchaseRequest;
use XF\Payment\AbstractProvider;
use XF\Entity\PaymentProviderLog;

if (!class_exists('\Firebase\JWT\JWT')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

class BaoKim extends AbstractProvider
{
    const PROVIDER_ID = 'tpb_baokim';
    const ALGO = 'HS256';
    const TOKEN_EXPIRE = 60; // token expires in seconds

    /**
     * @return string
     */
    public function getTitle()
    {
        return 'BaoKim';
    }

    /**
     * @return string
     */
    public function getApiEndpoint()
    {
        $enabled = (bool) \XF::config('enableLivePayments');
        if ($enabled) {
            return 'https://api.baokim.vn/payment';
        }

        return 'https://sandbox-api.baokim.vn/payment';
    }

    /**
     * @param PurchaseRequest $purchaseRequest
     * @param Purchase $purchase
     * @return array
     */
    protected function getPaymentParams(PurchaseRequest $purchaseRequest, Purchase $purchase)
    {
        return [
            'mrc_order_id' => $purchaseRequest->request_key,
            'total_amount' => $purchase->cost,
            'description' => $purchase->description,
            'url_success' => $purchase->returnUrl,
            'url_detail' => $purchase->cancelUrl,
            'accept_bank' => 1,
            'accept_cc' => 1,
            'accept_qrpay' => 1,
            'webhooks' => $this->getCallbackUrl()
        ];
    }

    /**
     * @param Controller $controller
     * @param PurchaseRequest $purchaseRequest
     * @param Purchase $purchase
     * @return \XF\Mvc\Reply\Redirect
     * @throws PrintableException
     */
    public function initiatePayment(Controller $controller, PurchaseRequest $purchaseRequest, Purchase $purchase)
    {
        $params = $this->getPaymentParams($purchaseRequest, $purchase);

        $client = $controller->app()->http()->client();
        $response = null;

        try {
            $response = $client->post(
                $this->getApiEndpoint() . '/api/v4/order/send',
                [
                    'form_params' => $params,
                    'query' => [
                        'jwt' => $this->getToken($purchaseRequest->PaymentProfile, $params)
                    ]
                ]
            );
        } catch (\Exception $e) {
            $controller
                ->app()
                ->logException($e, false, '[tl] Payment BaoKim: ');
        }

        if ($response === null) {
            throw new PrintableException(\XF::phrase('tpb_error_occurred_while_creating_order'));
        }

        $body = \strval($response->getBody());
        $json = \json_decode($body, true);

        /** @var PaymentProviderLog $log */
        $log = $controller->em()->create('XF:ProviderLog');
        $log->purchase_request_key = $purchaseRequest->request_key;
        $log->provider_id = $this->providerId;
        $log->transaction_id = isset($json['data'], $json['data']['order_id'])
            ? $json['data']['order_id']
            : '';

        $log->subscriber_id = '';
        $log->log_type = 'info';
        $log->log_message = 'Creating a order';
        $log->log_details = [
            'responseData' => $json,
            'responseCode' => $response->getStatusCode(),
            'requestData' => $params,
            '_rawData' => $body
        ];

        $log->save();

        if (!\is_array($json)) {
            $error = new \Exception('Invalid response: ' . $body);
            $controller
                ->app()
                ->logException($error, false, '[tl] Payment BaoKim: ');

            throw new PrintableException(\XF::phrase('tpb_error_occurred_while_creating_order'));
        }

        if (isset($json['data'], $json['data']['redirect_url'])) {
            return $controller->redirect(
                $this->getApiEndpoint() . '/' . \ltrim($json['data']['redirect_url'], '/'),
                ''
            );
        }

        throw new PrintableException(\XF::phrase('tpb_error_occurred_while_creating_order'));
    }

    /**
     * @param Request $request
     * @return CallbackState
     */
    public function setupCallback(\XF\Http\Request $request)
    {
        $inputRaw = $request->getInputRaw();
        $json = \json_decode($inputRaw, true);
        if (!\is_array($json)) {
            $json = [];
        }

        $filtered = $request->getInputFilterer()->filterArray($json, [
            'order' => 'array',
            'txn' => 'array',
            'sign' => 'str'
        ]);

        $state =  new CallbackState();

        if (isset($filtered['order']['mrc_order_id'])) {
            $state->requestKey = $filtered['order']['mrc_order_id'];
        }

        if (isset($filtered['order']['txn_id'])) {
            $state->transactionId = $filtered['order']['txn_id'];
        }

        $state->signature = $filtered['sign'];
        $state->inputFiltered = $filtered;
        $state->inputRaw = $inputRaw;

        $state->ip = $request->getIp();
        $state->_POST = $json;

        return $state;
    }

    /**
     * @param CallbackState $state
     * @return bool
     */
    protected function validateExpectedValues(CallbackState $state)
    {
        return ($state->getPurchaseRequest() && $state->getPaymentProfile());
    }

    /**
     * @param CallbackState $state
     * @return bool
     */
    public function validateCallback(CallbackState $state)
    {
        if (!$this->validateExpectedValues($state)) {
            $state->logType = 'error';
            $state->logMessage = 'Data received from BaoKim does not contain the expected values.';

            if (!$state->requestKey) {
                $state->httpCode = 200; // Not likely to recover from this error so send a successful response.
            }

            return false;
        }

        $filtered = $state->inputFiltered;
        $knownSign = $filtered['sign'];
        unset($filtered['sign']);

        $signData = \json_encode($filtered);
        if ($signData === false) {
            return false;
        }

        $userSign = \hash_hmac('sha256', $signData, $state->paymentProfile->options['api_secret']);
        if ($knownSign !== $userSign) {
            $state->logType = 'error';
            $state->logMessage = 'Webhook received from BaoKim could not be verified as being valid.';
            $state->httpCode = 400;

            return false;
        }

        return true;
    }

    /**
     * @param CallbackState $state
     * @return bool
     */
    public function validateCost(CallbackState $state)
    {
        $totalAmount = $state->inputFiltered['order']['total_amount'];
        $taxFee = $state->inputFiltered['order']['tax_fee'];

        $cost = \round($state->purchaseRequest->cost_amount, 2);
        $totalPaid = \round($totalAmount - $taxFee, 2);

        if ($cost !== $totalPaid) {
            $state->logType = 'error';
            $state->logMessage = 'Invalid cost amount';

            return false;
        }

        return true;
    }

    /**
     * @param CallbackState $state
     * @return void
     */
    public function getPaymentResult(CallbackState $state)
    {
        $result = $state->inputFiltered['order']['stat'];
        if ($result === 'c') {
            $state->paymentResult = CallbackState::PAYMENT_RECEIVED;

            $state->logMessage = \json_encode([
                'err_code' => 0,
                'message' => 'ok'
            ]);
        }
    }

    /**
     * @param CallbackState $state
     * @return void
     */
    public function prepareLogData(CallbackState $state)
    {
        $state->logDetails = $state->_POST;
    }

    /**
     * @param PaymentProfile $paymentProfile
     * @param mixed $unit
     * @param mixed $amount
     * @param mixed $result
     * @return bool
     */
    public function supportsRecurring(PaymentProfile $paymentProfile, $unit, $amount, &$result = self::ERR_NO_RECURRING)
    {
        return false;
    }

    /**
     * @param PaymentProfile $paymentProfile
     * @param array $formData
     * @return string
     */
    private function getToken(PaymentProfile $paymentProfile, array $formData)
    {
        $tokenId = base64_encode(\XF::generateRandomString(32, true));
        $issueAt = \XF::$time;
        $expiresAt = \XF::$time + self::TOKEN_EXPIRE;

        $payload = [
            'iat' => $issueAt,
            'jti' => $tokenId,
            'iss' => $paymentProfile->options['api_key'],
            'nbf' => \XF::$time,
            'exp' => $expiresAt,
            'form_params' => $formData
        ];

        return \Firebase\JWT\JWT::encode(
            $payload,
            $paymentProfile->options['api_secret'],
            self::ALGO
        );
    }
}
