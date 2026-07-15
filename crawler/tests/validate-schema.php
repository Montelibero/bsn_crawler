<?php

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

require __DIR__ . '/../vendor/autoload.php';

function readJsonObject(string $path): object
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Cannot read JSON file: ' . $path);
    }

    $value = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
    if (!is_object($value)) {
        throw new RuntimeException('JSON root must be an object: ' . $path);
    }

    return $value;
}

function assertSchemaValid(Validator $validator, object $schema, object $data, string $message): void
{
    $result = $validator->validate($data, $schema);
    if ($result->isValid()) {
        return;
    }

    $errors = (new ErrorFormatter())->format($result->error());
    throw new RuntimeException(sprintf(
        "%s\n%s",
        $message,
        json_encode($errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
    ));
}

function assertSchemaInvalid(Validator $validator, object $schema, object $data, string $message): void
{
    if ($validator->validate($data, $schema)->isValid()) {
        throw new RuntimeException($message);
    }
}

$schemaPath = __DIR__ . '/../schema/bsn.schema.json';
$fixturePath = __DIR__ . '/fixtures/bsn.json';
$documentPath = $argv[1] ?? $fixturePath;
$schema = readJsonObject($schemaPath);
$fixture = readJsonObject($fixturePath);
$document = readJsonObject($documentPath);

$validator = new Validator();
$validator->setMaxErrors(20);
$validator->setStopAtFirstError(false);

assertSchemaValid($validator, $schema, $document, 'BSN JSON does not match its schema: ' . $documentPath);

$invalidCreateDate = readJsonObject($fixturePath);
$invalidCreateDate->createDate = 'not-a-date';
assertSchemaInvalid($validator, $schema, $invalidCreateDate, 'Schema must require an RFC 3339 createDate');

$missingAccounts = readJsonObject($fixturePath);
unset($missingAccounts->accounts);
assertSchemaInvalid($validator, $schema, $missingAccounts, 'Schema must require accounts');

$emptyKnownTokens = readJsonObject($fixturePath);
$emptyKnownTokens->knownTokens = (object) [];
assertSchemaInvalid($validator, $schema, $emptyKnownTokens, 'Schema must reject an empty known token registry');

$emptyUsedSources = readJsonObject($fixturePath);
$emptyUsedSources->usedSources = [];
assertSchemaInvalid($validator, $schema, $emptyUsedSources, 'Schema must reject an empty source list');

$emptyAccounts = readJsonObject($fixturePath);
$emptyAccounts->accounts = (object) [];
assertSchemaInvalid($validator, $schema, $emptyAccounts, 'Schema must reject an empty account registry');

$invalidAccountId = readJsonObject($fixturePath);
$account = array_values(get_object_vars($invalidAccountId->accounts))[0];
$invalidAccountId->accounts = (object) ['GTEST' => $account];
assertSchemaInvalid($validator, $schema, $invalidAccountId, 'Schema must reject invalid account IDs');

$numericBalance = readJsonObject($fixturePath);
$accountId = array_key_first(get_object_vars($numericBalance->accounts));
$numericBalance->accounts->{$accountId}->balances->XLM = 10;
assertSchemaInvalid($validator, $schema, $numericBalance, 'Schema must require decimal balances as strings');

$invalidSignatureLabel = readJsonObject($fixturePath);
$accountId = array_key_first(get_object_vars($invalidSignatureLabel->accounts));
$signatureHash = array_key_first(get_object_vars($invalidSignatureLabel->accounts->{$accountId}->signatures));
$invalidSignatureLabel->accounts->{$accountId}->signatures->{$signatureHash} = true;
assertSchemaInvalid($validator, $schema, $invalidSignatureLabel, 'Schema must reject boolean signature labels');

print "BSN JSON Schema tests passed\n";
