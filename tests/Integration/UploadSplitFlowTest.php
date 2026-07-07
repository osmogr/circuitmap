<?php

declare(strict_types=1);

namespace CircuitMap\Tests\Integration;

use CircuitMap\Controllers\CircuitController;
use CircuitMap\Models\CircuitProviderRepository;
use CircuitMap\Models\CircuitRepository;
use CircuitMap\Tests\Support\DatabaseTestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\UploadedFile;

/**
 * Exercises the multi-folder split flow end to end at the controller level
 * (real DB, real file/pending storage, real KML pipeline), same scope as
 * UploadFlowTest: upload() returning the folder preview, then
 * confirmSplit() creating N circuits (or one, in single mode).
 */
final class UploadSplitFlowTest extends DatabaseTestCase
{
    private CircuitController $controller;
    private CircuitRepository $circuits;
    private CircuitProviderRepository $providers;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = $this->createUser();
        $this->circuits = new CircuitRepository($this->pdo);
        $this->providers = new CircuitProviderRepository($this->pdo);
        $this->controller = $this->makeCircuitController();
    }

    private function fixture(string $name): string
    {
        return dirname(__DIR__) . '/fixtures/' . $name;
    }

    private function uploadRequest(array $formFields, string $fixture)
    {
        $path = $this->fixture($fixture);
        $file = new UploadedFile($path, basename($path), null, filesize($path), UPLOAD_ERR_OK);

        return (new ServerRequestFactory())->createServerRequest('POST', '/upload')
            ->withParsedBody($formFields)
            ->withUploadedFiles(['kml_file' => $file])
            ->withAttribute('currentUser', ['id' => $this->userId, 'username' => 'testuser', 'role' => 'editor']);
    }

    private function confirmRequest(array $formFields, ?int $userId = null)
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/upload/confirm-split')
            ->withParsedBody($formFields)
            ->withAttribute('currentUser', [
                'id' => $userId ?? $this->userId,
                'username' => 'testuser',
                'role' => 'editor',
            ]);
    }

    /**
     * Uploads the multi-folder fixture and returns the pending token from
     * the rendered preview page.
     */
    private function uploadAndGetToken(array $formFields = ['name' => 'Provider Bundle']): string
    {
        $response = $this->controller->upload(
            $this->uploadRequest($formFields, 'multi_folder.kml'),
            (new ResponseFactory())->createResponse()
        );
        self::assertSame(200, $response->getStatusCode());

        $html = (string) $response->getBody();
        self::assertSame(1, preg_match('/name="pending_token" value="([0-9a-f]{32})"/', $html, $matches));
        return $matches[1];
    }

    public function testMultiFolderUploadShowsPreviewWithoutCreatingCircuits(): void
    {
        $response = $this->controller->upload(
            $this->uploadRequest(['name' => 'Provider Bundle'], 'multi_folder.kml'),
            (new ResponseFactory())->createResponse()
        );

        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getBody();
        self::assertStringContainsString('Circuit Alpha', $html);
        self::assertStringContainsString('Circuit Beta', $html);
        self::assertStringContainsString('Ungrouped placemarks', $html);
        self::assertStringContainsString('multi_folder.kml', $html);

        self::assertCount(0, $this->circuits->listVisible());
        self::assertDirectoryExists($this->storagePath . '/pending');
    }

    public function testMultiFolderKmzAlsoShowsPreview(): void
    {
        $response = $this->controller->upload(
            $this->uploadRequest(['name' => 'Provider Bundle'], 'multi_folder.kmz'),
            (new ResponseFactory())->createResponse()
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Circuit Alpha', (string) $response->getBody());
        self::assertCount(0, $this->circuits->listVisible());
    }

    public function testSingleFolderUploadSkipsPreview(): void
    {
        $response = $this->controller->upload(
            $this->uploadRequest(['name' => 'Plain Circuit'], 'single_folder.kml'),
            (new ResponseFactory())->createResponse()
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertCount(1, $this->circuits->listVisible());
    }

    public function testConfirmSplitCreatesOneCircuitPerSelectedFolder(): void
    {
        $providerId = $this->providers->insert('Acme Telecom', null, null, null);
        $token = $this->uploadAndGetToken([
            'name' => 'Provider Bundle',
            'description' => 'shared desc',
            'tags' => 'fiber,leased',
            'provider_id' => (string) $providerId,
            'provider_circuit_id' => 'CKT-1',
            'redundant' => '1',
        ]);

        $response = $this->controller->confirmSplit(
            $this->confirmRequest([
                'pending_token' => $token,
                'mode' => 'split',
                'include' => ['0', '1', 'ungrouped'],
                'names' => ['0' => 'Circuit Alpha', '1' => 'Beta Renamed', 'ungrouped' => 'Leftovers'],
            ]),
            (new ResponseFactory())->createResponse()
        );

        self::assertSame(302, $response->getStatusCode());

        $circuits = $this->circuits->listVisible();
        self::assertCount(3, $circuits);
        $byName = array_column($circuits, null, 'name');
        self::assertSame(['Beta Renamed', 'Circuit Alpha', 'Leftovers'], array_keys($byName) === [] ? [] : self::sorted(array_keys($byName)));

        foreach ($circuits as $circuit) {
            self::assertSame('shared desc', $circuit['description']);
            self::assertSame($providerId, (int) $circuit['provider_id']);
            self::assertSame('CKT-1', $circuit['provider_circuit_id']);
            self::assertSame(1, (int) $circuit['redundant']);
        }

        $alphaKml = (string) file_get_contents($this->storagePath . '/circuits/' . $byName['Circuit Alpha']['uuid'] . '/current.kml');
        self::assertStringContainsString('Alpha Segment 1', $alphaKml);
        self::assertStringContainsString('Alpha Lateral 1', $alphaKml);
        self::assertStringContainsString('line-red', $alphaKml);
        self::assertStringNotContainsString('Beta Segment 1', $alphaKml);
        self::assertStringNotContainsString('Loose Handhole', $alphaKml);

        $betaKml = (string) file_get_contents($this->storagePath . '/circuits/' . $byName['Beta Renamed']['uuid'] . '/current.kml');
        self::assertStringContainsString('Beta Segment 1', $betaKml);
        self::assertStringNotContainsString('Alpha Segment 1', $betaKml);

        $auditCount = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM audit_log WHERE event_type = 'upload' AND detail LIKE '%split_folder=%'"
        )->fetchColumn();
        self::assertSame(3, $auditCount);

        self::assertSame([], glob($this->storagePath . '/pending/*') ?: [], 'pending entry is removed after confirm');
    }

    public function testConfirmSplitWithExcludedFolderCreatesOnlySelected(): void
    {
        $token = $this->uploadAndGetToken();

        $response = $this->controller->confirmSplit(
            $this->confirmRequest([
                'pending_token' => $token,
                'mode' => 'split',
                'include' => ['1'],
                'names' => ['1' => 'Only Beta'],
            ]),
            (new ResponseFactory())->createResponse()
        );

        self::assertSame(302, $response->getStatusCode());
        $circuits = $this->circuits->listVisible();
        self::assertCount(1, $circuits);
        self::assertSame('Only Beta', $circuits[0]['name']);
    }

    public function testConfirmSingleModeImportsWholeFileAsOneCircuit(): void
    {
        $token = $this->uploadAndGetToken(['name' => 'Whole Bundle']);

        $response = $this->controller->confirmSplit(
            $this->confirmRequest(['pending_token' => $token, 'mode' => 'single']),
            (new ResponseFactory())->createResponse()
        );

        self::assertSame(302, $response->getStatusCode());
        $circuits = $this->circuits->listVisible();
        self::assertCount(1, $circuits);
        self::assertSame('Whole Bundle', $circuits[0]['name']);

        $kml = (string) file_get_contents($this->storagePath . '/circuits/' . $circuits[0]['uuid'] . '/current.kml');
        self::assertStringContainsString('Alpha Segment 1', $kml);
        self::assertStringContainsString('Beta Segment 1', $kml);
        self::assertStringContainsString('Loose Handhole', $kml);
    }

    public function testConfirmWithNoSelectionIsRejected(): void
    {
        $token = $this->uploadAndGetToken();

        $response = $this->controller->confirmSplit(
            $this->confirmRequest(['pending_token' => $token, 'mode' => 'split', 'include' => [], 'names' => []]),
            (new ResponseFactory())->createResponse()
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Select at least one folder', (string) $response->getBody());
        self::assertCount(0, $this->circuits->listVisible());
    }

    public function testConfirmWithBlankNameCreatesNothing(): void
    {
        $token = $this->uploadAndGetToken();

        $response = $this->controller->confirmSplit(
            $this->confirmRequest([
                'pending_token' => $token,
                'mode' => 'split',
                'include' => ['0', '1'],
                'names' => ['0' => 'Named', '1' => '   '],
            ]),
            (new ResponseFactory())->createResponse()
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertCount(0, $this->circuits->listVisible(), 'all-or-nothing: nothing is created on any error');
    }

    public function testForgedFolderKeysAreIgnored(): void
    {
        $token = $this->uploadAndGetToken();

        $response = $this->controller->confirmSplit(
            $this->confirmRequest([
                'pending_token' => $token,
                'mode' => 'split',
                'include' => ['99', '../etc'],
                'names' => ['99' => 'Evil', '../etc' => 'Evil'],
            ]),
            (new ResponseFactory())->createResponse()
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertCount(0, $this->circuits->listVisible());
    }

    public function testConfirmWithGarbageTokenIsGone(): void
    {
        $response = $this->controller->confirmSplit(
            $this->confirmRequest(['pending_token' => 'not-a-token', 'mode' => 'split']),
            (new ResponseFactory())->createResponse()
        );

        self::assertSame(410, $response->getStatusCode());
        self::assertCount(0, $this->circuits->listVisible());
    }

    public function testConfirmByDifferentUserIsRejected(): void
    {
        $token = $this->uploadAndGetToken();
        $otherUserId = $this->createUser('someoneelse');

        $response = $this->controller->confirmSplit(
            $this->confirmRequest([
                'pending_token' => $token,
                'mode' => 'split',
                'include' => ['0'],
                'names' => ['0' => 'Hijacked'],
            ], $otherUserId),
            (new ResponseFactory())->createResponse()
        );

        self::assertSame(410, $response->getStatusCode());
        self::assertCount(0, $this->circuits->listVisible());
    }

    /**
     * @param array<int, string> $values
     * @return array<int, string>
     */
    private static function sorted(array $values): array
    {
        sort($values);
        return $values;
    }
}
