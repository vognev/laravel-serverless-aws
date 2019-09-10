<?php

namespace Laravel\Serverless\Aws;

class Runtime
{
    private $apiUrl;

    const AWSRequestID = 'AWSRequestID';
    const DeadlineMS = 'DeadlineMS';
    const FunctionARN = 'FunctionARN';
    const TraceID = 'TraceID';

    public function __construct(string $apiUrl)
    {
        $this->apiUrl = $apiUrl;
    }

    public function loop(callable $handler) : void
    {
        while (true) {
            [$event, $context] = $this->waitNextInvocation();

            try {
                $this->sendResponse($context, $handler($event));
            } catch (\Throwable $e) {
                $this->sendFailure($context, $e);
            }
        }
    }

    private function waitNextInvocation()
    {
        do {
            $body = @file_get_contents("http://{$this->apiUrl}/2018-06-01/runtime/invocation/next");
        } while ($body === false);

        $context = [];

        foreach ($http_response_header as $headerString) {
            if (false === strpos($headerString, ':'))
                continue;

            list ($header, $value) = explode(':', $headerString, 2);
            $value = trim($value);

            switch (strtolower($header)) {
                case 'lambda-runtime-aws-request-id':
                    $context[self::AWSRequestID] = $value; break;
                case 'lambda-runtime-deadline-ms':
                    $context[self::DeadlineMS] = $value; break;
                case 'lambda-runtime-invoked-function-arn':
                    $context[self::FunctionARN] = $value; break;
                case 'lambda-runtime-trace-id':
                    $context[self::TraceID] = $value; break;
            }
        }

        return [json_decode($body, true), $context];
    }

    private function sendResponse(array $context, $response)
    {
        $response = json_encode($response);

        file_get_contents("http://{$this->apiUrl}/2018-06-01/runtime/invocation/${context[self::AWSRequestID]}/response",
            null, stream_context_create(['http' => [
                'method' => 'POST',
                'content' => $response,
                'header' => [
                    sprintf("Content-Type: application/json"),
                    sprintf("Content-Length: %d", strlen($response)),
                ]
            ]]));
    }

    private function sendFailure(array $context, \Throwable $error)
    {
        if ($error instanceof \Exception) {
            $errorMessage = 'Uncaught ' . get_class($error) . ': ' . $error->getMessage();
        } else {
            $errorMessage = $error->getMessage();
        }

        printf(
            "Fatal error: %s in %s:%d\nStack trace:\n%s",
            $errorMessage,
            $error->getFile(),
            $error->getLine(),
            $error->getTraceAsString()
        );

        $response = json_encode([
            'errorMessage'  => $error->getMessage(),
            'errorType'     => get_class($error),
            'stackTrace'    => explode(PHP_EOL, $error->getTraceAsString()),
        ]);

        file_get_contents("http://{$this->apiUrl}/2018-06-01/runtime/invocation/${context[self::AWSRequestID]}/error",
            null, stream_context_create(['http' => [
                'method' => 'POST',
                'content' => $response,
                'header' => [
                    sprintf("Content-Type: application/json"),
                    sprintf("Content-Length: %d", strlen($response)),
                ]
            ]]));
    }
}
