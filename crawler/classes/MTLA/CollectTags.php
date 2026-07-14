<?php

namespace MTLA;

use Closure;
use RuntimeException;
use Soneso\StellarSDK\Asset;
use Soneso\StellarSDK\Exceptions\HorizonRequestException;
use Soneso\StellarSDK\Responses\Account\AccountBalanceResponse;
use Soneso\StellarSDK\Responses\Account\AccountResponse;
use Soneso\StellarSDK\Responses\Account\AccountSignerResponse;
use Soneso\StellarSDK\StellarSDK;

class CollectTags
{
    const SHA256_HASH_REGEX = '/^[a-f0-9]{64}$/';

    private StellarSDK $Stellar;
    private Closure $logger;
    private bool $debug_mode = false;

    private array $sources = [];

    private array $accounts = [];
    private array $balance_tokens = [];

    public array $sort_tags_example = [
        'A', 'B', 'C', 'D',
        'Spouse', 'Love', 'OneFamily', 'Guardian', 'Ward', 'Sympathy', 'Divorce',
        'Employer', 'Employee', 'Contractor', 'Client', 'Partnership', 'Collaboration',
        'Owner', 'OwnershipFull', 'OwnerMajority', 'OwnershipMajority', 'OwnerMinority',
        'FactionMember', 'WelcomeGuest',
    ];
    public array $sort_profile_example = [
        'Name',
        'About',
        'Website',
    ];


    public function __construct(StellarSDK $Stellar)
    {
        $this->Stellar = $Stellar;

        // TODO: fix it
        if (!isset($this->logger)) {
            $this->setDefaultLogger();
        }
    }

    public function addSource(string $code, string $issuer): void
    {
        if (!self::validateStellarAccountIdFormat($issuer)) {
            throw new RuntimeException('The issuer not a valid stellar account: ' . $issuer);
        }

        $this->sources[$code . '-' . $issuer] = true;
    }

    /**
     * @return string[]
     */
    public function getSources(): array
    {
        return array_keys($this->sources);
    }

    public function addBalanceToken(string $token): void
    {
        [, $issuer] = explode('-', $token);
        if (!self::validateStellarAccountIdFormat($issuer)) {
            throw new RuntimeException('The issuer not a valid stellar account: ' . $issuer);
        }

        $this->balance_tokens[$token] = true;
    }

    public function run(): array
    {
        if (!isset($this->logger)) {
            $this->setDefaultLogger();
        }

        if (!$this->sources) {
            throw new RuntimeException('Missing sources for accounts');
        }

        foreach (array_keys($this->sources) as $source) {
            [$code, $issuer] = explode('-', $source);
            $this->fetchDataFromAssetHolders($code, $issuer);
        }

        return $this->processData();
    }

    public function fetchDataFromAssetHolders(string $code, string $issuer): void
    {
        $accounts = $this->fetchAssetHolders($code, $issuer);

        foreach ($accounts as $AccountResponse) {
            if ($AccountResponse instanceof AccountResponse) {
                $this->processStellarAccount($AccountResponse);
            }
        }
    }

    /**
     * @param string $code
     * @param string $issuer
     * @return AccountResponse[]
     * @throws HorizonRequestException
     */
    public function fetchAssetHolders(string $code, string $issuer): array
    {
        $Asset = Asset::createNonNativeAsset($code, $issuer);

        $Accounts = $this->Stellar
            ->accounts()
            ->forAsset($Asset)
            ->limit(200)
            ->execute();
        $this->log('Fetch accounts page for ' . $code);
        $accounts = [];
        do {
            $this->log('Got new ' . $Accounts->getAccounts()->count() . ' accounts');
            foreach ($Accounts->getAccounts() as $Account) {
                $accounts[] = $Account;
            }
            $Accounts = $Accounts->getNextPage();
            $this->log('Fetch next accounts ' . $code);
        } while ($Accounts->getAccounts()->count());

        $this->log('Finally: ' . count($accounts) . ' accounts');
        return $accounts;
    }

    //region Logging
    public function setLogger(Closure $logger): void
    {
        $this->logger = $logger;
    }

    public function setDefaultLogger(): void
    {
        $this->setLogger(function (bool $debug, string $string) {
            if (!$debug || $this->debug_mode) {
                print $string . "\n";
            }
        });
    }

    public function isDebugMode(?bool $debug_mode = null): bool
    {
        if ($debug_mode !== null) {
            $this->debug_mode = $debug_mode;
        }

        return $this->debug_mode;
    }

    public function log(string $string = ''): void
    {
        ($this->logger)(true, $string);
    }

    public function print(string $string = ''): void
    {
        ($this->logger)(false, $string);
    }

    //endregion

