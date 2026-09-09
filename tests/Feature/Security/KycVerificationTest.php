<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\KycDocumentType;
use App\Enums\KycStatus;
use App\Enums\UserStatus;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\Security\KycVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class KycVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $player;
    private User $admin;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'player', 'guard_name' => 'web']);

        $this->player = User::factory()->create(['status' => UserStatus::Active]);
        $this->player->assignRole('player');

        $this->admin = User::factory()->create(['status' => UserStatus::Active]);
        $this->admin->assignRole('admin');

        $this->token = $this->player->createToken('test_kyc_token')->plainTextToken;
    }

    public function test_player_can_fetch_initial_unverified_kyc_status(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/kyc/status');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'kyc_status' => 'unverified',
                    'is_verified' => false,
                    'documents' => [],
                ],
            ]);
    }

    public function test_player_can_upload_valid_identity_document(): void
    {
        $file = UploadedFile::fake()->create('passport.pdf', 500, 'application/pdf');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/v1/kyc/upload', [
                'document_type' => KycDocumentType::Passport->value,
                'document_number' => 'AB1234567',
                'document' => $file,
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'document' => [
                        'document_type' => 'passport',
                        'status' => 'pending',
                        'original_filename' => 'passport.pdf',
                    ],
                ],
            ]);

        $this->assertDatabaseHas('kyc_documents', [
            'user_id' => $this->player->id,
            'document_type' => 'passport',
            'status' => 'pending',
        ]);
    }

    public function test_kyc_upload_rejects_disallowed_mime_type(): void
    {
        $file = UploadedFile::fake()->create('malicious.exe', 100, 'application/x-msdownload');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/v1/kyc/upload', [
                'document_type' => KycDocumentType::Passport->value,
                'document' => $file,
            ]);

        $response->assertStatus(422);
    }

    public function test_compliance_admin_can_review_and_verify_document(): void
    {
        $service = app(KycVerificationService::class);
        $file = UploadedFile::fake()->create('id_card.png', 200, 'image/png');

        $doc = $service->submitDocument(
            user: $this->player,
            type: KycDocumentType::NationalId,
            file: $file,
            documentNumber: '1234567890123',
        );

        $this->assertEquals(KycStatus::Pending, $doc->status);

        $service->reviewDocument(
            document: $doc,
            reviewer: $this->admin,
            approved: true,
        );

        $doc->refresh();
        $this->assertEquals(KycStatus::Verified, $doc->status);
        $this->assertEquals($this->admin->id, $doc->verified_by);
        $this->assertTrue($this->player->kycStatus()->isVerified());
    }

    public function test_compliance_admin_can_reject_document_with_reason(): void
    {
        $service = app(KycVerificationService::class);
        $file = UploadedFile::fake()->create('blurry_id.jpg', 150, 'image/jpeg');

        $doc = $service->submitDocument(
            user: $this->player,
            type: KycDocumentType::NationalId,
            file: $file,
        );

        $service->reviewDocument(
            document: $doc,
            reviewer: $this->admin,
            approved: false,
            rejectionReason: 'Image is too blurry to verify details.',
        );

        $doc->refresh();
        $this->assertEquals(KycStatus::Rejected, $doc->status);
        $this->assertEquals('Image is too blurry to verify details.', $doc->rejection_reason);
    }
}
