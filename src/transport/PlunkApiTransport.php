<?php
/**
 * @copyright Copyright (c) Rareform
 */

namespace rareform\craftplunk\transport;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractApiTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Plunk API transport.
 */
class PlunkApiTransport extends AbstractApiTransport
{
    private string $apiKey;

    private string $apiBaseUrl;

    public function __construct(
        string $apiKey,
        string $apiBaseUrl = 'https://next-api.useplunk.com',
        ?HttpClientInterface $client = null,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->apiKey = $apiKey;
        $this->apiBaseUrl = rtrim($apiBaseUrl, '/');

        parent::__construct($client, $dispatcher, $logger);
    }

    public function __toString(): string
    {
        return sprintf('plunk+api://%s', preg_replace('#^https?://#', '', $this->apiBaseUrl));
    }

    /**
     * @inheritdoc
     */
    protected function doSendApi(SentMessage $sentMessage, Email $email, Envelope $envelope): ResponseInterface
    {
        if ($email->getCc() !== [] || $email->getBcc() !== []) {
            throw new TransportException('Plunk does not support separate CC or BCC recipients.');
        }

        $response = $this->client->request('POST', sprintf('%s/v1/send', $this->apiBaseUrl), [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => sprintf('Bearer %s', $this->apiKey),
            ],
            'json' => $this->getPayload($email, $envelope),
        ]);

        try {
            $statusCode = $response->getStatusCode();
            $result = $response->toArray(false);
        } catch (DecodingExceptionInterface) {
            throw new HttpTransportException(
                sprintf('Unable to send an email through Plunk: %s (HTTP %d).', $response->getContent(false), $response->getStatusCode()),
                $response
            );
        } catch (TransportExceptionInterface $exception) {
            throw new HttpTransportException('Could not reach the Plunk API.', $response, 0, $exception);
        }

        if ($statusCode < 200 || $statusCode >= 300 || ($result['success'] ?? true) === false) {
            throw new HttpTransportException($this->errorMessage($result, $statusCode), $response);
        }

        $messageId = $this->messageId($result);
        if ($messageId !== null) {
            $sentMessage->setMessageId($messageId);
        }

        return $response;
    }

    private function getPayload(Email $email, Envelope $envelope): array
    {
        $recipients = array_map(
            fn(Address $address): array => $this->addressPayload($address),
            $this->getRecipients($email, $envelope)
        );

        return array_filter([
            'to' => $recipients,
            'from' => $this->addressPayload($envelope->getSender()),
            'reply' => $this->replyTo($email),
            'subject' => $email->getSubject(),
            'body' => $this->body($email),
            'headers' => $this->headers($email),
            'attachments' => $this->attachments($email),
        ], fn($value): bool => $value !== null && $value !== []);
    }

    private function addressPayload(Address $address): array
    {
        return array_filter([
            'email' => $address->getAddress(),
            'name' => $address->getName() !== '' ? $address->getName() : null,
        ], fn($value): bool => $value !== null);
    }

    private function replyTo(Email $email): ?string
    {
        $replyTo = $email->getReplyTo();

        return $replyTo !== [] ? $replyTo[0]->getAddress() : null;
    }

    private function body(Email $email): string
    {
        if ($email->getHtmlBody() !== null && $email->getHtmlBody() !== '') {
            return $email->getHtmlBody();
        }

        if ($email->getTextBody() !== null && $email->getTextBody() !== '') {
            return nl2br(htmlspecialchars($email->getTextBody(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }

        return ' ';
    }

    private function headers(Email $email): array
    {
        $headers = [];
        $headersToBypass = [
            'bcc',
            'cc',
            'content-transfer-encoding',
            'content-type',
            'date',
            'from',
            'message-id',
            'mime-version',
            'reply-to',
            'sender',
            'subject',
            'to',
        ];

        foreach ($email->getHeaders()->all() as $name => $header) {
            if (in_array($name, $headersToBypass, true)) {
                continue;
            }

            $headers[$header->getName()] = $header->getBodyAsString();
        }

        return $headers;
    }

    private function attachments(Email $email): array
    {
        $attachments = [];

        foreach ($email->getAttachments() as $attachment) {
            $headers = $attachment->getPreparedHeaders();
            $filename = $headers->getHeaderParameter('Content-Disposition', 'filename');
            $disposition = $headers->getHeaderBody('Content-Disposition');

            $payload = [
                'filename' => $filename,
                'content' => $attachment->bodyToString(),
                'contentType' => $headers->get('Content-Type')->getBody(),
                'disposition' => $disposition === 'inline' ? 'inline' : 'attachment',
            ];

            if ($disposition === 'inline') {
                $payload['contentId'] = $attachment->hasContentId() ? $attachment->getContentId() : $filename;
            }

            $attachments[] = $payload;
        }

        return $attachments;
    }

    private function errorMessage(array $result, int $statusCode): string
    {
        $error = is_array($result['error'] ?? null) ? $result['error'] : [];
        $message = $error['message'] ?? $result['message'] ?? 'Unable to send an email through Plunk.';
        $code = $error['code'] ?? null;
        $requestId = $error['requestId'] ?? null;

        $details = [sprintf('HTTP %d', $statusCode)];
        if ($code) {
            $details[] = sprintf('code %s', $code);
        }
        if ($requestId) {
            $details[] = sprintf('request %s', $requestId);
        }

        return sprintf('%s (%s).', $message, implode(', ', $details));
    }

    private function messageId(array $result): ?string
    {
        $emails = $result['data']['emails'] ?? null;
        if (is_array($emails) && isset($emails[0]['email']) && is_string($emails[0]['email'])) {
            return $emails[0]['email'];
        }

        return null;
    }
}
