<?php

/**
 * Available options (similar guzzle) for RollingCurl
 *      allow_redirects true|false default: true
 *      cookies path_of_cookie_jar default: null
 *      force_ip_resolve v4|v6 default: null
 *      proxy http://username:password@127.0.0.1:8080 default: null
 *      interface 127.0.0.1 default: null
 *      verify 0|2 default: 0
 *
 * @author  Mahmuthan Elbir <me@mahmuthanelbir.com.tr>
 * @license MIT
 */

use RollingCurl\RollingCurl;
use RollingCurl\Request;

class Mclient
{
    /**
     * @var RollingCurl
     */
    private $client;
    /**
     * @var array
     */
    private $requests;
    /**
     * @var array
     */
    private $responses;
    /**
     * @var int
     */
    private $timeout;
    /**
     * @var int
     */
    private $connectTimeout;
    /**
     * @var int
     */
    private $concurrency;

    /**
     * @var string
     */
    private $version = "1.0";

    /**
     *
     */
    public function __construct($concurrency = 50, $connectTimeout = 5, $timeout = 0)
    {
        $this->client = new RollingCurl();
        $this->setTimeout($timeout);
        $this->setConnectTimeout($connectTimeout);
        $this->setConcurrency($concurrency);
    }

    /**
     * @param string $method
     * @param string $url
     * @param array $headers
     * @param array $options
     * @param null $extra
     * @return void
     */
    public function request(string $method, string $url, array $headers = [], array $options = [], $extra = null): void
    {
        $this->requests[] = [
            "method" => strtoupper($method),
            "url" => $url,
            "headers" => array_change_key_case($headers),
            "options" => array_change_key_case($options),
            "extra" => $extra
        ];
    }

    /**
     * @param string $url
     * @param array|string $data
     * @param array $headers
     * @param array $options
     * @param null $extra
     * @return void
     */
    public function post(string $url, $data, array $headers = [], array $options = [], $extra = null): void
    {
        if (is_array($data)) {
            $options["form_params"] = $data;
            $options["post"] = http_build_query($data);
        } else {
            $options["body"] = $data;
        }
        $this->request("POST", $url, $headers, $options, $extra);
    }

    /**
     * @param string $url
     * @param array $data
     * @param array $headers
     * @param array $options
     * @param null $extra
     * @return void
     */
    public function get(string $url, array $data = [], array $headers = [], array $options = [], $extra = null): void
    {
        $query = '';
        if (!empty($data)) {
            $query = '?' . http_build_query($data);
            $options["query"] = $data;
            $options["_original_url"] = $url;
        }
        $this->request("GET", $url . $query, $headers, $options, $extra);
    }

    /**
     * @param bool $single
     * @return array
     */
    public function execute(bool $single = false): array
    {
        $this->generateResponses();
        return $single ? $this->responses[0] : $this->responses;
    }

    /**
     * @return int
     */
    public function getTimeout(): int
    {
        return $this->timeout;
    }

    /**
     * @param int $timeout
     */
    public function setTimeout(int $timeout): void
    {
        $this->timeout = $timeout;
    }

    /**
     * @return int
     */
    public function getConnectTimeout(): int
    {
        return $this->connectTimeout;
    }

    /**
     * @param int $connectTimeout
     */
    public function setConnectTimeout(int $connectTimeout): void
    {
        $this->connectTimeout = $connectTimeout;
    }

    /**
     * @return int
     */
    public function getConcurrency(): int
    {
        return $this->concurrency;
    }

    /**
     * @param int $concurrency
     */
    public function setConcurrency(int $concurrency): void
    {
        $this->concurrency = $concurrency;
    }

    /**
     * @return void
     */
    private function generateResponses(): void
    {
        try {
            $this->responses = [];
            $this->generateRequests();
            $this->client->setCallback(function (Request $request) {
                $this->responses[] = [
                    "code" => $request->getResponseInfo()["http_code"],
                    "body" => trim($request->getResponseText()) ?? '',
                    "headers" => $this->lines_to_headers(trim($request->getResponseHeaders()) ?? ''),
                    "request" => $request->identifierParams["request"],
                    "extra" => $request->identifierParams["extra"]
                ];
                $this->client->clearCompleted();
                $this->client->prunePendingRequestQueue();
            });
            $this->client->setSimultaneousLimit($this->getConcurrency());

            $this->client->execute();
            $this->requests = [];
        } catch (Exception $e) {
        }
    }

    /**
     * @return void
     */
    private function generateRequests(): void
    {
        foreach ($this->requests as $request) {
            $data = $request["options"]["post"] ?? $request["options"]["body"] ?? $request["options"]["query"] ?? null;
            $curl[CURLOPT_TIMEOUT] = $this->getTimeout();
            $curl[CURLOPT_CONNECTTIMEOUT] = $this->getConnectTimeout();
            $curl[CURLOPT_FOLLOWLOCATION] = $request["options"]["allow_redirects"] ?? true;
            $curl[CURLOPT_SSL_VERIFYPEER] = $request["options"]["verify"] ?? 0;
            $curl[CURLOPT_SSL_VERIFYHOST] = $request["options"]["verify"] ?? 0;
            if (!empty($request["options"]["cookies"])) {
                $curl[CURLOPT_COOKIEFILE] = $request["options"]["cookies"];
                $curl[CURLOPT_COOKIEJAR] = $request["options"]["cookies"];
            }
            if (!empty($request["options"]["force_ip_resolve"])) {
                $curl[CURLOPT_IPRESOLVE] = strtolower($request["options"]["force_ip_resolve"]) === "v6" ? CURL_IPRESOLVE_V6 : CURL_IPRESOLVE_V4;
            }
            if (!empty($request["options"]["proxy"])) {
                $curl[CURLOPT_PROXY] = $request["options"]["proxy"];
            } else if (!empty($request["options"]["interface"])) {
                $curl[CURLOPT_IPRESOLVE] = stristr($request["options"]["interface"], ":") ? CURL_IPRESOLVE_V6 : CURL_IPRESOLVE_V4;
                $curl[CURLOPT_INTERFACE] = $request["options"]["interface"];
            }
            if (empty($request["headers"]["user-agent"]))
                $request["headers"]["user-agent"] = "Mclient/" . $this->version;

            $new_request = $request;
            $new_request["url"] = $request["options"]["_original_url"] ?? $request["url"];
            unset($new_request["options"]["_original_url"]);
            unset($new_request["options"]["post"]);
            unset($new_request["extra"]);
            $this->client->request(
                $request["url"],
                $request["method"],
                $data,
                $this->array_to_headers($request["headers"]),
                array_replace_recursive($request["options"]["curl"] ?? [], $curl),
                [
                    "request" => $new_request,
                    "extra" => $request["extra"]
                ]);
        }
    }

    /**
     * @param string $lines
     * @return array
     */
    private function lines_to_headers(string $lines): array
    {
        $headers = [];
        $lines = array_filter(explode(PHP_EOL, trim($lines)));
        unset($lines[0]);
        foreach ($lines as $line) {
            $parts = explode(':', $line, 2);
            $headers[strtolower(trim($parts[0]))][] = isset($parts[1]) ? trim($parts[1]) : '';
        }

        return $headers;
    }

    /**
     * @param array $headers
     * @return array
     */
    private function array_to_headers(array $headers): array
    {
        $lines = [];
        foreach ($headers as $key => $val) {
            $lines[] = trim($key) . ': ' . trim($val);
        }
        return $lines;
    }

}
