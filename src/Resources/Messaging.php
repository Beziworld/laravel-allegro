<?php

declare(strict_types=1);

namespace Beziworld\Allegro\Resources;

use Beziworld\Allegro\AllegroClient;
use Beziworld\Allegro\Data\Message;
use Beziworld\Allegro\Data\MessageThread;
use Beziworld\Allegro\Support\Read;

/**
 * Message center: conversations with buyers (scope allegro:api:messaging).
 *
 * Allegro limits sending to one message per second per seller.
 */
final readonly class Messaging
{
    public function __construct(private AllegroClient $client) {}

    /**
     * Conversations, most recent first.
     *
     * @param array<string, mixed> $filters e.g. ['read' => false, 'status' => 'OPEN', 'orderId' => '...'].
     * @param int $limit 1 to 20.
     * @return list<MessageThread>
     */
    public function threads(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $data = $this->client->request('GET', '/messaging/threads', array_merge($filters, [
            'limit' => max(1, min(20, $limit)),
            'offset' => max(0, $offset),
        ]));

        return array_map(MessageThread::fromArray(...), Read::rows($data, 'threads'));
    }

    public function thread(string $threadId): MessageThread
    {
        return MessageThread::fromArray($this->client->request('GET', '/messaging/threads/' . rawurlencode($threadId)));
    }

    /**
     * Messages of a conversation, newest first.
     *
     * @param array<string, mixed> $filters e.g. ['after' => now()->subDay()] or ['before' => ...].
     * @param int $limit 1 to 20.
     * @return list<Message>
     */
    public function messages(string $threadId, array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $data = $this->client->request('GET', '/messaging/threads/' . rawurlencode($threadId) . '/messages', array_merge($filters, [
            'limit' => max(1, min(20, $limit)),
            'offset' => max(0, $offset),
        ]));

        return array_map(Message::fromArray(...), Read::rows($data, 'messages'));
    }

    public function message(string $messageId): Message
    {
        return Message::fromArray($this->client->request('GET', '/messaging/messages/' . rawurlencode($messageId)));
    }

    /**
     * Replies in an existing conversation.
     *
     * @param list<string> $attachmentIds Ids returned by uploadAttachment().
     */
    public function reply(string $threadId, string $text, array $attachmentIds = []): Message
    {
        return Message::fromArray($this->client->request('POST', '/messaging/threads/' . rawurlencode($threadId) . '/messages', [], array_filter([
            'text' => $text,
            'attachments' => self::attachments($attachmentIds),
        ], static fn(mixed $value): bool => $value !== null)));
    }

    /**
     * Writes to a buyer about an order, starting a conversation if there is none.
     *
     * @param list<string> $attachmentIds
     */
    public function send(string $recipientLogin, string $text, string $orderId, array $attachmentIds = []): Message
    {
        return Message::fromArray($this->client->request('POST', '/messaging/messages', [], array_filter([
            'recipient' => ['login' => $recipientLogin],
            'text' => $text,
            'order' => ['id' => $orderId],
            'attachments' => self::attachments($attachmentIds),
        ], static fn(mixed $value): bool => $value !== null)));
    }

    public function markRead(string $threadId, bool $read = true): void
    {
        $this->client->send('PUT', '/messaging/threads/' . rawurlencode($threadId) . '/read', [], ['read' => $read]);
    }

    public function deleteMessage(string $messageId): void
    {
        $this->client->send('DELETE', '/messaging/messages/' . rawurlencode($messageId));
    }

    /**
     * Declares and uploads an attachment (png, gif, bmp, tiff, jpeg or pdf, up to 5 MB); returns its id.
     */
    public function uploadAttachment(string $fileName, string $contents, string $contentType = 'application/pdf'): string
    {
        $declared = $this->client->request('POST', '/messaging/message-attachments', [], ['filename' => $fileName, 'size' => strlen($contents)]);
        $id = Read::string($declared, 'id') ?? '';

        $this->client->sendBinary('PUT', '/messaging/message-attachments/' . rawurlencode($id), $contents, $contentType);

        return $id;
    }

    /**
     * Downloads an attachment as binary content.
     */
    public function attachment(string $attachmentId): string
    {
        return $this->client->send('GET', '/messaging/message-attachments/' . rawurlencode($attachmentId), accept: '*/*')->body();
    }

    /**
     * @param list<string> $ids
     * @return list<array{id: string}>|null
     */
    private static function attachments(array $ids): ?array
    {
        return $ids !== [] ? array_map(static fn(string $id): array => ['id' => $id], $ids) : null;
    }
}
