<?php

namespace Tests\Feature\Api\V1;

use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Models\WorkspaceMembership;
use App\Notifications\WorkspaceInvitationNotification;
use App\Services\WorkspaceInvitationService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class WorkspaceInvitationsAndMembersTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_invite_and_only_a_hash_is_stored_while_response_hides_token(): void
    {
        Notification::fake();
        [$owner, $workspace] = $this->createOwnerWorkspace();
        Sanctum::actingAs($owner);

        $response = $this->postJson($this->invitationsUrl($workspace), [
            'email' => ' Invitee@Example.Test ',
            'role' => 'staff',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.email', 'invitee@example.test')
            ->assertJsonPath('data.role', 'staff')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.token_hash');

        $invitation = WorkspaceInvitation::query()->sole();
        $this->assertSame('invitee@example.test', $invitation->email);
        $this->assertSame($invitation->email, $invitation->pending_email_key);
        $this->assertSame(64, strlen($invitation->token_hash));
        $this->assertGreaterThanOrEqual(
            7 * 24 * 60 * 60 - 1,
            (int) $invitation->created_at->diffInSeconds($invitation->expires_at)
        );

        $token = $this->sentInvitationToken();
        $this->assertNotSame($token, $invitation->token_hash);
        $this->assertSame(hash('sha256', $token), $invitation->token_hash);
        $url = $this->sentInvitationUrl();
        $this->assertStringStartsWith(rtrim(config('app.frontend_url'), '/').'/invitations/accept#token=', $url);
        $this->assertNull(parse_url($url, PHP_URL_QUERY));
        $this->assertInstanceOf(ShouldBeEncrypted::class, new WorkspaceInvitationNotification(
            'Workspace',
            'Inviter',
            'member',
            'test-token'
        ));
    }

    public function test_non_manager_cannot_create_or_list_workspace_invitations(): void
    {
        [$owner, $workspace] = $this->createOwnerWorkspace();
        $member = $this->createMember($workspace, 'member', 'active');
        Sanctum::actingAs($member);

        $this->postJson($this->invitationsUrl($workspace), [
            'email' => 'invitee@example.test',
            'role' => 'member',
        ])->assertNotFound();
        $this->getJson($this->invitationsUrl($workspace))->assertNotFound();
        $this->assertDatabaseCount('workspace_invitations', 0);
    }

    public function test_invitation_acceptance_requires_authentication(): void
    {
        $this->postJson('/api/v1/invitations/'.str_repeat('a', 64).'/accept')->assertUnauthorized();
        $this->postJson('/api/v1/invitations/'.str_repeat('a', 64).'/decline')->assertUnauthorized();
    }

    public function test_invitation_email_and_role_are_validated_and_owner_role_is_forbidden(): void
    {
        [$owner, $workspace] = $this->createOwnerWorkspace();
        Sanctum::actingAs($owner);

        $this->postJson($this->invitationsUrl($workspace), [
            'email' => 'not-an-email',
            'role' => 'member',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->postJson($this->invitationsUrl($workspace), [
            'email' => 'invitee@example.test',
            'role' => 'owner',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->postJson($this->invitationsUrl($workspace), [
            'email' => 'invitee@example.test',
            'role' => 'root',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->assertDatabaseCount('workspace_invitations', 0);
    }

    public function test_admin_can_invite_members_but_cannot_assign_admin_role(): void
    {
        Notification::fake();
        [$owner, $workspace] = $this->createOwnerWorkspace();
        $admin = $this->createMember($workspace, 'admin', 'active');
        Sanctum::actingAs($admin);

        $this->postJson($this->invitationsUrl($workspace), [
            'email' => 'member@example.test',
            'role' => 'member',
        ])->assertCreated();

        $this->postJson($this->invitationsUrl($workspace), [
            'email' => 'other-admin@example.test',
            'role' => 'admin',
        ])->assertForbidden();

        $this->assertDatabaseCount('workspace_invitations', 1);
        $this->assertSame('owner', $owner->workspaceMemberships()->where('workspace_id', $workspace->id)->value('role'));
    }

    public function test_duplicate_pending_invitation_is_prevented_case_insensitively(): void
    {
        Notification::fake();
        [$owner, $workspace] = $this->createOwnerWorkspace();
        Sanctum::actingAs($owner);

        $this->postJson($this->invitationsUrl($workspace), [
            'email' => 'duplicate@example.test',
            'role' => 'member',
        ])->assertCreated();

        $this->postJson($this->invitationsUrl($workspace), [
            'email' => ' DUPLICATE@EXAMPLE.TEST ',
            'role' => 'staff',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('workspace_invitations', 1);
    }

    public function test_invitation_delivery_refuses_mail_log_transport_to_prevent_token_logging(): void
    {
        Notification::fake();
        [$owner, $workspace] = $this->createOwnerWorkspace();
        Sanctum::actingAs($owner);

        config([
            'mail.mailers.audit_failover' => [
                'transport' => 'failover',
                'mailers' => ['smtp', 'log'],
            ],
        ]);

        foreach (['log', 'audit_failover'] as $mailer) {
            config(['mail.default' => $mailer]);
            $this->postJson($this->invitationsUrl($workspace), [
                'email' => 'no-log@example.test',
                'role' => 'member',
            ])->assertServiceUnavailable();
        }

        Notification::assertNothingSent();
        $this->assertDatabaseCount('workspace_invitations', 0);
    }

    public function test_database_unique_key_prevents_duplicate_pending_invites(): void
    {
        Notification::fake();
        [$owner, $workspace] = $this->createOwnerWorkspace();
        Sanctum::actingAs($owner);
        $this->postJson($this->invitationsUrl($workspace), [
            'email' => 'unique@example.test',
            'role' => 'member',
        ])->assertCreated();
        $invitation = WorkspaceInvitation::query()->sole();

        $this->expectException(QueryException::class);
        WorkspaceInvitation::query()->create([
            'workspace_id' => $workspace->id,
            'invited_by_user_id' => $owner->id,
            'email' => 'unique@example.test',
            'pending_email_key' => 'unique@example.test',
            'role' => 'member',
            'status' => 'pending',
            'token_hash' => hash('sha256', Str::random(64)),
            'expires_at' => now()->addDays(7),
        ]);
    }

    public function test_admin_invitation_cannot_restore_a_removed_owner_membership(): void
    {
        Notification::fake();
        [$owner, $workspace] = $this->createOwnerWorkspace();
        $formerOwner = $this->createMember($workspace, 'owner', 'removed', 'former-owner@example.test');
        $admin = $this->createMember($workspace, 'admin', 'active');
        Sanctum::actingAs($admin);

        $this->postJson($this->invitationsUrl($workspace), [
            'email' => $formerOwner->email,
            'role' => 'member',
        ])->assertCreated();
        $token = $this->sentInvitationToken();
        Sanctum::actingAs($formerOwner);

        $this->postJson('/api/v1/invitations/'.$token.'/accept')
            ->assertOk()
            ->assertJsonPath('data.membership.role', 'member')
            ->assertJsonPath('data.membership.status', 'active');

        $this->assertDatabaseHas('workspace_memberships', [
            'workspace_id' => $workspace->id,
            'user_id' => $formerOwner->id,
            'role' => 'member',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('workspace_memberships', [
            'workspace_id' => $workspace->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'status' => 'active',
        ]);
    }

    public function test_owner_can_list_and_revoke_pending_invitations_without_token_exposure(): void
    {
        Notification::fake();
        [$owner, $workspace] = $this->createOwnerWorkspace();
        Sanctum::actingAs($owner);
        $created = $this->postJson($this->invitationsUrl($workspace), [
            'email' => 'revoke@example.test',
            'role' => 'member',
        ])->assertCreated();

        $this->getJson($this->invitationsUrl($workspace))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'revoke@example.test')
            ->assertJsonMissingPath('data.0.token_hash');

        $this->deleteJson($this->invitationsUrl($workspace).'/'.$created->json('data.id'))
            ->assertOk();

        $this->assertDatabaseHas('workspace_invitations', [
            'id' => $created->json('data.id'),
            'status' => 'revoked',
            'pending_email_key' => null,
        ]);
    }

    public function test_invitation_creation_is_rate_limited(): void
    {
        Notification::fake();
        [$owner, $workspace] = $this->createOwnerWorkspace();
        Sanctum::actingAs($owner);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson($this->invitationsUrl($workspace), [
                'email' => 'rate'.$attempt.'@example.test',
                'role' => 'member',
            ])->assertCreated();
        }

        $this->postJson($this->invitationsUrl($workspace), [
            'email' => 'last@example.test',
            'role' => 'member',
        ])->assertTooManyRequests();
    }

    public function test_invitation_acceptance_activates_membership_atomically_for_matching_verified_email(): void
    {
        Notification::fake();
        [$owner, $workspace] = $this->createOwnerWorkspace();
        Sanctum::actingAs($owner);
        $this->postJson($this->invitationsUrl($workspace), [
            'email' => 'accepted@example.test',
            'role' => 'staff',
        ])->assertCreated();
        $token = $this->sentInvitationToken();
        $invitee = User::factory()->create(['email' => 'ACCEPTED@EXAMPLE.TEST']);
        Sanctum::actingAs($invitee);

        $response = $this->postJson('/api/v1/invitations/'.$token.'/accept');

        $response->assertOk()
            ->assertJsonPath('data.workspace.id', $workspace->id)
            ->assertJsonPath('data.membership.role', 'staff')
            ->assertJsonPath('data.membership.status', 'active')
            ->assertJsonMissingPath('data.token');

        $this->assertDatabaseHas('workspace_memberships', [
            'workspace_id' => $workspace->id,
            'user_id' => $invitee->id,
            'role' => 'staff',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('workspace_invitations', [
            'workspace_id' => $workspace->id,
            'status' => 'accepted',
            'pending_email_key' => null,
        ]);
        $this->assertSame(
            1,
            WorkspaceMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $invitee->id)->count()
        );
    }

    public function test_failed_membership_creation_rolls_back_invitation_acceptance(): void
    {
        Notification::fake();
        [$owner, $workspace] = $this->createOwnerWorkspace();
        Sanctum::actingAs($owner);
        $this->postJson($this->invitationsUrl($workspace), [
            'email' => 'transaction@example.test',
            'role' => 'member',
        ])->assertCreated();
        $token = $this->sentInvitationToken();
        $invitee = User::factory()->create(['email' => 'transaction@example.test']);
        $this->createFailingMembershipTrigger($invitee->id);

        try {
            app(WorkspaceInvitationService::class)->accept($invitee, $token);
            $this->fail('Membership failure should abort invitation acceptance.');
        } catch (QueryException) {
            $this->assertDatabaseHas('workspace_invitations', [
                'workspace_id' => $workspace->id,
                'status' => 'pending',
                'accepted_at' => null,
            ]);
            $this->assertDatabaseMissing('workspace_memberships', [
                'workspace_id' => $workspace->id,
                'user_id' => $invitee->id,
            ]);
        }
    }

    public function test_invitation_token_cannot_be_used_by_another_email_or_reused(): void
    {
        Notification::fake();
        [$owner, $workspace] = $this->createOwnerWorkspace();
        Sanctum::actingAs($owner);
        $this->postJson($this->invitationsUrl($workspace), [
            'email' => 'intended@example.test',
            'role' => 'member',
        ])->assertCreated();
        $token = $this->sentInvitationToken();
        Sanctum::actingAs(User::factory()->create(['email' => 'wrong@example.test']));

        $this->postJson('/api/v1/invitations/'.$token.'/accept')
            ->assertUnprocessable();
        $this->assertDatabaseCount('workspace_memberships', 1);

        Sanctum::actingAs(User::factory()->create(['email' => 'intended@example.test']));
        $this->postJson('/api/v1/invitations/'.$token.'/accept')->assertOk();
        $this->assertSame('[REDACTED]', Route::current()->parameter('token'));
        $this->assertStringNotContainsString($token, (string) request()->server->get('REQUEST_URI'));
        $this->postJson('/api/v1/invitations/'.$token.'/accept')->assertUnprocessable();
    }

    public function test_unverified_user_cannot_accept_an_invitation(): void
    {
        Notification::fake();
        [$owner, $workspace] = $this->createOwnerWorkspace();
        Sanctum::actingAs($owner);
        $this->postJson($this->invitationsUrl($workspace), [
            'email' => 'unverified@example.test',
            'role' => 'member',
        ])->assertCreated();
        $token = $this->sentInvitationToken();
        Sanctum::actingAs(User::factory()->unverified()->create(['email' => 'unverified@example.test']));

        $this->postJson('/api/v1/invitations/'.$token.'/accept')->assertForbidden();
        $this->assertDatabaseCount('workspace_memberships', 1);
    }

    public function test_expired_invitation_is_rejected_and_marked_expired(): void
    {
        Notification::fake();
        [$owner, $workspace] = $this->createOwnerWorkspace();
        Sanctum::actingAs($owner);
        $this->postJson($this->invitationsUrl($workspace), [
            'email' => 'expired@example.test',
            'role' => 'member',
        ])->assertCreated();
        $token = $this->sentInvitationToken();
        DB::table('workspace_invitations')->update(['expires_at' => now()->subSecond()]);
        Sanctum::actingAs(User::factory()->create(['email' => 'expired@example.test']));

        $this->postJson('/api/v1/invitations/'.$token.'/accept')->assertUnprocessable();

        $this->assertDatabaseHas('workspace_invitations', [
            'status' => 'expired',
            'pending_email_key' => null,
        ]);
        $this->assertDatabaseCount('workspace_memberships', 1);
    }

    public function test_revoked_invitation_cannot_be_accepted_and_decline_closes_a_pending_invitation(): void
    {
        Notification::fake();
        [$owner, $workspace] = $this->createOwnerWorkspace();
        Sanctum::actingAs($owner);
        $revoked = $this->postJson($this->invitationsUrl($workspace), [
            'email' => 'revoked@example.test',
            'role' => 'member',
        ])->assertCreated();
        $revokedToken = $this->sentInvitationToken();
        $this->deleteJson($this->invitationsUrl($workspace).'/'.$revoked->json('data.id'))->assertOk();

        Sanctum::actingAs(User::factory()->create(['email' => 'revoked@example.test']));
        $this->postJson('/api/v1/invitations/'.$revokedToken.'/accept')->assertUnprocessable();

        Sanctum::actingAs($owner);
        $declined = $this->postJson($this->invitationsUrl($workspace), [
            'email' => 'declined@example.test',
            'role' => 'member',
        ])->assertCreated();
        $declinedToken = $this->sentInvitationToken();
        Sanctum::actingAs(User::factory()->create(['email' => 'declined@example.test']));

        $this->postJson('/api/v1/invitations/'.$declinedToken.'/decline')
            ->assertOk()
            ->assertJsonPath('message', 'Invitation declined.');
        $this->assertDatabaseHas('workspace_invitations', [
            'id' => $declined->json('data.id'),
            'status' => 'declined',
            'pending_email_key' => null,
        ]);
        $this->postJson('/api/v1/invitations/'.$declinedToken.'/accept')->assertUnprocessable();
    }

    public function test_invitation_acceptance_does_not_change_an_existing_active_membership_role(): void
    {
        Notification::fake();
        [$owner, $workspace] = $this->createOwnerWorkspace();
        $existing = $this->createMember($workspace, 'admin', 'active', 'existing@example.test');
        Sanctum::actingAs($owner);
        $this->postJson($this->invitationsUrl($workspace), [
            'email' => $existing->email,
            'role' => 'member',
        ])->assertCreated();
        $token = $this->sentInvitationToken();
        Sanctum::actingAs($existing);

        $this->postJson('/api/v1/invitations/'.$token.'/accept')->assertOk();

        $this->assertDatabaseHas('workspace_memberships', [
            'workspace_id' => $workspace->id,
            'user_id' => $existing->id,
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    public function test_invitation_acceptance_uses_invited_role_instead_of_preserving_privileged_membership_role(): void
    {
        Notification::fake();
        [$owner, $workspace] = $this->createOwnerWorkspace();
        $invitee = $this->createMember($workspace, 'owner', 'invited', 'invited.role@example.test');
        Sanctum::actingAs($owner);
        $this->postJson($this->invitationsUrl($workspace), [
            'email' => $invitee->email,
            'role' => 'member',
        ])->assertCreated();
        $token = $this->sentInvitationToken();
        Sanctum::actingAs($invitee);

        $this->postJson('/api/v1/invitations/'.$token.'/accept')->assertOk();

        $this->assertDatabaseHas('workspace_memberships', [
            'workspace_id' => $workspace->id,
            'user_id' => $invitee->id,
            'role' => 'member',
            'status' => 'active',
        ]);
    }

    public function test_admin_can_manage_staff_but_cannot_self_escalate_or_modify_owner(): void
    {
        [$owner, $workspace] = $this->createOwnerWorkspace();
        $admin = $this->createMember($workspace, 'admin', 'active');
        $staff = $this->createMember($workspace, 'staff', 'active');
        Sanctum::actingAs($admin);

        $this->patchJson($this->membersUrl($workspace).'/'.$staff->workspaceMemberships()->where('workspace_id', $workspace->id)->value('id'), [
            'role' => 'member',
            'status' => 'suspended',
        ])->assertOk()
            ->assertJsonPath('data.role', 'member')
            ->assertJsonPath('data.status', 'suspended');

        $adminMembershipId = $admin->workspaceMemberships()->where('workspace_id', $workspace->id)->value('id');
        $ownerMembershipId = $owner->workspaceMemberships()->where('workspace_id', $workspace->id)->value('id');
        $this->patchJson($this->membersUrl($workspace).'/'.$adminMembershipId, ['role' => 'owner'])
            ->assertForbidden();
        $this->patchJson($this->membersUrl($workspace).'/'.$staff->workspaceMemberships()->where('workspace_id', $workspace->id)->value('id'), [
            'role' => 'admin',
        ])->assertForbidden();
        $this->patchJson($this->membersUrl($workspace).'/'.$ownerMembershipId, ['status' => 'suspended'])
            ->assertForbidden();
        $this->deleteJson($this->membersUrl($workspace).'/'.$ownerMembershipId)->assertForbidden();
        $this->assertDatabaseHas('workspace_memberships', [
            'id' => $ownerMembershipId,
            'role' => 'owner',
            'status' => 'active',
        ]);
    }

    public function test_admin_cannot_modify_their_own_membership(): void
    {
        [, $workspace] = $this->createOwnerWorkspace();
        $admin = $this->createMember($workspace, 'admin', 'active');
        Sanctum::actingAs($admin);
        $membershipId = $admin->workspaceMemberships()->where('workspace_id', $workspace->id)->value('id');

        $this->patchJson($this->membersUrl($workspace).'/'.$membershipId, ['role' => 'member'])
            ->assertForbidden();
        $this->deleteJson($this->membersUrl($workspace).'/'.$membershipId)->assertForbidden();
    }

    public function test_invited_membership_cannot_be_activated_without_accepting_invitation(): void
    {
        [$owner, $workspace] = $this->createOwnerWorkspace();
        $invitee = $this->createMember($workspace, 'member', 'invited');
        Sanctum::actingAs($owner);
        $membershipId = $invitee->workspaceMemberships()->where('workspace_id', $workspace->id)->value('id');

        $this->patchJson($this->membersUrl($workspace).'/'.$membershipId, ['status' => 'active'])
            ->assertConflict();
        $this->assertDatabaseHas('workspace_memberships', [
            'id' => $membershipId,
            'status' => 'invited',
        ]);
    }

    public function test_owner_can_manage_members_and_transfer_owner_role_without_leaving_workspace_ownerless(): void
    {
        [$owner, $workspace] = $this->createOwnerWorkspace();
        $secondOwner = $this->createMember($workspace, 'staff', 'active');
        Sanctum::actingAs($owner);
        $ownerMembershipId = $owner->workspaceMemberships()->where('workspace_id', $workspace->id)->value('id');
        $secondOwnerMembershipId = $secondOwner->workspaceMemberships()->where('workspace_id', $workspace->id)->value('id');

        $this->patchJson($this->membersUrl($workspace).'/'.$secondOwnerMembershipId, ['role' => 'owner'])
            ->assertOk();
        $this->patchJson($this->membersUrl($workspace).'/'.$ownerMembershipId, ['role' => 'member'])
            ->assertOk();

        $this->assertDatabaseHas('workspace_memberships', [
            'id' => $ownerMembershipId,
            'role' => 'member',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('workspace_memberships', [
            'id' => $secondOwnerMembershipId,
            'role' => 'owner',
            'status' => 'active',
        ]);
    }

    public function test_last_active_owner_cannot_be_demoted_suspended_or_removed(): void
    {
        [$owner, $workspace] = $this->createOwnerWorkspace();
        Sanctum::actingAs($owner);
        $membershipId = $owner->workspaceMemberships()->where('workspace_id', $workspace->id)->value('id');
        $url = $this->membersUrl($workspace).'/'.$membershipId;

        $this->patchJson($url, ['role' => 'admin'])->assertConflict();
        $this->patchJson($url, ['status' => 'suspended'])->assertConflict();
        $this->deleteJson($url)->assertConflict();
        $this->assertDatabaseHas('workspace_memberships', [
            'id' => $membershipId,
            'role' => 'owner',
            'status' => 'active',
        ]);
    }

    public function test_member_listing_is_scoped_to_workspace_and_active_manager(): void
    {
        [$owner, $workspace] = $this->createOwnerWorkspace();
        $member = $this->createMember($workspace, 'member', 'active');
        Sanctum::actingAs($owner);

        $this->getJson($this->membersUrl($workspace))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $member->id, 'email' => $member->email]);

        $otherWorkspace = $this->createOwnerWorkspace()[1];
        $this->getJson($this->membersUrl($otherWorkspace))->assertNotFound();
        Sanctum::actingAs($member);
        $this->getJson($this->membersUrl($workspace))->assertNotFound();
    }

    public function test_removing_member_immediately_denies_workspace_document_access(): void
    {
        [$owner, $workspace] = $this->createOwnerWorkspace();
        $member = $this->createMember($workspace, 'member', 'active');
        $document = Document::query()->create([
            'workspace_id' => $workspace->id,
            'owner_user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'title' => 'Workspace document',
            'type' => 'report',
            'language' => 'en',
            'visibility' => 'workspace',
            'status' => 'draft',
            'content_format' => 'html',
            'content_html' => '<p>Shared</p>',
            'word_count' => 1,
            'version' => 1,
        ]);
        Sanctum::actingAs($owner);
        $membershipId = $member->workspaceMemberships()->where('workspace_id', $workspace->id)->value('id');
        $this->deleteJson($this->membersUrl($workspace).'/'.$membershipId)->assertOk();
        Sanctum::actingAs($member);

        $this->getJson('/api/v1/workspaces/'.$workspace->id.'/documents/'.$document->id)->assertForbidden();
    }

    public function test_workspace_owner_cannot_read_another_members_private_document_without_a_grant(): void
    {
        [$owner, $workspace] = $this->createOwnerWorkspace();
        $documentOwner = $this->createMember($workspace, 'member', 'active');
        $document = Document::query()->create([
            'workspace_id' => $workspace->id,
            'owner_user_id' => $documentOwner->id,
            'created_by_user_id' => $documentOwner->id,
            'title' => 'Private document',
            'type' => 'report',
            'language' => 'en',
            'visibility' => 'private',
            'status' => 'draft',
            'content_format' => 'html',
            'content_html' => '<p>Private</p>',
            'word_count' => 1,
            'version' => 1,
        ]);
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/workspaces/'.$workspace->id.'/documents/'.$document->id)
            ->assertNotFound();
    }

    private function createOwnerWorkspace(): array
    {
        $owner = User::factory()->create();
        $workspace = new Workspace;
        $workspace->created_by_user_id = $owner->id;
        $workspace->name = 'Workspace '.Str::random(8);
        $workspace->slug = 'workspace-'.Str::uuid();
        $workspace->type = 'institution';
        $workspace->save();
        WorkspaceMembership::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$owner, $workspace];
    }

    private function createMember(
        Workspace $workspace,
        string $role,
        string $status,
        ?string $email = null
    ): User {
        $user = User::factory()->create([
            'email' => $email ?? Str::uuid().'@example.test',
        ]);
        WorkspaceMembership::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => $status,
            'joined_at' => $status === 'active' ? now() : null,
        ]);

        return $user;
    }

    private function invitationsUrl(Workspace $workspace): string
    {
        return '/api/v1/workspaces/'.$workspace->id.'/invitations';
    }

    private function membersUrl(Workspace $workspace): string
    {
        return '/api/v1/workspaces/'.$workspace->id.'/members';
    }

    private function sentInvitationToken(): string
    {
        $token = null;
        Notification::assertSentOnDemand(
            WorkspaceInvitationNotification::class,
            function (
                WorkspaceInvitationNotification $notification,
                array $channels,
                object $notifiable
            ) use (&$token): bool {
                parse_str((string) parse_url($notification->toMail($notifiable)->actionUrl, PHP_URL_FRAGMENT), $query);
                $token = $query['token'] ?? null;

                return in_array('mail', $channels, true) && is_string($token);
            }
        );

        return $token;
    }

    private function sentInvitationUrl(): string
    {
        $url = null;
        Notification::assertSentOnDemand(
            WorkspaceInvitationNotification::class,
            function (
                WorkspaceInvitationNotification $notification,
                array $channels,
                object $notifiable
            ) use (&$url): bool {
                $url = $notification->toMail($notifiable)->actionUrl;

                return true;
            }
        );

        return $url;
    }

    private function createFailingMembershipTrigger(string $inviteeId): void
    {
        $driver = DB::connection()->getDriverName();
        $suffix = bin2hex(random_bytes(8));
        $triggerName = 'fail_invitee_membership_'.$suffix;
        $functionName = $triggerName.'_fn';
        $message = 'membership failure';

        if ($driver === 'sqlite') {
            DB::statement(
                "CREATE TRIGGER {$triggerName} BEFORE INSERT ON workspace_memberships "
                ."WHEN NEW.user_id = '{$inviteeId}' BEGIN SELECT RAISE(FAIL, '{$message}'); END"
            );

            return;
        }

        if ($driver === 'pgsql') {
            DB::unprepared(
                "CREATE FUNCTION {$functionName}() RETURNS trigger AS \$\$ "
                .'BEGIN '
                ."IF NEW.user_id = '{$inviteeId}'::uuid THEN "
                ."RAISE EXCEPTION '{$message}'; "
                .'END IF; '
                .'RETURN NEW; '
                .'END; $$ LANGUAGE plpgsql'
            );
            DB::unprepared(
                "CREATE TRIGGER {$triggerName} BEFORE INSERT ON workspace_memberships "
                ."FOR EACH ROW EXECUTE FUNCTION {$functionName}()"
            );

            return;
        }

        throw new RuntimeException("Unsupported test database driver: {$driver}");
    }
}
