<?php
/**
 * @copyright Copyright (c) Rareform
 */

namespace rareform\craftplunk\tests\transport;

use PHPUnit\Framework\TestCase;
use rareform\craftplunk\transport\PlunkApiTransport;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

class PlunkApiTransportTest extends TestCase
{
    public function testPayloadMapsEmailToPlunkSendRequest(): void
    {
        $requests = [];
        $transport = $this->transport($requests);
        $email = (new Email())
            ->from(new Address('sender@example.com', 'Sender Name'))
            ->to(new Address('recipient@example.com', 'Recipient Name'))
            ->replyTo('reply@example.com')
            ->subject('Hello')
            ->html('<p>Hello world</p>');
        $email->getHeaders()->addTextHeader('X-Custom', 'Custom value');

        $sentMessage = $transport->send($email);

        self::assertSame('queued-email-id', $sentMessage->getMessageId());
        self::assertSame('POST', $requests[0]['method']);
        self::assertSame('https://api.example.test/v1/send', $requests[0]['url']);
        self::assertSame('Bearer sk_test', $this->header($requests[0]['options'], 'authorization'));

        $payload = $this->payload($requests[0]['options']);
        self::assertSame([
            [
                'email' => 'recipient@example.com',
                'name' => 'Recipient Name',
            ],
        ], $payload['to']);
        self::assertSame([
            'email' => 'sender@example.com',
            'name' => 'Sender Name',
        ], $payload['from']);
        self::assertSame('reply@example.com', $payload['reply']);
        self::assertSame('Hello', $payload['subject']);
        self::assertSame('<p>Hello world</p>', $payload['body']);
        self::assertSame(['X-Custom' => 'Custom value'], $payload['headers']);
    }

    public function testTextOnlyEmailBecomesHtmlBody(): void
    {
        $requests = [];
        $transport = $this->transport($requests);
        $email = (new Email())
            ->from('sender@example.com')
            ->to('recipient@example.com')
            ->subject('Hello')
            ->text("Hello <world>\nSecond line");

        $transport->send($email);

        $payload = $this->payload($requests[0]['options']);
        self::assertSame("Hello &lt;world&gt;<br />\nSecond line", $payload['body']);
    }

    public function testAttachmentsMapToPlunkPayload(): void
    {
        $requests = [];
        $transport = $this->transport($requests);
        $inlinePart = (new DataPart('inline file', 'inline.txt', 'text/plain'))->asInline();

        $email = (new Email())
            ->from('sender@example.com')
            ->to('recipient@example.com')
            ->subject('Files')
            ->html('<p>Files</p>')
            ->attach('regular file', 'regular.txt', 'text/plain')
            ->addPart($inlinePart);

        $transport->send($email);

        $attachments = $this->payload($requests[0]['options'])['attachments'];
        self::assertCount(2, $attachments);
        self::assertSame('regular.txt', $attachments[0]['filename']);
        self::assertSame('text/plain', $attachments[0]['contentType']);
        self::assertSame('attachment', $attachments[0]['disposition']);
        self::assertSame(base64_encode('regular file'), trim($attachments[0]['content']));
        self::assertSame('inline.txt', $attachments[1]['filename']);
        self::assertSame('inline', $attachments[1]['disposition']);
        self::assertArrayHasKey('contentId', $attachments[1]);
    }

    public function testPlunkErrorEnvelopeBecomesTransportException(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode([
            'success' => false,
            'error' => [
                'code' => 'VALIDATION_ERROR',
                'message' => 'Request validation failed',
                'requestId' => 'request-123',
            ],
        ]), [
            'http_code' => 422,
        ]));
        $transport = new PlunkApiTransport('sk_test', 'https://api.example.test', $client);
        $email = (new Email())
            ->from('sender@example.com')
            ->to('recipient@example.com')
            ->subject('Hello')
            ->html('<p>Hello</p>');

        $this->expectException(HttpTransportException::class);
        $this->expectExceptionMessage('Request validation failed (HTTP 422, code VALIDATION_ERROR, request request-123).');

        $transport->send($email);
    }

    public function testCcAndBccAreRejected(): void
    {
        $transport = new PlunkApiTransport('sk_test', 'https://api.example.test', new MockHttpClient());
        $email = (new Email())
            ->from('sender@example.com')
            ->to('recipient@example.com')
            ->cc('copy@example.com')
            ->subject('Hello')
            ->html('<p>Hello</p>');

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Plunk does not support separate CC or BCC recipients.');

        $transport->send($email);
    }

    private function transport(array &$requests): PlunkApiTransport
    {
        $client = new MockHttpClient(function(string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [
                'method' => $method,
                'url' => $url,
                'options' => $options,
            ];

            return new MockResponse(json_encode([
                'success' => true,
                'data' => [
                    'emails' => [
                        [
                            'email' => 'queued-email-id',
                        ],
                    ],
                ],
            ]));
        });

        return new PlunkApiTransport('sk_test', 'https://api.example.test', $client);
    }

    private function payload(array $options): array
    {
        if (isset($options['json'])) {
            return $options['json'];
        }

        return json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
    }

    private function header(array $options, string $name): ?string
    {
        foreach ($options['normalized_headers'] ?? [] as $header) {
            $header = is_array($header) ? implode(', ', $header) : $header;
            if (str_starts_with(strtolower($header), sprintf('%s: ', strtolower($name)))) {
                return substr($header, strlen($name) + 2);
            }
        }

        foreach ($options['headers'] ?? [] as $key => $value) {
            if (strtolower($key) === strtolower($name)) {
                return is_array($value) ? implode(', ', $value) : $value;
            }
        }

        return null;
    }
}
