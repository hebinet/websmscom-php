<?php

namespace WebSms;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use WebSms\Exception\ApiException;
use WebSms\Exception\AuthorizationFailedException;
use WebSms\Exception\HttpConnectionException;
use WebSms\Exception\ParameterValidationException;
use WebSms\Exception\UnknownResponseException;

class Client
{

    /**
     * @var string
     */
    private $VERSION = "1.0.7";
    /**
     * @var string
     */
    private $username;
    /**
     * @var string
     */
    private $accessToken;
    /**
     * @var int
     */
    private $mode;
    /**
     * @var string|null
     */
    private $password;
    /**
     * @var string
     */
    private $url;
    /**
     * @var string
     */
    private $path;
    /**
     * @var int
     */
    private $port;
    /**
     * @var string
     */
    private $scheme;
    /**
     * @var string
     */
    private $host;
    /**
     * @var bool
     */
    private $verbose = false;
    /**
     * @var int
     */
    private $connectionTimeout = 10;
    /**
     * @var string
     */
    private $endpointJson = "/json/smsmessaging/";
    /**
     * @var string
     */
    private $endpointText = "text";
    /**
     * @var string
     */
    private $endpointBinary = "binary";
    /**
     * @var bool
     */
    private $sslVerifyHost = true;
    /**
     * @var array
     */
    private $guzzleOptions = [];
    /**
     * @var bool
     */
    private $testMode = false;


    /**
     * Client constructor.
     *
     * @param  string  $url
     * @param  string  $usernameOrAccessToken
     * @param  string|null  $password
     * @param  int  $mode
     *
     * @throws ParameterValidationException
     */
    public function __construct(
        string $url,
        string $usernameOrAccessToken,
        string $password = null,
        int $mode = AuthenticationMode::USER_PW
    ) {
        $this->initUrl($url);

        if ((strlen($this->host) < 4)) {
            throw new ParameterValidationException(
                "Invalid call of sms.at gateway class. Hostname in wrong format: {$this->url}"
            );
        }
        if ($mode === AuthenticationMode::USER_PW && (! $usernameOrAccessToken || ! $password) || $mode === AuthenticationMode::ACCESS_TOKEN && ! $usernameOrAccessToken) {
            throw new ParameterValidationException(
                "Invalid call of sms.at gateway class. Check username/password or token."
            );
        }

        $this->mode = $mode;

        // username & password authentication
        if ($this->mode === AuthenticationMode::USER_PW) {
            $this->username = $usernameOrAccessToken;
            $this->password = $password;
        } else { // access token authentication
            $this->accessToken = $usernameOrAccessToken;
        }
    }

    /**
     * @param  Message  $message  message object of type WebSmsCom\TextMessage or BinaryMessage
     * @param  int|null  $maxSmsPerMessage
     *
     * @return Response
     *
     * @throws ApiException
     * @throws AuthorizationFailedException
     * @throws HttpConnectionException
     * @throws ParameterValidationException
     * @throws UnknownResponseException
     */
    public function send(Message $message, int $maxSmsPerMessage = null)
    {
        if (! is_null($maxSmsPerMessage) && $maxSmsPerMessage <= 0) {
            throw new ParameterValidationException("maxSmsPerMessage cannot be less or equal to 0, try null.");
        }

        return $this->doRequest($message, $maxSmsPerMessage);
    }

    /**
     * @param  string  $url
     */
    private function initUrl(string $url)
    {
        // remove trailing slashes from url
        $this->url = preg_replace('/\/+$/', '', $url);
        if (! preg_match("#^http|^https.*#i", $this->url)) {
            $this->url = 'https://'.$this->url;
        }

        $parsedUrl = parse_url($this->url);
        $this->host = $parsedUrl['host'] ?? '';
        $this->path = $parsedUrl['path'] ?? '';

        $this->scheme = $parsedUrl['scheme'] ?? 'http';
        $this->port = $parsedUrl['port'] ?? '';

        if (! $this->port) {
            $this->port = 443;
            if ($this->scheme === 'http') {
                $this->port = 80;
            }
        }
    }