    public static function validateStellarAccountIdFormat(?string $account_id): bool
    {
        if (!$account_id) {
            return false;
        }

        return (bool) preg_match('/^G[A-Z2-7]{55}$/', $account_id);
    }

    public static function validateSignature(?string $string): bool
    {
        if (!$string) {
            return false;
        }

        return preg_match(self::SHA256_HASH_REGEX, $string);
    }

    public static function isTextManageDataValue(string $value): bool
    {
        return preg_match('//u', $value) === 1
            && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) !== 1
            && preg_match('/[\x{0080}-\x{009F}]/u', $value) !== 1;
    }

    public static function normalizeDataEntryTagName(string $entryName): ?string
    {
        $tag = trim(explode(':', $entryName, 2)[0]);
        $tag = preg_replace('/\s*\d+\s*$/', '', $tag);
        if ($tag === null || $tag === '' || preg_match('/^[a-z0-9_]+$/i', $tag) !== 1) {
            return null;
        }

        return $tag;
    }

    private function processStellarAccount(AccountResponse $AccountResponse): void
    {
        $account_id = $AccountResponse->getAccountId();

        if (array_key_exists($account_id, $this->accounts)) {
            return;
        }

        $profile = $this->getProfile($AccountResponse);

        $balances = $this->getBalances($AccountResponse);

        $tags = $this->getTags($AccountResponse);

        $signatures = $this->getSignatures($AccountResponse);

        $multisig = $this->getMultisig($AccountResponse);

        $result = [];
        if ($profile) {
            $result['profile'] = $profile;
        }
        $result['balances'] = $balances;
        if ($tags) {
            $result['tags'] = $tags;
        }
        if ($signatures) {
            $result['signatures'] = $signatures;
        }
        if ($multisig) {
            $result['multisig'] = $multisig;
        }

        $this->accounts[$account_id] = $result;
    }

    private function getBalances(AccountResponse $AccountResponse): array
    {
        $balances = [];
        foreach ($AccountResponse->getBalances()->toArray() as $Asset) {
            if (($Asset instanceof AccountBalanceResponse)
            ) {
                if ($Asset->getAssetType() === Asset::TYPE_NATIVE) {
                    $balances['XLM'] = $Asset->getBalance();
                    continue;
                }

                if (array_key_exists($Asset->getAssetCode() . '-' . $Asset->getAssetIssuer(), $this->balance_tokens)) {
                    $balances[$Asset->getAssetCode()] = $Asset->getBalance();
                }
            }
        }

        return $balances;
    }

    private function getProfile(AccountResponse $AccountResponse): array
    {
        $profile = [];
        $Data = $AccountResponse->getData();
        foreach ($Data->getKeys() as $key) {
            $value = $Data->get($key);
            if (!self::isTextManageDataValue($value)) {
                $this->print(sprintf(
                    'Skipping non-text ManageData value: account=%s key=%s',
                    $AccountResponse->getAccountId(),
                    $key
                ));
                continue;
            }

            $value = trim($value);
            if ($value === '') {
                continue;
            }

            $key = self::normalizeDataEntryTagName($key);
            if ($key === null) {
                continue;
            }

            // Ignore Links
            if (self::validateStellarAccountIdFormat($value)) {
                continue;
            }

            if (!array_key_exists($key, $profile)) {
                $profile[$key] = [];
            }
            $profile[$key][] = $value;
        }

        $this->semantic_sort_keys($profile, $this->sort_profile_example);

        return $profile;
    }

    private function getTags(AccountResponse $AccountResponse): array
    {
        $tags = [];
        $Data = $AccountResponse->getData();
        foreach ($Data->getKeys() as $key) {
            $value = $Data->get($key);
            if (!self::validateStellarAccountIdFormat($value)) {
                continue;
            }

            $key = self::normalizeDataEntryTagName($key);
            if ($key === null) {
                continue;
            }

            if (!array_key_exists($key, $tags)) {
                $tags[$key] = [];
            }

            $tags[$key][] = $value;
        }

        $this->semantic_sort_keys($tags, $this->sort_tags_example);

        return $tags;
    }

    private function getSignatures(AccountResponse $AccountResponse): array
    {
        $signatures = [];
        $Data = $AccountResponse->getData();
        foreach ($Data->getKeys() as $key) {
            $value = strtolower($Data->get($key));
            if (!self::validateSignature($value)) {
                continue;
            }

            if (array_key_exists($value, $signatures)) {
                continue; // Double
            }

            $signatures[$value] = $key;
        }

        return $signatures;
    }

    private function getMultisig(AccountResponse $AccountResponse): ?array
    {
        $master_key = 0;
        $signers = [];
        $Signers = $AccountResponse->getSigners();
        /** @var AccountSignerResponse $Signer */
        foreach ($Signers->toArray() as $Signer) {
            $key = $Signer->getKey();
            $weight = $this->getSignerWeight($Signer);

            if ($key === $AccountResponse->getAccountId()) {
                $master_key = $weight;
                continue;
            }
            if (!self::validateStellarAccountIdFormat($key)) {
                continue;
            }
            $signers[] = [$key, $weight];
        }

        if (!$signers) {
            return null;
        }

        return [
            'thresholds' => $this->getThresholds($AccountResponse),
            'master_key' => $master_key,
            'signers' => $signers,
        ];
    }

    private function getSignerWeight(AccountSignerResponse $Signer): int
    {
        if (method_exists($Signer, 'getWeight')) {
            return (int) $Signer->getWeight();
        }

        if (property_exists($Signer, 'weight')) {
            return (int) $Signer->weight;
        }

        return 0;
    }

    private function getThresholds(AccountResponse $AccountResponse): array
    {
        $Thresholds = method_exists($AccountResponse, 'getThresholds')
            ? $AccountResponse->getThresholds()
            : null;

        return [
            $this->extractThresholdValue($AccountResponse, $Thresholds, 'Low'),
            $this->extractThresholdValue($AccountResponse, $Thresholds, 'Med'),
            $this->extractThresholdValue($AccountResponse, $Thresholds, 'High'),
        ];
    }

    private function extractThresholdValue(AccountResponse $AccountResponse, mixed $Thresholds, string $level): int
    {
        $account_method = 'get' . $level . 'Threshold';
        if (method_exists($AccountResponse, $account_method)) {
            return (int) $AccountResponse->$account_method();
        }

        if (is_object($Thresholds)) {
            $threshold_method = 'get' . $level . 'Threshold';
            if (method_exists($Thresholds, $threshold_method)) {
                return (int) $Thresholds->$threshold_method();
            }

            $property = strtolower($level) . 'Threshold';
            if (property_exists($Thresholds, $property)) {
                return (int) $Thresholds->$property;
            }

            $property = strtolower($level) . '_threshold';
            if (property_exists($Thresholds, $property)) {
                return (int) $Thresholds->$property;
            }
        }

        if (is_array($Thresholds)) {
            $camel_key = strtolower($level) . 'Threshold';
            if (array_key_exists($camel_key, $Thresholds)) {
                return (int) $Thresholds[$camel_key];
            }

            $snake_key = strtolower($level) . '_threshold';
            if (array_key_exists($snake_key, $Thresholds)) {
                return (int) $Thresholds[$snake_key];
            }
        }

        return 0;
    }

    private function processData(): array
    {
        foreach ($this->accounts as & $data) {
            if (array_key_exists('tags', $data) && $data['tags']) {
                $data['has_tag_out'] = true;
            }

            if (!array_key_exists('tags', $data)) {
                continue;
            }

            foreach ($data['tags'] as $items) {
                foreach ($items as $item) {
                    if (array_key_exists($item, $this->accounts)) {
                        $this->accounts[$item]['has_tag_in'] = true;
                    }
                }
            }
        }
        unset($data);

        $result = [];
        foreach ($this->accounts as $id => $data) {
            if (!array_key_exists('has_tag_out', $data)
                && !array_key_exists('has_tag_in', $data)
                && !(array_key_exists('MTLAP', $data['balances']) && $data['balances']['MTLAP'] > 0)
                && !(array_key_exists('MTLAC', $data['balances']) && $data['balances']['MTLAC'] > 0)
            ) {
                continue;
            }

            $result_data = [];
            if (array_key_exists('profile', $data) && $data['profile']) {
                $result_data['profile'] = $data['profile'];
            }
            $result_data['balances'] = $data['balances'];
            if (array_key_exists('tags', $data) && $data['tags']) {
                $result_data['tags'] = $data['tags'];
            }
            if (array_key_exists('signatures', $data) && $data['signatures']) {
                $result_data['signatures'] = $data['signatures'];
            }
            if (array_key_exists('multisig', $data) && $data['multisig']) {
                $result_data['multisig'] = $data['multisig'];
            }

            $result[$id] = $result_data;
        }

        return $result;
    }

    public function semantic_sort_keys(array & $data, array $sort_example): void
    {
        uksort($data, function($a, $b) use ($sort_example) {
            $indexA = array_search($a, $sort_example);
            $indexB = array_search($b, $sort_example);

            // Если оба ключа есть в массиве сортировки
            if ($indexA !== false && $indexB !== false) {
                return $indexA - $indexB;
            }
            // Если ключ A в массиве сортировки, а B нет
            elseif ($indexA !== false) {
                return -1;
            }
            // Если ключ B в массиве сортировки, а A нет
            elseif ($indexB !== false) {
                return 1;
            }
            // Если ни один из ключей не в массиве сортировки, сортируем их по алфавиту
            else {
                return $a <=> $b;
            }
        });
    }
}
