<?php

namespace Laravel\Serverless\Aws;

class RequestFactory
{
    public static function fromPayload(array &$event) : \Illuminate\Http\Request
    {
        // get rid of potentially hudge content in memory asap
        $content = fopen('php://temp', 'w+');
        if ($event['isBase64Encoded'] ?? false) {
            stream_filter_append($content, 'convert.base64-decode', STREAM_FILTER_WRITE);
        }

        if (array_key_exists('body', $event)) {
            fwrite($content, (string) $event['body']);
            unset($event['body']);
            rewind($content);
        }

        // pick which headers container to use
        $headers = array_change_key_case(
            (array) ($event['multiValueHeaders'] ?? $event['headers']),
            CASE_LOWER);

        $queryString = self::makeQueryString(
            (array) ($event['multiValueQueryStringParameters'] ?? $event['queryStringParameters'])
        );

        $request = []; $files = [];
        parse_str($queryString, $query);

        self::parseRequest($headers, $content, $request, $files);
        $cookies = self::parseCookies($headers);

        $contextPath = $event['requestContext']['path'] ?? null;
        if (is_null($contextPath) || $contextPath == $event['path']) {
            $baseUrl = '';
        } else {
            $baseUrl = str_replace($event['path'], '', $contextPath);
        }

        $server  = self::parseServer($headers) + [
            'REQUEST_METHOD' => strtoupper($event['httpMethod'] ?? 'GET'),
            'REQUEST_URI' => implode('', [
                $baseUrl,
                ($event['path'] ?? '/'),
                ($queryString ? '?' : ''),
                $queryString
            ]),
            'SERVER_NAME' => 'localhost',
            'SERVER_PORT' => 80,
            'REMOTE_ADDR' => '127.0.0.1',
            'SCRIPT_FILENAME' => public_path('index.php'),
            'SCRIPT_NAME' => $baseUrl . '/index.php'
        ];

        return new \Illuminate\Http\Request(
            $query, $request, [], $cookies, $files, $server, $content
        );
    }

    private static function parseCookies(array &$headers) : array
    {
        $cookies = [];

        foreach ($headers['cookie'] ?? [] as $cookies) {
            foreach ((array) $cookies as $cookieString) {
                parse_str(strtr($cookieString, array('&' => '%26', '+' => '%2B', ';' => '&')), $cookies);
            }
        }

        return $cookies;
    }

    private static function parseServer(array &$headers) : array
    {
        $server = [];
        foreach ($headers as $headerName => $headerStrings) {
            foreach ((array) $headerStrings as $headerString) {
                $serverHeaderName = 'HTTP_' . strtoupper(str_replace('-', '_', $headerName));
                $server[$serverHeaderName] = $headerString;
            }
        }

        return $server;
    }

    private static function parseRequest(array &$headers, &$content, array &$request, array &$files) : void
    {
        if (array_key_exists('content-type', $headers)) {
            $contentType = current((array) $headers['content-type']);
        }

        if (!isset($contentType) || !$contentType) {
            // parse as form data on default
            $contentType = 'application/x-www-form-urlencoded';
        }

        try {
            switch (true) {
                case 0 === stripos($contentType, 'application/x-www-form-urlencoded'):
                    parse_str(stream_get_contents($content), $request);
                    break;
                case 0 === stripos($contentType, 'application/json'):
                    $request = json_decode(stream_get_contents($content), true);
                    break;
                case 0 === stripos($contentType, 'multipart/form-data');
                    $data = new \Laravel\Serverless\MultipartParser($content);
                    $request = $data->getFormData(); $files = $data->getFiles();
                    break;
                default:
                    break; // or throw ?
            }
        } finally {
            rewind($content);
        }
    }

    private static function makeQueryString(array $queryParams) : string
    {
        $queryString = '';

        foreach ($queryParams as $paramName => $paramArray) {
            foreach ((array) $paramArray as $paramValue) {
                $queryString .= '&' . $paramName . '=' . urlencode($paramValue);
            }
        }

        return ltrim($queryString, '&');
    }
}