    /**
     * @param  Message  $message
     * @param  int  $maxSmsPerMessage
     *
     * @return Response|null
     *
     * @throws ApiException
     * @throws AuthorizationFailedException
     * @throws HttpConnectionException
     * @throws UnknownResponseException
     */
    private function doRequest(Message $message, int $maxSmsPerMessage): ?Response
    {
        $client = new \GuzzleHttp\Client(
            array_merge(
                $this->guzzleOptions,
                [
                    // Base URI is used with relative requests
                    'base_uri' => "{$this->scheme}://{$this->host}{$this->endpointJson}",
                    'timeout' => $this->connectionTimeout,
                ]
            )
        );

        $headers = [
            'User-Agent' => "PHP SDK Client with Guzzle (v".$this->VERSION.", PHP".phpversion().")",
        ];
        $options = [];

        if ($this->mode === AuthenticationMode::USER_PW) {
            $options['auth'] = [
                $this->username,
                $this->password,
            ];
        } else {
            // add access token in header
            $headers['Authorization'] = "Bearer {$this->accessToken}";
        }

        $data = $message->getJsonData();
        if ($maxSmsPerMessage > 0) {
            $data['maxSmsPerMessage'] = $maxSmsPerMessage;
        }

        if (is_bool($this->testMode)) {
            $data['test'] = $this->testMode;
        }

        $options[RequestOptions::JSON] = $data;
        $options[RequestOptions::HEADERS] = $headers;
        if (! $this->sslVerifyHost) {
            // Defaults to true so we need only check for false.
            // Guzzle Docs: Disable validation entirely (don't do this!).
            $options[RequestOptions::VERIFY] = false;
        }
        if ($this->verbose) {
            $options[RequestOptions::DEBUG] = true;
        }

        $path = $message instanceof BinaryMessage ? $this->endpointBinary : $this->endpointText;
        try {
            $response = $client->post($path, $options);

            if ($response->getStatusCode() > 200) {
                throw new HttpConnectionException(
                    "Response HTTP Status: {$response->getStatusCode()}\n{$response->getBody()}",
                    $response->getStatusCode()
                );
            }

            if (false === strpos($response->getHeaderLine('Content-Type'), 'application/json')) {
                throw new UnknownResponseException(
                    "Received unknown content type '{$response->getHeaderLine('Content-Type')}'. Content: {$response->getBody()}"
                );
            }

            $apiResult = json_decode($response->getBody()->getContents());
            if ($apiResult->statusCode < 2000 || $apiResult->statusCode > 2001) {
                throw new ApiException($apiResult->statusMessage, $apiResult->statusCode);
            }

            return new Response($apiResult, $response);
        } catch (RequestException $e) {
            if ($e instanceof ConnectException) {
                throw new HttpConnectionException("Couldn't connect to remote server");
            }

            if ($e->getCode() === 401) {
                if ($this->mode === AuthenticationMode::ACCESS_TOKEN) {
                    throw new AuthorizationFailedException("Authentication failed. Invalid access token.");
                }
                throw new AuthorizationFailedException(
                    "Basic Authentication failed. Check given username and password. (Account has to be active)"
                );
            }

            throw new HttpConnectionException(
                "Response HTTP Status: {$e->getCode()}\n{$e->getMessage()}",
                $e->getCode()
            );
        }
    }

    /**
     * @return $this
     */
    public function test(): self
    {
        $this->testMode = true;

        return $this;
    }

    /**
     * @return $this
     */
    public function noTest(): self
    {
        $this->testMode = false;

        return $this;
    }

    /**
     * Returns version string of this WebSmsCom client
     *
     * @return string
     */
    public function getVersion(): string
    {
        return $this->VERSION;
    }

    /**
     * Returns username set at WebSmsCom client creation
     *
     * @return string
     */
    public function getUsername(): string
    {
        return $this->username;
    }

    /**
     * Returns url set at WebSmsCom client creation
     *
     * @return string
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * Returns timeout in seconds
     *
     * @return int
     */
    public function getConnectionTimeout(): int
    {
        return $this->connectionTimeout;
    }

    /**
     * Set time in seconds for http timeout
     *
     * @param  int  $connectionTimeout
     *
     * @return Client
     */
    public function setConnectionTimeout(int $connectionTimeout): Client
    {
        $this->connectionTimeout = $connectionTimeout;

        return $this;
    }

    /**
     * Set verbose to see more information about request (echoes)
     *
     * @param  bool  $value
     *
     * @return Client
     */
    public function setVerbose(bool $value): Client
    {
        $this->verbose = $value;

        return $this;
    }

    /**
     * Ignore ssl host security
     *
     * @param  bool  $value
     *
     * @return Client
     */
    public function setSslVerifyHost(bool $value): Client
    {
        $this->sslVerifyHost = $value;

        return $this;
    }

    /**
     * @param  array  $guzzleOptions
     *
     * @return Client
     */
    public function setGuzzleOptions(array $guzzleOptions): Client
    {
        $this->guzzleOptions = $guzzleOptions;

        return $this;
    }

    /**
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * @return string
     */
    public function getAccessToken(): string
    {
        return $this->accessToken;
    }

    /**
     * @return string|null
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    /**
     * @return int
     */
    public function getPort(): int
    {
        return $this->port;
    }

    /**
     * @return string
     */
    public function getScheme(): string
    {
        return $this->scheme;
    }

    /**
     * @return string
     */
    public function getHost(): string
    {
        return $this->host;
    }
}
