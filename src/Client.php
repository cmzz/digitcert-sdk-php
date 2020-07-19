<?php

namespace DigitCert;

use DigitCert\Exceptions\DoNotHavePrivilegeException;
use DigitCert\Exceptions\InsufficientBalanceException;
use DigitCert\Exceptions\RequestException;
use DigitCert\Resources\Order;
use DigitCert\Resources\Product;
use DigitCert\Response\Interfaces\BaseResponse;
use DigitCert\Traits\SignTrait;

use GuzzleHttp\Client as GuzzleHttpClient;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\RequestOptions;
use Illuminate\Validation\ValidationException;

use function GuzzleHttp\json_decode;

/**
 * @method mixed get($uri, $parameters = [])
 * @method mixed post($uri, $parameters = [])
 */
class Client
{
    use SignTrait;

    const ORIGIN_API = 'https://www.digitcert.com.cn';
    const ORIGIN_API_STAGING = 'https://www.digitcert.com.cn';
    const ORIGIN_API_DEV = '';

    const CODE_EXCEPTION_MAP = [
        'INSUFFICIENT_BALANCE' => InsufficientBalanceException::class,
        'DO_NOT_HAVE_RIVILEGE' => DoNotHavePrivilegeException::class,
    ];

    /**
     * @var Product
     */
    public $product;

    /**
     * @var Order
     */
    public $order;

    /**
     * @var string
     */
    protected $accessKeyId;

    /**
     * @var string
     */
    protected $accessKeySecret;

    /**
     * @var string
     */
    protected $apiOrigin;

    /**
     * @var int
     */
    protected $connectTimeout;

    /**
     * @var int
     */
    protected $readTimeout;

    public function __construct($accessKeyId, $accessKeySecret, $apiOrigin = null, $connectTimeout = 5, $readTimeout = 15)
    {
        if ($apiOrigin === null) {
            $apiOrigin = self::ORIGIN_API;
        }

        $this->accessKeyId = $accessKeyId;
        $this->accessKeySecret = $accessKeySecret;
        $this->apiOrigin = $apiOrigin;

        $this->product = new Product($this);
        $this->order = new Order($this);
        $this->connectTimeout = $connectTimeout;
        $this->readTimeout = $readTimeout;

        //$this->callback = new Callback($this);
    }

    /**
     * 魔术
     *
     * @param string $method GET、POST
     * @param array $arguments 第一个参数为API的路径，第二个参数为业务参数
     * @return BaseResponse
     * @throws RequestException
     * @throws ValidationException
     */
    public function __call($method, $arguments = [])
    {
        try {
            $http = new GuzzleHttpClient([
                RequestOptions::CONNECT_TIMEOUT => $this->connectTimeout,
                RequestOptions::READ_TIMEOUT => $this->readTimeout,
            ]);

            $api = $arguments[0];
            $resource = '/' . $api;

            $parameters = isset($arguments[1]) ? $arguments[1] : [];
            $parameters = $this->sign($resource, $parameters, $this->accessKeyId, $this->accessKeySecret);

            $uri = $this->apiOrigin . $resource;

            $response = $http->{$method}($uri, [
                ($method == 'get' ? 'query' : RequestOptions::JSON) => $parameters,
            ]);

            $json = json_decode($response->getBody()->__toString());

            if (!isset($json->success) || !$json->success) {
                $exception_class = RequestException::class;
                $map = static::CODE_EXCEPTION_MAP;
                if (!isset($json->error_code)) {
                    throw new RequestException('未知错误', -1);
                }
                if (isset($map[$json->error_code])) {
                    $exception_class = $map[$json->error_code];
                }
                throw new $exception_class(isset($json->message) ? $json->message : '请求接口出错', isset($json->error_code) ? $json->error_code : -1);
            }
            return $json->data;
        } catch (ClientException $e) {
            // 若不存在 Laravel's ValidationException 类，或者版本太低没有 withMessages 方法，抛出Guzzle的异常
            if (!class_exists(ValidationException::class) || !method_exists(ValidationException::class, 'withMessages')) {
                throw $e;
            }

            $response = $e->getResponse();
            if ($response->getStatusCode() !== 412) {
                throw $e;
            }

            $data = json_decode($response->getBody()->__toString(), true);
            if (JSON_ERROR_NONE !== json_last_error() || !isset($data['message'])) {
                throw new ClientException('JSON DECODE ERROR', $e->getRequest(), $e->getResponse(), $e);
            }

            throw ValidationException::withMessages($data['message']);
        }
    }
}