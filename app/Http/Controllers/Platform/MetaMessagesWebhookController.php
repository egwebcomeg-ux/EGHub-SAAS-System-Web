<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Jobs\SendPlatformAutoReply;
use App\Models\PlatformConversation;
use App\Services\WhatsApp\PlatformAutoReply;
use App\Services\WhatsApp\PlatformInbox;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * What people write to the EgHub page on Messenger and on Instagram (/webhooks/meta), signed with the same Meta app as
 * the WhatsApp webhook, whose handshake it shares. Only the EgHub accounts are read: a restaurant's page never comes here.
 * An echo is our own answer, or a person of the team answering in Meta's own inbox, which quiets the automatic answer.
 * A comment on the page's posts and ads gets a private message and a public answer (PlatformInbox::answerComment).
 */
class MetaMessagesWebhookController extends Controller
{
    private const CHANNELS = ['page' => PlatformConversation::MESSENGER, 'instagram' => PlatformConversation::INSTAGRAM];

    public function receive(Request $request, PlatformInbox $inbox, PlatformAutoReply $autoReply): Response
    {
        $secret = (string) config('whatsapp.app_secret');
        $given = (string) $request->header('X-Hub-Signature-256', '');
        abort_unless($secret !== '' && $given !== '' && hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $given), 403);

        $channel = self::CHANNELS[(string) $request->input('object')] ?? null;
        $account = (string) ($channel === PlatformConversation::INSTAGRAM ? config('whatsapp.page.instagram_id') : config('whatsapp.page.id'));
        if ($channel === null || $account === '') {
            return response('', 200);
        }
        foreach ((array) $request->input('entry', []) as $entry) {
            if ((string) ($entry['id'] ?? '') !== $account) {
                continue;
            }
            foreach ((array) ($entry['messaging'] ?? []) as $event) {
                $this->event($channel, $account, (array) $event, $inbox, $autoReply);
            }
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $this->comment($channel, $account, (array) $change, $inbox);
            }
        }

        return response('', 200);
    }

    /**
     * A new comment on the page's post or ad: Facebook sends it on the «feed» field, Instagram on «comments». The page's
     * own comments (our public answers among them) and edits or deletions are left alone.
     *
     * @param  array<string, mixed>  $change
     */
    private function comment(string $channel, string $account, array $change, PlatformInbox $inbox): void
    {
        $value = (array) ($change['value'] ?? []);
        $from = (array) ($value['from'] ?? []);
        if ($channel === PlatformConversation::INSTAGRAM) {
            if (($change['field'] ?? '') !== 'comments') {
                return;
            }
            [$commentId, $postId, $text, $name] = [(string) ($value['id'] ?? ''), (string) ($value['media']['id'] ?? ''), (string) ($value['text'] ?? ''), (string) ($from['username'] ?? '')];
        } else {
            if (($change['field'] ?? '') !== 'feed' || ($value['item'] ?? '') !== 'comment' || ($value['verb'] ?? '') !== 'add') {
                return;
            }
            [$commentId, $postId, $text, $name] = [(string) ($value['comment_id'] ?? ''), (string) ($value['post_id'] ?? ''), (string) ($value['message'] ?? ''), (string) ($from['name'] ?? '')];
        }
        $sender = (string) ($from['id'] ?? '');
        if ($sender === '' || $sender === $account || $sender === (string) config('whatsapp.page.id')) {
            return;
        }
        // two calls to Meta: after the response, so Meta is not kept waiting
        app()->terminating(fn () => $inbox->answerComment($channel, $commentId, $postId, $sender, $name, mb_substr($text, 0, 1000)));
    }

    /** @param  array<string, mixed>  $event */
    private function event(string $channel, string $account, array $event, PlatformInbox $inbox, PlatformAutoReply $autoReply): void
    {
        $message = (array) ($event['message'] ?? []);
        $postback = (array) ($event['postback'] ?? []);
        $text = (string) ($message['text'] ?? $postback['title'] ?? '');
        $providerId = (string) ($message['mid'] ?? $postback['mid'] ?? '');
        if ($providerId === '' || ! empty($message['is_deleted']) || ! empty($message['is_unsupported'])) {
            return;
        }
        if (! empty($message['is_echo'])) {
            if ((string) ($message['app_id'] ?? '') !== (string) config('whatsapp.page.app_id')) {
                $inbox->answeredOnMeta($channel, (string) ($event['recipient']['id'] ?? ''), $providerId, $text);
            }

            return;
        }
        $sender = (string) ($event['sender']['id'] ?? '');
        if ($sender === $account) {
            return;
        }
        $kept = $inbox->receiveFromPage($channel, $sender, $providerId, $this->type((array) ($message['attachments'][0] ?? [])), $text);
        if (! $kept) {
            return;
        }
        if (blank($kept->conversation->name)) {
            app()->terminating(fn () => $inbox->fillPageName($kept->conversation));
        }
        if ($autoReply->shouldAnswer($kept->conversation)) {
            SendPlatformAutoReply::dispatchAfterResponse($kept->id);
        } elseif ($kept->type !== 'sticker') {
            $inbox->alertTeam($kept->conversation);
        }
    }

    /** @param  array<string, mixed>  $attachment  The first attachment: a share or a reel counts as a file the team opens in Meta. */
    private function type(array $attachment): string
    {
        $type = (string) ($attachment['type'] ?? '');
        if ($type === '') {
            return 'text';
        }
        if (isset($attachment['payload']['sticker_id'])) {
            return 'sticker';
        }

        return in_array($type, ['image', 'audio', 'video', 'location'], true) ? $type : 'document';
    }
}
