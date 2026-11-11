<?php

namespace Tests\Feature\Api\V1;

use App\Models\Document;
use App\Models\DocumentAccessGrant;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_matches_title_and_document_content(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('owner');
        $titleMatch = $this->createDocument($workspace, $owner, 'Budget forecast', 'Ordinary body');
        $contentMatch = $this->createDocument($workspace, $owner, 'Meeting notes', 'Contains Aurora project plan');
        $this->createDocument($workspace, $owner, 'Unrelated', 'Nothing relevant');
        Sanctum::actingAs($owner);

        $this->getJson($this->documentsUrl($workspace).'?search=aurora')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $contentMatch->id);

        $this->getJson($this->documentsUrl($workspace).'?search=BUDGET')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $titleMatch->id);
    }

    public function test_document_search_filters_by_type_workspace_and_creation_date(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('owner');
        [$otherWorkspace, $otherOwner] = $this->createWorkspaceMember('owner');
        $inRange = $this->createDocument($workspace, $owner, 'In range proposal', 'Proposal details', 'proposal', '2026-04-15 09:00:00');
        $wrongType = $this->createDocument($workspace, $owner, 'In range report', 'Report details', 'report', '2026-04-16 09:00:00');
        $outsideRange = $this->createDocument($workspace, $owner, 'Old proposal', 'Earlier proposal', 'proposal', '2026-03-31 23:59:59');
        $otherTenant = $this->createDocument($otherWorkspace, $otherOwner, 'In range proposal', 'Proposal details', 'proposal', '2026-04-15 09:00:00');
        Sanctum::actingAs($owner);

        $url = $this->documentsUrl($workspace)
            .'?type=proposal&workspace_id='.$workspace->id
            .'&created_from=2026-04-01&created_to=2026-04-30';
        $this->getJson($url)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inRange->id);

        $this->getJson($this->documentsUrl($workspace).'?workspace_id='.$otherWorkspace->id)
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertNotSame($wrongType->id, $inRange->id);
        $this->assertNotSame($outsideRange->id, $inRange->id);
        $this->assertNotSame($otherTenant->id, $inRange->id);
    }

    public function test_documents_can_be_sorted_by_created_at_updated_at_and_title(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('owner');
        $middle = $this->createDocument($workspace, $owner, 'Middle', 'Middle body', 'report', '2026-01-02 12:00:00');
        $alpha = $this->createDocument($workspace, $owner, 'Alpha', 'Alpha body', 'report', '2026-01-01 12:00:00');
        $zulu = $this->createDocument($workspace, $owner, 'Zulu', 'Zulu body', 'report', '2026-01-03 12:00:00');
        Document::query()->whereKey($middle->id)->update(['updated_at' => '2026-01-04 12:00:00']);
        Document::query()->whereKey($alpha->id)->update(['updated_at' => '2026-01-05 12:00:00']);
        Document::query()->whereKey($zulu->id)->update(['updated_at' => '2026-01-03 12:00:00']);
        Sanctum::actingAs($owner);

        $this->getJson($this->documentsUrl($workspace).'?sort=title')
            ->assertOk()
            ->assertJsonPath('data.0.id', $alpha->id)
            ->assertJsonPath('data.2.id', $zulu->id);
        $this->getJson($this->documentsUrl($workspace).'?sort=created_at')
            ->assertOk()
            ->assertJsonPath('data.0.id', $zulu->id)
            ->assertJsonPath('data.2.id', $alpha->id);
        $this->getJson($this->documentsUrl($workspace).'?sort=updated_at')
            ->assertOk()
            ->assertJsonPath('data.0.id', $alpha->id)
            ->assertJsonPath('data.2.id', $zulu->id);
    }

    public function test_pagination_uses_a_safe_default_and_enforces_maximum_page_size(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('owner');
        for ($i = 1; $i <= 23; $i++) {
            $this->createDocument($workspace, $owner, "Document {$i}", 'Content');
        }
        Sanctum::actingAs($owner);

        $this->getJson($this->documentsUrl($workspace))
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 23)
            ->assertJsonPath('meta.current_page', 1);
        $this->getJson($this->documentsUrl($workspace).'?per_page=10&page=2')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.current_page', 2);
        $this->getJson($this->documentsUrl($workspace).'?per_page=100')
            ->assertOk()
            ->assertJsonCount(23, 'data')
            ->assertJsonPath('meta.per_page', 100);

        $this->getJson($this->documentsUrl($workspace).'?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
    }

    public function test_private_documents_remain_hidden_but_explicitly_shared_documents_are_searchable(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('member');
        [, $reader] = $this->createWorkspaceMember('admin', $workspace);
        $private = $this->createDocument($workspace, $owner, 'Secret roadmap', 'secret phrase');
        $shared = $this->createDocument($workspace, $owner, 'Shared roadmap', 'secret phrase');
        DocumentAccessGrant::query()->create([
            'workspace_id' => $workspace->id,
            'document_id' => $shared->id,
            'user_id' => $reader->id,
            'granted_by_user_id' => $owner->id,
            'permission' => 'read',
        ]);
        Sanctum::actingAs($reader);

        $this->getJson($this->documentsUrl($workspace).'?search=secret')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $shared->id);
        $this->assertNotSame($private->id, $shared->id);
    }

    public function test_search_does_not_escape_workspace_scope_or_interpret_input_as_sql(): void
    {
        [$workspace, $owner] = $this->createWorkspaceMember('owner');
        [$otherWorkspace, $otherOwner] = $this->createWorkspaceMember('owner');
        $this->createDocument($workspace, $owner, 'Visible title', 'Visible content');
        $otherDocument = $this->createDocument($otherWorkspace, $otherOwner, 'Injected phrase', 'Other content');
        Sanctum::actingAs($owner);

        $this->getJson($this->documentsUrl($workspace).'?search=%27%20OR%201%3D1%20--')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson($this->documentsUrl($workspace).'?search=Injected')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->assertSame($otherWorkspace->id, $otherDocument->workspace_id);
    }

    private function createWorkspaceMember(string $role, ?Workspace $workspace = null): array
    {
        $user = User::factory()->create();
        $workspace ??= Workspace::query()->create([
            'created_by_user_id' => $user->id,
            'name' => 'Search Test Workspace',
            'slug' => 'search-'.fake()->unique()->slug(),
            'type' => 'institution',
        ]);
        WorkspaceMembership::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$workspace, $user];
    }

    private function createDocument(
        Workspace $workspace,
        User $owner,
        string $title,
        string $content,
        string $type = 'report',
        ?string $createdAt = null
    ): Document {
        $document = Document::query()->create([
            'workspace_id' => $workspace->id,
            'owner_user_id' => $owner->id,
            'created_by_user_id' => $owner->id,
            'title' => $title,
            'type' => $type,
            'language' => 'en',
            'visibility' => 'private',
            'status' => 'draft',
            'content_format' => 'html',
            'content_html' => "<p>{$content}</p>",
        ]);

        if ($createdAt !== null) {
            Document::query()->whereKey($document->id)->update([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }

        return $document;
    }

    private function documentsUrl(Workspace $workspace): string
    {
        return "/api/v1/workspaces/{$workspace->id}/documents";
    }
}
