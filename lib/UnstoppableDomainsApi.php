<?php

class UnstoppableDomainsApi
{
    private $baseUrl;
    private $token;
    private $logger;

    public function __construct($baseUrl, $token, $logger = null)
    {
        $this->baseUrl = rtrim((string) $baseUrl, '/');
        $this->token = (string) $token;
        $this->logger = $logger;
    }

    public function get($path, array $query = [])
    {
        return $this->request('GET', $path, null, $query);
    }

    public function post($path, array $body = [], array $query = [])
    {
        return $this->request('POST', $path, $body, $query);
    }

    public function patch($path, array $body = [], array $query = [])
    {
        return $this->request('PATCH', $path, $body, $query);
    }

    public function put($path, array $body = [], array $query = [])
    {
        return $this->request('PUT', $path, $body, $query);
    }

    public function delete($path, array $body = [], array $query = [])
    {
        return $this->request('DELETE', $path, $body, $query);
    }

    public function action($actionName, array $body = [])
    {
        return $this->post('/mcp/v1/actions/' . ltrim($actionName, '/'), $body);
    }

    public function request($method, $path, $body = null, array $query = [])
    {
        if (!$this->isValidBaseUrl($this->baseUrl)) {
            return [
                'status' => 0,
                'body' => null,
                'raw' => null,
                'curl_error' => 'Invalid API base URL.',
                'url' => $this->baseUrl
            ];
        }

        $url = $this->baseUrl . '/' . ltrim($path, '/');
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/json',
            'Content-Type: application/json'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 90);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
        if ($body !== null && $method !== 'GET') {
            $payload = json_encode($body);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload === false ? '{}' : $payload);
        }

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = null;
        if (is_string($response) && $response !== '') {
            $decoded = json_decode($response, true);
        }

        if ($this->logger) {
            try {
                $this->logger->info('UD API ' . $method . ' ' . $url . ' [' . $status . ']');
            } catch (Exception $e) {
            }
        }

        return [
            'status' => $status,
            'body' => is_array($decoded) ? $decoded : null,
            'raw' => $response,
            'curl_error' => $curlError,
            'url' => $url,
            'method' => $method,
            'request_body' => $body
        ];
    }

    private function isValidBaseUrl($url)
    {
        $parts = @parse_url((string) $url);

        return is_array($parts)
            && !empty($parts['scheme'])
            && strtolower((string) $parts['scheme']) === 'https'
            && !empty($parts['host'])
            && empty($parts['user'])
            && empty($parts['pass'])
            && empty($parts['fragment']);
    }
}
