<?php

use MTLA\SnapshotHealthCheck;
use MTLA\SnapshotPublisher;
use MTLA\CollectTags;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\StellarSDK;

require __DIR__ . '/../vendor/autoload.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            "%s\nExpected: %s\nActual: %s",
            $message,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function expectException(callable $callback, string $expectedClass, string $message): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        if ($exception instanceof $expectedClass) {
            return;
        }
        throw new RuntimeException($message . ': unexpected ' . $exception::class, previous: $exception);
    }

    throw new RuntimeException($message . ': exception was not thrown');
}

function assertJsonFileMatchesSchema(string $jsonPath, string $schemaPath): void
{
    $json = file_get_contents($jsonPath);
    $schemaJson = file_get_contents($schemaPath);
    if ($json === false || $schemaJson === false) {
        throw new RuntimeException('Schema validation input cannot be read');
    }

    $document = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
    $schema = json_decode($schemaJson, false, 512, JSON_THROW_ON_ERROR);
    $validator = new Validator();
    $validator->setMaxErrors(20);
    $validator->setStopAtFirstError(false);
    $result = $validator->validate($document, $schema);
    if ($result->isValid()) {
        return;
    }

    $errors = (new ErrorFormatter())->format($result->error());
    throw new RuntimeException(sprintf(
        "Published bsn.json does not match its schema\n%s",
        json_encode($errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
    ));
}

function removeTestDirectory(string $directory): void
{
    if (!file_exists($directory) && !is_link($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isLink() || $item->isFile()) {
            unlink($item->getPathname());
        } else {
            rmdir($item->getPathname());
        }
    }
    rmdir($directory);
}

$directory = sys_get_temp_dir() . '/bor-publisher-' . bin2hex(random_bytes(6));
mkdir($directory, 0755, true);

try {
    assertTrue(CollectTags::isTextManageDataValue("Valid UTF-8: Živjo\n"), 'UTF-8 text must be accepted');
    assertTrue(!CollectTags::isTextManageDataValue("binary-\xFF"), 'invalid UTF-8 must be rejected');
    assertTrue(!CollectTags::isTextManageDataValue("text\0binary"), 'control bytes must be rejected');
    assertSame('Tag', CollectTags::normalizeDataEntryTagName('Tag12'), 'numeric suffix must be removed');
    assertSame('E2EE_PUB_V', CollectTags::normalizeDataEntryTagName('E2EE_PUB_V1'), 'only trailing digits must be removed');
    assertSame('PartOf', CollectTags::normalizeDataEntryTagName('PartOf:Supporter'), 'colon qualifier must be ignored');
    assertSame('PartOf', CollectTags::normalizeDataEntryTagName('PartOf7:Supporter'), 'numeric suffix before qualifier must be removed');
    assertSame(null, CollectTags::normalizeDataEntryTagName('Bad Tag:Supporter'), 'invalid base tag must be rejected');

    $selfAccount = 'GCNVDZIHGX473FEI7IXCUAEXUJ4BGCKEMHF36VYP5EMS7PX2QBLAMTLA';
    $targetAccount = 'GACKTN5DAZGWXRWB2WLM6OPBDHAMT6SJNGLJZPQMEZBUR4JUGBX2UK7V';
    $accountResponse = AccountResponse::fromJson([
        'account_id' => $selfAccount,
        'data' => [
            'Self1' => base64_encode($selfAccount),
            'PartOf:Supporter' => base64_encode($targetAccount),
        ],
    ]);
    $getTags = new ReflectionMethod(CollectTags::class, 'getTags');
    $parsedTags = $getTags->invoke(new CollectTags(new StellarSDK('https://example.invalid')), $accountResponse);
    assertSame([$targetAccount], $parsedTags['PartOf'] ?? null, 'colon-qualified link must use its base tag');
    assertSame([$selfAccount], $parsedTags['Self'] ?? null, 'self-link must be preserved');

    $filterCollector = new CollectTags(new StellarSDK('https://example.invalid'));
    $accounts = [
        'empty' => ['balances' => ['XLM' => '5.0']],
        'other-token-balance' => ['balances' => ['XLM' => '5.0', 'EURMTL' => '10.0']],
        'profile' => ['balances' => ['XLM' => '5.0'], 'profile' => ['Name' => ['Profile']]],
        'signatures' => ['balances' => ['XLM' => '5.0'], 'signatures' => [str_repeat('a', 64) => 'Document']],
        'multisig' => ['balances' => ['XLM' => '5.0'], 'multisig' => ['signers' => [[$targetAccount, 1]]]],
        'membership' => ['balances' => ['XLM' => '5.0', 'MTLAP' => '1.0']],
        'tag-source' => ['balances' => ['XLM' => '5.0'], 'tags' => ['Friend' => ['tag-target']]],
        'tag-target' => ['balances' => ['XLM' => '5.0']],
    ];
    $accountsProperty = new ReflectionProperty(CollectTags::class, 'accounts');
    $accountsProperty->setValue($filterCollector, $accounts);
    $processData = new ReflectionMethod(CollectTags::class, 'processData');
    $filteredAccounts = $processData->invoke($filterCollector);
    assertSame(
        ['profile', 'signatures', 'multisig', 'membership', 'tag-source', 'tag-target'],
        array_keys($filteredAccounts),
        'filter must keep own BSN data, membership balances and both sides of links only'
    );

    foreach (['bsn.json', 'bsn.json.gz', 'bsn-extra.json,gz', 'bsn.html.gz'] as $legacyFile) {
        file_put_contents($directory . '/' . $legacyFile, 'legacy');
    }

    $publisher = new SnapshotPublisher($directory);
    $publisher->acquireLock();

    $fixtureJson = file_get_contents(__DIR__ . '/fixtures/bsn.json');
    assertTrue($fixtureJson !== false, 'schema fixture must be readable');
    $snapshot = json_decode($fixtureJson, true, 512, JSON_THROW_ON_ERROR);
    $snapshot['createDate'] = gmdate(DATE_ATOM);
    $snapshotAccountId = array_key_first($snapshot['accounts']);

    $firstGeneration = $publisher->publish($snapshot, $snapshot, '<html>first</html>');
    assertTrue(is_link($directory . '/current'), 'current must be a symbolic link');
    assertSame('.snapshots/' . $firstGeneration, readlink($directory . '/current'), 'current target mismatch');
    foreach (['bsn.json', 'bsn.json.gz', 'bsn-extra.json,gz', 'bsn.html.gz'] as $publicFile) {
        assertSame('current/' . $publicFile, readlink($directory . '/' . $publicFile), 'public alias mismatch');
    }

    $status = (new SnapshotHealthCheck())->check($directory, 900);
    assertSame($firstGeneration, $status['generation'], 'healthcheck generation mismatch');
    assertSame(1, $status['accounts'], 'healthcheck account count mismatch');

    $currentBeforeInvalidPublish = readlink($directory . '/current');
    $invalidSnapshot = $snapshot;
    $invalidSnapshot['accounts'][$snapshotAccountId]['profile']['About'] = ["binary-\xFF"];
    expectException(
        fn () => $publisher->publish($invalidSnapshot, $invalidSnapshot, '<html>invalid</html>'),
        JsonException::class,
        'invalid UTF-8 must reject publication'
    );
    assertSame(
        $currentBeforeInvalidPublish,
        readlink($directory . '/current'),
        'failed publication must preserve the current generation'
    );

    $snapshot['accounts'][$snapshotAccountId]['profile']['Name'] = ['Second'];
    $secondGeneration = $publisher->publish($snapshot, $snapshot, '<html>second</html>');
    assertTrue($secondGeneration !== $firstGeneration, 'a new publication must create a new generation');
    assertSame('.snapshots/' . $secondGeneration, readlink($directory . '/current'), 'generation switch mismatch');

    $publishedJson = file_get_contents($directory . '/bsn.json');
    $publishedGzip = file_get_contents($directory . '/bsn.json.gz');
    assertTrue($publishedJson !== false && $publishedGzip !== false, 'published files must be readable');
    assertSame($publishedJson, gzdecode($publishedGzip), 'published gzip must match JSON');
    assertJsonFileMatchesSchema($directory . '/bsn.json', __DIR__ . '/../schema/bsn.schema.json');

    expectException(
        fn () => (new SnapshotHealthCheck())->check($directory, 900, time() + 901),
        RuntimeException::class,
        'stale publication must fail healthcheck'
    );

    $secondPublisher = new SnapshotPublisher($directory);
    expectException(
        fn () => $secondPublisher->acquireLock(),
        RuntimeException::class,
        'concurrent crawler must not acquire the lock'
    );

    $publisher->releaseLock();
    print "All tests passed\n";
} finally {
    if (isset($publisher)) {
        $publisher->releaseLock();
    }
    removeTestDirectory($directory);
}
