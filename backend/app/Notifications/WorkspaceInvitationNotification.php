<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkspaceInvitationNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $workspaceName,
        private readonly string $inviterName,
        private readonly string $role,
        #[\SensitiveParameter]
        private readonly string $token,
    ) {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $acceptUrl = rtrim((string) config('app.frontend_url'), '/').'/invitations/accept#'.http_build_query([
            'token' => $this->token,
        ]);

        return (new MailMessage)
            ->subject("Invitation to join {$this->workspaceName}")
            ->greeting('You have been invited to join a workspace')
            ->line("{$this->inviterName} invited you to join {$this->workspaceName} as a {$this->role}.")
            ->action('Review invitation', $acceptUrl)
            ->line('This invitation expires in '.(int) config('workspaces.invitation_expiration_days', 7).' days.');
    }
}
