<?php

namespace Cristal\ApiWrapper\Transports;

use App\Models\ApiLog;
use App\Repositories\ApiLogRepository;
use Closure;
use Cristal\ApiWrapper\Exceptions\ApiEntityNotFoundException;
use Cristal\ApiWrapper\Exceptions\ApiException;
use Cristal\ApiWrapper\Exceptions\Handlers\AbstractErrorHandler;
use Cristal\ApiWrapper\Exceptions\Handlers\BadRequestErrorHandler;
use Cristal\ApiWrapper\Exceptions\Handlers\ForbiddenErrorHandler;
use Cristal\ApiWrapper\Exceptions\Handlers\NetworkErrorHandler;
use Cristal\ApiWrapper\Exceptions\Handlers\NotFoundErrorHandler;
use Cristal\ApiWrapper\Exceptions\Handlers\UnauthorizedErrorHandler;
use Cristal\ApiWrapper\MultipartParam;
use Curl\Curl as CurlClient;
use CURLFile;
use Illuminate\Support\Facades\Log;

/**
 * Class Transport
 * @package Cristal\ApiWrapper\Transports
 */
class Transport implements TransportInterface
{
    const HTTP_NETWORK_ERROR_CODE = 0;
    const HTTP_UNAUTHORIZED = 401;
    const HTTP_FORBIDDEN = 403;
    const HTTP_NOT_FOUND_ERROR_CODE = 404;
    const HTTP_BAD_REQUEST = 400;
    const HTTP_UNPROCESSABLE_ENTITY = 422;

    const JSON_MIME_TYPE = 'application/json';

    /**
     * @var null|string
     */
    protected $entrypoint;

    /**
     * @var CurlClient
     */
    protected $client;

    /**
     * @var AbstractErrorHandler[]
     */
    protected $errorHandlers = [];

    /**
     * @var null|string
     */
    protected $url = null;

    /**
     * @var null|string
     */
    protected $payload = null;

    /**
     * Transport constructor.
     *
     * @param string $entrypoint
     * @param CurlClient $client
     */
    public function __construct(string $entrypoint, CurlClient $client)
    {
        $this->client = $client;
        $this->entrypoint = rtrim($entrypoint, '/') . '/';

        $this->setErrorHandler(self::HTTP_NETWORK_ERROR_CODE, new NetworkErrorHandler($this));
        $this->setErrorHandler(self::HTTP_UNAUTHORIZED, new UnauthorizedErrorHandler($this));
        $this->setErrorHandler(self::HTTP_FORBIDDEN, new ForbiddenErrorHandler($this));
        $this->setErrorHandler(self::HTTP_NOT_FOUND_ERROR_CODE, new NotFoundErrorHandler($this));
        $this->setErrorHandler(self::HTTP_BAD_REQUEST, new BadRequestErrorHandler($this));
        $this->setErrorHandler(self::HTTP_UNPROCESSABLE_ENTITY, new BadRequestErrorHandler($this));
    }

    /**
     * Define or remove an error handler for the request.
     * Pass null to remove an existing handler.
     *
     * @param int $code
     * @param AbstractErrorHandler|null $handler
     *
     * @return $this
     */
    public function setErrorHandler(int $code, ?AbstractErrorHandler $handler)
    {
        $this->errorHandlers[$code] = $handler;

        if (is_null($handler)) {
            unset($this->errorHandlers[$code]);
        }

        return $this;
    }

    /**
     * Get Curl client.
     *
     * @return CurlClient
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * {@inheritdoc}
     */
    public function request($endpoint, array $data = [], $method = 'get')
    {
        $rawResponse = $this->rawRequest($endpoint, $data, $method);
        $httpStatusCode = $this->getClient()->httpStatusCode;
        $response = json_decode($rawResponse, true);
        $this->saveAPILog($endpoint, $data, $method, $httpStatusCode, $rawResponse);
        if ($httpStatusCode >= 200 && $httpStatusCode <= 299) {
            return $response;
        }

        $exception = new ApiException(
            $response,
            sprintf(
                'The request ended on a %s code : %s',
                $httpStatusCode,
                $this->arrayGet($response ?? [], $this->getErrorKey()) ?? $rawResponse ?? 'Unknown error message'
            ),
            $httpStatusCode
        );

        if ($handler = $this->errorHandlers[$httpStatusCode] ?? false) {
            return $handler->handle($exception, compact('endpoint', 'data', 'method'));
        }

        throw $exception;
    }

    public function getErrorKey(): string
    {
        return 'message';
    }

