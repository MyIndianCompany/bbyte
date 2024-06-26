<?php

namespace App\Listeners;

use App\Events\CommentNotification;
use App\Models\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendCommentNotification
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(CommentNotification $event): void
    {
        $data = [
            'commented_by' => $event->user->id,
            'post_id' => $event->post->id,
            'commented_by_profile_picture' => $event->user->profile->profile_picture,
            'username' => $event->user->profile->username,
            'post_video_url' => $event->post->file_url,
        ];

        Notification::create([
            'user_id' => $event->post->user_id,
            'type' => 'comment',
            'data' => $data,
        ]);
    }
}
