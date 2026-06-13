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
use Laravel\Horizon\Contracts\HighFailureRateDetectedNotification;
use Laravel\Horizon\Horizon;

class HighFailureRateDetected extends Notification implements HighFailureRateDetectedNotification
{
    use Queueable;

    /**
     * The number of recently failed jobs.
     *
     * @var int
     */
    public $failedJobs;

    /**
     * The configured failure threshold that was exceeded.
     *
     * @var int
     */
    public $threshold;

    /**
     * Create a new notification instance.
     *
     * @param  int  $failedJobs
     * @param  int  $threshold
     * @return void
     */
    public function __construct($failedJobs, $threshold)
    {
        $this->failedJobs = $failedJobs;
        $this->threshold = $threshold;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
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
            ->subject(config('horizon.name').': High Job Failure Rate Detected')
            ->greeting('Oh no! Something needs your attention.')
            ->line(sprintf(
                '%s jobs have failed recently, which exceeds the configured threshold of %s.',
                $this->failedJobs, $this->threshold
            ));
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
        $title = 'High Failure Rate Detected';
        $text = 'Oh no! Something needs your attention.';
        $imageUrl = 'https://laravel.com/assets/img/horizon-48px.png';

        $content = sprintf(
            '[%s] %s jobs have failed recently, which exceeds the configured threshold of %s.',
            config('horizon.name'),
            $this->failedJobs,
            $this->threshold
        );

        if (class_exists('\Illuminate\Notifications\Slack\SlackMessage') &&
            class_exists('\Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock') &&
            ! (is_string(Horizon::$slackWebhookUrl) && Str::startsWith(Horizon::$slackWebhookUrl, ['http://', 'https://']))) {
            return (new ChannelIdSlackMessage)
                ->username($fromName)
                ->image($imageUrl)
                ->text($text)
                ->headerBlock($title)
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
            ->attachment(function ($attachment) use ($title, $content) {
                $attachment->title($title)
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
        return (new NexmoMessage)->content(sprintf( // @phpstan-ignore-line
            '[%s] %s jobs have failed recently, which exceeds the configured threshold of %s.',
            config('horizon.name'), $this->failedJobs, $this->threshold
        ));
    }

    /**
     * The unique signature of the notification.
     *
     * @return string
     */
    public function signature()
    {
        return md5('high-failure-rate');
    }
}
