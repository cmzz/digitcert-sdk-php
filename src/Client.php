<?php

namespace DigitCert\Sdk;

use DigitCert\Sdk\Exceptions\DoNotHavePrivilegeException;
use DigitCert\Sdk\Exceptions\InsufficientBalanceException;
use DigitCert\Sdk\Exceptions\RequestException;
use DigitCert\Sdk\Resources\Certificate;
use DigitCert\Sdk\Resources\Order;
use DigitCert\Sdk\Resources\Product;
use DigitCert\Sdk\Response\Interfaces\BaseResponse;
use DigitCert\Sdk\Traits\SignTrait;
use GuzzleHttp\Client as GuzzleHttpClient;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Facades\Log;
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
    const ORIGIN_API_DEV = 'http://dev.digitalcert.test';

    const CODE_EXCEPTION_MAP = [
        'INSUFFICIENT_BALANCE' => InsufficientBalanceException::class,
        'DO_NOT_HAVE_RIVILEGE' => DoNotHavePrivilegeException::class,
    ];

    const ENV_DEV = 'dev';
    const ENV_STG = 'stg';
    const ENV_PROD = 'prod';

    /**
     * @var Product
     */
    public $product;

    /**
     * @var Certificate $certificate
     */
    public $certificate;

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

    public function __construct($accessKeyId, $accessKeySecret, $env = self::ENV_PROD, $connectTimeout = 5, $readTimeout = 15)
    {
        switch ($env) {
            case self::ENV_DEV:
                $apiOrigin = self::ORIGIN_API_DEV;
                break;

            case self::ENV_STG:
                $apiOrigin = self::ORIGIN_API_STAGING;
                break;

            default:
                $apiOrigin = self::ORIGIN_API;
        }

        $this->accessKeyId = $accessKeyId;
        $this->accessKeySecret = $accessKeySecret;
        $this->apiOrigin = $apiOrigin;

        $this->product = new Product($this);
        $this->order = new Order($this);
        $this->certificate = new Certificate($this);

        $this->connectTimeout = $connectTimeout;
        $this->readTimeout = $readTimeout;

        //$this->callback = new Callback($this);
    }

    /**
     * ??????
     *
     * @param string $method GET???POST
     * @param array $arguments ??????????????????API??????????????????????????????????????????
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

            /** @var Response $response */
            $response = $http->{$method}($uri, [
                ($method == 'get' ? 'query' : RequestOptions::JSON) => $parameters,
            ]);

            if ($response->getStatusCode() != 200) {
                throw new RequestException('???????????????');
            }

            $content = $response->getBody()->getContents();
            Log::info('response', [
                $uri,
                $response->getStatusCode(),
                $method,
                [
                    ($method == 'get' ? 'query' : RequestOptions::JSON) => $parameters,
                ],
                $content
            ]);

            try {
                $response = \json_decode($content);
                if (!$response)
                    throw new RequestException('??????????????????');

                return $response;
            } catch (\Throwable $e) {
                Log::error('??????????????????????????????', [
                    $uri, $method, [
                        ($method == 'get' ? 'query' : RequestOptions::JSON) => $parameters,
                    ], $content
                ]);

                throw $e;
            }
        } catch (ClientException $e) {
            // ???????????? Laravel's ValidationException ?????????????????????????????? withMessages ???????????????Guzzle?????????
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
        } catch (\Throwable $e) {
            dd($e);
        }
    }
}