    /**
     * {@inheritdoc}
     */
    public function rawRequest($endpoint, array $data = [], $method = 'get')
    {
        $method = strtolower($method);
        switch ($method) {
            case 'get':
                $url = $this->getUrl($endpoint, $data);
                $this->url = $url;
                $this->payload = json_encode($data);
//                ddd($url);
                Log::channel('internalApi')->info("API request ($method) to: " . $url);
                $this->getClient()->get($url);
                break;
            case 'post':
//                ddd($this->getUrl($endpoint));
                $this->payload = $this->encodeBody($data);
//                ddd($this->payload);

                Log::channel('internalApi')->info("API request ($method) to: " . $this->getUrl($endpoint) . $this->encodeBody($data));
                $this->url = $this->getUrl($endpoint);
                $this->getClient()->post(
                    $this->getUrl($endpoint),
                    $this->encodeBody($data),
                    (version_compare(PHP_VERSION, '5.5.11') < 0) || defined('HHVM_VERSION')
                );
                break;
            case 'put':
                $url = $this->getUrl($endpoint);
                $this->url = $url;
                $this->payload = $this->encodeBody($data);
                $this->getClient()->put($url, $this->encodeBody($data));
                break;
            case 'patch':
                $url = $this->getUrl($endpoint);
                $this->url = $url;
                $this->payload = $this->encodeBody($data);
                $this->getClient()->patch($url, $this->encodeBody($data));
                break;
            case 'delete':
                $url = $this->getUrl($endpoint);
                $this->url = $url;
                $this->payload = json_encode($data);
                $this->getClient()->delete($url, $data);
                break;
            default:
                $this->url = $this->getUrl($endpoint);
                $this->payload = json_encode($data);
                break;
        }

        return $this->getClient()->rawResponse;
    }

    /**
     * Build URL with stored entrypoint, the endpoint and data queries.
     *
     * @param string $endpoint
     * @param array $data
     *
     * @return string
     */
    protected function getUrl(string $endpoint, array $data = [])
    {
        $url = $this->getEntrypoint() . ltrim($endpoint, '/');
        return $url . $this->appendData($data);
    }

    /**
     * Get entrypoint URL.
     *
     * @return string
     */
    public function getEntrypoint()
    {
        return $this->entrypoint;
    }

    /**
     * Add request parameters to the URI.
     *
     * @param array $data
     *
     * @return string|null
     */
    protected function appendData(array $data = [])
    {
        if (!count($data)) {
            return null;
        }

        $data = array_map(function ($item) {
            return is_null($item) ? '' : $item;
        }, $data);

        return '?' . http_build_query($data);
    }

    /**
     * If a file is sent, use multipart header and raw data.
     *
     * @param interable $data
     *
     * @return false|string
     */
    public function encodeBody($data)
    {
        foreach ($data as $value) {
            if ($value instanceof CURLFile) {
                $this->getClient()->setHeader('Content-Type', 'multipart/form-data');

                return $data;
            }
            if ($value instanceof MultipartParam) {
                $delimiter = '----WebKitFormBoundary' . uniqid('', true);

                $this->getClient()->setHeader('Content-Type', 'multipart/form-data; boundary=' . $delimiter);
                return join(array_map(function ($param, $name) use ($delimiter) {
                        if (!$param instanceof MultipartParam) {
                            $param = new MultipartParam($param);
                        }

                        return $param->render($name, $delimiter);
                    }, $data, array_keys($data))) . '--' . $delimiter . '--';

            }
        }

        $this->getClient()->setHeader('Content-Type', static::JSON_MIME_TYPE);
        return json_encode($data);
    }

    public function getResponseHeaders(): array
    {
        return iterator_to_array($this->getClient()->getResponseHeaders());
    }

    protected function arrayGet(array $array, string $key)
    {
        $exploded = explode('.', $key, 2);

        if (!isset($exploded[1])) {
            return $array[$key] ?? null;
        }

        return $this->arrayGet($array[$exploded[0]], $exploded[1]);
    }

    protected function saveAPILog($endpoint, $data, $method, $httpStatusCode, $rawResponse)
    {
        $response = ($httpStatusCode >= 200 && $httpStatusCode <= 299) ? $rawResponse : json_encode([$rawResponse]);
        $apiLogRepository = new ApiLogRepository();
        $apiLogRepository->prepareInternalApiDataForLogSave(
            config('const.api.internal_api_wrapper'),
            config('models.api_logs_direction.outgoing'),
            $this->url,
            $method,
            $httpStatusCode,
            $this->payload,
            request()->ip(),
            request()->header('User-Agent'),
            json_encode($this->getResponseHeaders()),
            $response
        );
    }
}
