<?php
namespace Apikr\Siot\Iamport;

use Apikr\Api\Result;
use Apikr\Siot\Iamport\Contracts\CardExpiryInterface;
use Apikr\Siot\Iamport\Contracts\CardNumberInterface;
use Apikr\Siot\Iamport\Contracts\TransactionInterface;
use Apikr\Siot\Iamport\Exception\IamportRequestException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

class Api
{
    /** @var \GuzzleHttp\Client */
    protected $client;

    /** @var \Psr\SimpleCache\CacheInterface */
    protected $cache;
    
    /** @var \Apikr\Siot\Iamport\Configuration */
    protected $config;

    public function __construct(Client $client, Configuration $config)
    {
        $this->client = $client;
        $this->config = $config;
        $this->cache = $config->cache ?: new ArrayCache();
    }

    /**
     * @param \Apikr\Siot\Iamport\Contracts\TransactionInterface $transaction
     * @param array $options
     * @return \Apikr\Siot\Iamport\TransactionResult
     */
    public function cancelPayment(TransactionInterface $transaction, array $options = [])
    {
        $result = $this->request('post', '/payments/cancel', $options + [
            'imp_uid' => $transaction->getImpUid(),
        ]);
        return new TransactionResult($result->toArray());
    }
    
    /**
     * @param string $customerUid
     * @param \Apikr\Siot\Iamport\Contracts\CardNumberInterface $cardNumber
     * @param \Apikr\Siot\Iamport\Contracts\CardExpiryInterface $expiry
     * @param string $birth
     * @param string|null $pwd2digit
     * @param array $options
     * @return \Apikr\Api\Result
     */
    public function saveUnauthPaymentCustomer(
        $customerUid,
        CardNumberInterface $cardNumber,
        CardExpiryInterface $expiry,
        $birth,
        $pwd2digit = null,
        array $options = []
    ) {
        return $this->request('post', "/subscribe/customers/{$customerUid}", $options + [
            'card_number' => $cardNumber->getCardNumber(),
            'expiry' => $expiry->getCardExpiry(),
            'birth' => $birth,
            'pwd_2digit' => $pwd2digit,
        ]);
    }

    /**
     * @param string $customerUid
     * @return \Apikr\Api\Result
     */
    public function getUnauthPaymentCustomer($customerUid)
    {
        return $this->request('get', "/subscribe/customers/{$customerUid}");
    }

    /**
     * @param string $customerUid
     * @return \Apikr\Api\Result
     */
    public function removeUnauthPaymentCustomer($customerUid)
    {
        return $this->request('delete', "/subscribe/customers/{$customerUid}");
    }

    /**
     * @param string $customerUid
     * @param string $merchantUid
     * @param int $amount
     * @param string $name
     * @param array $options
     * @return \Apikr\Siot\Iamport\TransactionResult
     */
    public function makeUnauthPayment($customerUid, $merchantUid, $amount, $name, array $options = [])
    {
        $result = $this->request('post', "/subscribe/payments/again", $options + [
            'customer_uid' => $customerUid,
            'merchant_uid' => $merchantUid,
            'amount' => (int)$amount,
            'name' => $name,
        ]);
        return new TransactionResult($result->toArray());
    }
    
    public function scheduleUnauthPayment($customerUid, $merchantUid, $amount, $name, array $options = [])
    {
    }

    public function unscheduleUnauthPayment($customerUid, $merchantUid, $amount, $name, array $options = [])
    {
    }

    /**
     * @return string
     */
    public function createToken()
    {
        if ($this->cache->has($this->config->tokenCacheKey)) {
            return $this->cache->get($this->config->tokenCacheKey);
        }
        $result = $this->request('POST', '/users/getToken', [
            'imp_key' => $this->config->impKey,
            'imp_secret' => $this->config->impSecret,
        ], false);
        $this->cache->set(
            $this->config->tokenCacheKey,
            $result->search('response.access_token'),
            $result->search('response.expired_at')
        );
        return $result->search('response.access_token');
    }

    /**
     * @param string $method
     * @param string $path
     * @param array $form
     * @param bool $auth
     * @return \Apikr\Api\Result
     */
    public function request($method, $path, array $form = [], $auth = true)
    {
        $headers = [];
        if ($auth) {
            $headers['Authorization'] = $this->createToken();
        }
        try {
            $uri = $this->config->host . $path;
            $options = [
                'headers' => $headers,
            ];
            if (count($form)) {
                if (strtolower($method) === 'get') {
                    $uri .= '?' . http_build_query($form);
                } else {
                    $options['json'] = $form;
                }
            }
            $response = $this->client->request($method, $uri, $options);
            $result = Result::createFromResponse($response);
            if ($result->search('code') != 0) {
                throw new IamportRequestException($result->search('message'), $result->search('code'), $result);
            }
            return $result;
        } catch (ClientException $e) {
            $result = Result::createFromResponse($e->getResponse());
            throw new IamportRequestException($result->search('message'), $result->search('code'), $result, $e);
        }
    }
}
