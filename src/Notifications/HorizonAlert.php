<?php

namespace Laravel\Horizon\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\NexmoMessage;
use Illuminate\Notifications\Messages\SlackMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage as ChannelIdSlackMessage;
use Illuminate\Support\Str;
use Laravel\Horizon\Horizon;

abstract class HorizonAlert extends Notification
{
    use Queueable;

    /**
     * Get the subject line for the alert.
     *
     * @return string
     */
    abstract public function subject();

    /**
     * Get the human readable message body for the alert.
     *
     * @return string
     */
    abstract public function message();

    /**
     * The unique signature of the notification.
     *
     * @return string
     */
    abstract public function signature();

    /**
     * Get the short title used for chat notifications.
     *
     * @return string
     */
    public function title()
    {
        return $this->subject();
    }

    /**
     * Determine whether this alert is enabled.
     *
     * Subclasses tied to automatically-dispatched events override this to gate
     * delivery behind an opt-in config flag, so the package stays a drop-in
     * replacement that does not start paging existing installs on upgrade.
     *
     * @return bool
     */
    public function enabled()
    {
        return true;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        if (! $this->enabled()) {
            return [];
        }

        return array_filter([
            Horizon::$slackWebhookUrl ? 'slack' : null,
            Horizon::$smsNumber ? 'nexmo' : null,
            Horizon::$email ? 'mail' : null,
        ]);
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->error()
            ->subject(config('horizon.name').': '.$this->subject())
            ->greeting('Oh no! Something needs your attention.')
            ->line($this->message());
    }

    /**
     * Get the Slack representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\SlackMessage
     */
    public function toSlack($notifiable)
    {
        $fromName = 'Laravel Horizon';
        $text = 'Oh no! Something needs your attention.';
        $imageUrl = 'https://laravel.com/assets/img/horizon-48px.png';

        $content = '['.config('horizon.name').'] '.$this->message();

        if (class_exists('\Illuminate\Notifications\Slack\SlackMessage') &&
            class_exists('\Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock') &&
            ! (is_string(Horizon::$slackWebhookUrl) && Str::startsWith(Horizon::$slackWebhookUrl, ['http://', 'https://']))) {
            return (new ChannelIdSlackMessage)
                ->username($fromName)
                ->image($imageUrl)
                ->text($text)
                ->headerBlock($this->title())
                ->sectionBlock(function (SectionBlock $block) use ($content): void { // @phpstan-ignore-line
                    $block->text($content);
                });
        }

        return (new SlackMessage) // @phpstan-ignore-line
            ->from($fromName)
            ->to(Horizon::$slackChannel)
            ->image($imageUrl)
            ->error()
            ->content($text)
            ->attachment(function ($attachment) use ($content) {
                $attachment->title($this->title())
                    ->content($content);
            });
    }

    /**
     * Get the Nexmo / SMS representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\NexmoMessage
     */
    public function toNexmo($notifiable)
    {
        return (new NexmoMessage)->content( // @phpstan-ignore-line
            '['.config('horizon.name').'] '.$this->message()
        );
    }
}
