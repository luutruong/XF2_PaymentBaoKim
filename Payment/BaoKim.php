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

    const KEY_DATA_REGISTRY_BANK_LIST = 'TBP_BankList';

    const BANK_ID_QRCODE = 297;

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
            return 'https://api.baokim.vn';
        }

        return 'https://sandbox-api.baokim.vn';
    }

    /**
     * @param PurchaseRequest $purchaseRequest
     * @param Purchase $purchase
     * @return array
     */
    protected function getPaymentParams(PurchaseRequest $purchaseRequest, Purchase $purchase)
    {
        $extraData = $purchase->extraData;

        return [
            'mrc_order_id' => $purchaseRequest->request_key,
            'total_amount' => $purchase->cost,
            'description' => $purchase->description,
            'url_success' => $purchase->returnUrl,
            'url_detail' => $purchase->cancelUrl,
            'accept_bank' => 1,
            'accept_cc' => 1,
            'accept_qrpay' => 1,
            'webhooks' => $this->getCallbackUrl(),
            'customer_email' => $purchaseRequest->User->email,
            'customer_phone' => isset($extraData['phone_number']) ? $extraData['phone_number'] : '',
            'customer_name' => $purchaseRequest->User->username
        ];
    }

    /**
     * @param PaymentProfile $paymentProfile
     * @return array
     */
    protected function getBankList(PaymentProfile $paymentProfile)
    {
        $dataRegistry = \XF::app()->registry();
        $cached = $dataRegistry->get(self::KEY_DATA_REGISTRY_BANK_LIST);

        $invalidate = false;

        if (!\is_array($cached)) {
            $invalidate = true;
        } else {
            $ttl = 86400; // cache for 1 day
            if (($cached['lastFetched'] + $ttl) <= \XF::$time) {
                $invalidate = true;
                $cached = [];
            }
        }

        if ($invalidate) {
            $client = \XF::app()->http()->client();

            $response = null;

            try {
                $response = $client->get($this->getApiEndpoint() . '/payment/api/v4/bpm/list', [
                    'query' => [
                        'jwt' => $this->getToken($paymentProfile, [])
                    ]
                ]);
            } catch (\Exception $e) {
            }

            if ($response === null || $response->getStatusCode() !== 200) {
                return [];
            }

            $json = \json_decode(\strval($response->getBody()), true);
            if (!isset($json['data'])) {
                return [];
            }

            $cached = [
                'bankList' => $json['data'],
                'lastFetched' => \XF::$time
            ];

            $dataRegistry->set(self::KEY_DATA_REGISTRY_BANK_LIST, $cached);
        }

        return $cached['bankList'];
    }

    /**
     * @param Controller $controller
     * @param PurchaseRequest $purchaseRequest
     * @param Purchase $purchase
     * @return \XF\Mvc\Reply\View
     */
    public function initiatePayment(Controller $controller, PurchaseRequest $purchaseRequest, Purchase $purchase)
    {
        $bankList = $this->getBankList($purchaseRequest->PaymentProfile);
        $bankList = \array_filter($bankList, function ($item) {
            return $item['type'] == 1;
        });

        return $controller->view(
            'Truonglv\PaymentBaoKim:BaoKim',
            'tpb_payment_baokim_initiate',
            \compact('purchaseRequest', 'purchase', 'bankList')
        );
    }

    /**
     * @param Controller $controller
     * @param PurchaseRequest $purchaseRequest
     * @param PaymentProfile $paymentProfile
     * @param Purchase $purchase
     * @return \XF\Mvc\Reply\AbstractReply
     * @throws PrintableException
     */
    public function processPayment(
        Controller $controller,
        PurchaseRequest $purchaseRequest,
        PaymentProfile $paymentProfile,
        Purchase $purchase
    ) {
        $params = $this->getPaymentParams($purchaseRequest, $purchase);
        $bankList = $this->getBankList($paymentProfile);

        $type = $controller->request()->filter('type', 'str');
        if ($type === 'qrcode') {
            $bankId = self::BANK_ID_QRCODE;
        } else {
            $bankId = $controller->request()->filter('bank_id', 'uint');
            $bankSelected = \array_filter($bankList, function ($item) use ($bankId) {
                return $item['id'] == $bankId;
            });
            if (\count($bankSelected) !== 1) {
                return $controller->error(\XF::phrase('tbp_please_choose_a_valid_bank'));
            }
        }

        $params['bpm_id'] = $bankId;

        $client = $controller->app()->http()->client();
        $response = null;

        try {
            $response = $client->post(
                $this->getApiEndpoint() . '/payment/api/v4/order/send',
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
        $log = $controller->em()->create('XF:PaymentProviderLog');
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

        if (isset($json['data'], $json['data']['payment_url'])) {
            return $controller->redirect(
                $json['data']['payment_url'],
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
        $inputRaw = \file_get_contents('php://input');
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
                $state->httpCode = 200;
            }

            return false;
        }

        $client = \XF::app()->http()->client();
        $inputFiltered = $state->inputFiltered;

        try {
            $response = $client->get($this->getApiEndpoint() . '/api/v4/order/detail', [
                'query' => [
                    'id' => $inputFiltered['order']['order_id'],
                    'mrc_order_id' => $state->requestKey
                ]
            ]);
        } catch (\Exception $e) {
            $state->logType = 'error';
            $state->logMessage = $e->getMessage();
            $state->httpCode = 400;

            return false;
        }

        if ($response->getStatusCode() !== 200) {
            $state->logType = 'error';
            $state->logMessage = $response->getReasonPhrase();

            $state->httpCode = 400;

            return false;
        }

        $data = \json_decode(\strval($response->getBody()));
        $order = $data['data'];

        if ($order['mrc_order_id'] !== $state->requestKey) {
            return false;
        }

        $inputFiltered = array_replace_recursive($inputFiltered, [
            'order' => $order
        ]);
        $state->inputFiltered = $inputFiltered;

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
        $state->logDetails = $state->_POST + [
            'raw' => $state->inputRaw
        ];
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
        $tokenId = \base64_encode(\XF::generateRandomString(32, true));
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
