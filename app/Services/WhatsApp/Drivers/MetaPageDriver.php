<?php

namespace App\Services\WhatsApp\Drivers;

use App\Models\PlatformConversation;
use App\Services\WhatsApp\WhatsAppException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Answers on the EgHub page's Messenger and Instagram (the Send API, with the page's token). Instagram takes 1000 bytes
 * a message, about 500 Arabic letters, so a longer answer goes out in parts on both channels.
 */
final class MetaPageDriver
{
    private const MAX_BYTES = 1000;

    public function __construct(private string $channel) {}

    /** The channel can answer: the page's token is set, and the id of the account people write to. */
    public static function connected(string $channel): bool
    {
        $account = $channel === PlatformConversation::INSTAGRAM ? config('whatsapp.page.instagram_id') : config('whatsapp.page.id');

        return filled(config('whatsapp.page.id')) && filled(config('whatsapp.page.token')) && filled($account);
    }

    public function reply(string $to, string $text): string
    {
        return $this->replyWithButtons($to, $text, []);
    }

    /**
     * Quick replies on the last part; a tap comes back as a message with the button's title.
     *
     * @param  array<string, string>  $buttons  id => title (20 characters at most)
     */
    public function replyWithButtons(string $to, string $text, array $buttons): string
    {
        $parts = $this->parts($text);
        $last = array_pop($parts);
        foreach ($parts as $part) {
            $this->post($to, ['text' => $part]);
        }
        $quickReplies = [];
        foreach ($buttons as $id => $title) {
            $quickReplies[] = ['content_type' => 'text', 'title' => $title, 'payload' => $id];
        }

        return $this->post($to, ['text' => $last] + ($quickReplies !== [] ? ['quick_replies' => $quickReplies] : []));
    }

    /**
     * The one private message Meta allows to a person who commented on the page (within 7 days of the comment): it goes
     * to their Messenger or Instagram inbox, and their answer opens the usual chat. Meta gives back their id there.
     *
     * @param  array<string, string>  $buttons  id => title
     * @return array{recipient: string, id: string}
     */
    public function privateReply(string $commentId, string $text, array $buttons = []): array
    {
        $quickReplies = [];
        foreach ($buttons as $id => $title) {
            $quickReplies[] = ['content_type' => 'text', 'title' => $title, 'payload' => $id];
        }
        $text = $this->parts($text)[0];
        $response = $this->send((string) config('whatsapp.page.id').'/messages', [
            'recipient' => ['comment_id' => $commentId],
            'message' => ['text' => $text] + ($quickReplies !== [] ? ['quick_replies' => $quickReplies] : []),
        ]);

        return ['recipient' => (string) ($response['recipient_id'] ?? ''), 'id' => (string) ($response['message_id'] ?? throw new WhatsAppException('Meta returned no message id.'))];
    }

    /** A public answer under the comment, from the page. */
    public function commentReply(string $commentId, string $text): string
    {
        $edge = $this->channel === PlatformConversation::INSTAGRAM ? 'replies' : 'comments';

        return (string) ($this->send($commentId.'/'.$edge, ['message' => $text])['id'] ?? '');
    }

    /** The name on the person's profile, when Meta gives it. */
    public function profileName(string $id): ?string
    {
        $fields = $this->channel === PlatformConversation::INSTAGRAM ? 'name,username' : 'first_name,last_name';
        try {
            $profile = Http::withToken((string) config('whatsapp.page.token'))->acceptJson()->timeout(5)->get($this->url($id), ['fields' => $fields]);
        } catch (ConnectionException) {
            return null;
        }
        $name = trim($profile->json('name') ?? $profile->json('username') ?? $profile->json('first_name').' '.$profile->json('last_name'));

        return $profile->successful() && $name !== '' ? mb_substr($name, 0, 120) : null;
    }

    /** @return non-empty-list<string> */
    private function parts(string $text): array
    {
        $parts = [];
        $text = trim($text);
        while (strlen($text) > self::MAX_BYTES) {
            $part = mb_strcut($text, 0, self::MAX_BYTES);
            $break = max((int) mb_strrpos($part, "\n"), (int) mb_strrpos($part, ' '));
            if ($break > 0) {
                $part = mb_substr($part, 0, $break);
            }
            $parts[] = $part;
            $text = ltrim(mb_substr($text, mb_strlen($part)));
        }
        $parts[] = $text;

        return $parts;
    }

    /** @param  array<string, mixed>  $message */
    private function post(string $to, array $message): string
    {
        $response = $this->send((string) config('whatsapp.page.id').'/messages', ['recipient' => ['id' => $to], 'messaging_type' => 'RESPONSE', 'message' => $message]);

        return (string) ($response['message_id'] ?? throw new WhatsAppException('Meta returned no message id.'));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function send(string $path, array $payload): array
    {
        if (! self::connected($this->channel)) {
            throw new WhatsAppException('The EgHub page is not connected (META_PAGE_ID / META_INSTAGRAM_ID / META_PAGE_TOKEN).');
        }
        $token = (string) config('whatsapp.page.token');
        try {
            $response = Http::withToken($token)->acceptJson()->timeout((int) config('whatsapp.timeout', 15))
                ->post($this->url($path), $payload);
        } catch (ConnectionException) {
            throw new WhatsAppException('Meta could not be reached.');
        }
        if ($response->failed()) {
            $error = (string) ($response->json('error.message') ?? 'HTTP '.$response->status());
            throw new WhatsAppException(mb_substr(str_replace($token, '***', $error), 0, 200), (int) $response->json('error.code', 0));
        }

        return (array) $response->json();
    }

    private function url(string $path): string
    {
        return rtrim((string) config('whatsapp.meta.base_url'), '/').'/'.config('whatsapp.meta.version').'/'.$path;
    }
}
