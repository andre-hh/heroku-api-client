<?php
declare(strict_types=1);

namespace HerokuApiClient;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use HerokuApiClient\Exceptions\HerokuApiException;
use HerokuApiClient\Exceptions\HerokuCannotUpdateAboveLimitException;
use HerokuApiClient\Exceptions\HerokuDynoNameNotFoundException;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

class HerokuApi
{
    const DYNO_TYPE_FREE = 'free';
    const DYNO_TYPE_HOBBY = 'hobby';
    const DYNO_TYPE_STANDARD_1X = 'standard-1x';
    const DYNO_TYPE_STANDARD_2X = 'standard-2x';
    const DYNO_TYPE_PERFORMANCE_M = 'performance-m';
    const DYNO_TYPE_PERFORMANCE_L = 'performance-l';

    /**
     * Returns all potential dyno types.
     * @see https://devcenter.heroku.com/articles/dyno-types
     */
    public static function getAvailableDynoTypes(): array
    {
        return [
            self::DYNO_TYPE_FREE,
            self::DYNO_TYPE_HOBBY,
            self::DYNO_TYPE_STANDARD_1X,
            self::DYNO_TYPE_STANDARD_2X,
            self::DYNO_TYPE_PERFORMANCE_M,
            self::DYNO_TYPE_PERFORMANCE_L,
        ];
    }

    /** @var LoggerInterface */
    private $logger;

    /** @var string */
    private $logLevel;

    /** @var string */
    private $app;

    /** @var Client */
    private $client;

    public function __construct(LoggerInterface $logger, string $app, string $apiKey, string $logLevel = LogLevel::INFO)
    {
        if (!in_array($logLevel, [LogLevel::DEBUG, LogLevel::INFO,])) {
            throw new InvalidArgumentException('Log level must be "debug" or "info"');
        }

        $this->logger = $logger;
        $this->logLevel = $logLevel;

        $this->app = $app;

        $this->client = new Client([
            'base_uri' => 'https://api.heroku.com/',
            'auth' => ['', $apiKey, 'basic'],
            'headers' => ['Accept' => 'application/vnd.heroku+json; version=3'],
            'timeout' => 3,
        ]);
    }

    /**
     * Sets PHP's memory limit based on the the given dyno type's capabilities.
     * @see https://devcenter.heroku.com/articles/limits#dynos
     *
     * $allowSwap is the percentage of available swap that should be allowed to use.
     */
    public static function setMemoryLimitBasedOnDynoType(string $dynoType, float $allowSwap = 0): void
    {
        self::validateDynoType($dynoType);

        if ($allowSwap < 0 || $allowSwap > 1) {
            throw new InvalidArgumentException('$allowSwap must be a float value between 0 and 1');
        }

        if (in_array($dynoType, [self::DYNO_TYPE_FREE, self::DYNO_TYPE_HOBBY, self::DYNO_TYPE_STANDARD_1X])) {
            ini_set('memory_limit', ((512 * $allowSwap + 512) . 'M'));
        } elseif ($dynoType === self::DYNO_TYPE_STANDARD_2X) {
            ini_set('memory_limit', ((1024 * $allowSwap + 1024) . 'M'));
        } elseif ($dynoType === self::DYNO_TYPE_PERFORMANCE_M) {
            ini_set('memory_limit', ((2500 * $allowSwap + 2500) . 'M'));
        } elseif ($dynoType === self::DYNO_TYPE_PERFORMANCE_L) {
            ini_set('memory_limit', ((14000 * $allowSwap + 14000) . 'M'));
        }
    }

    /**
     * Runs the given command on the given dyno type and returns the name of the dyno running that command.
     *
     * @throws HerokuApiException
     */
    public function runOneOffDyno(string $command, string $dynoType = self::DYNO_TYPE_STANDARD_1X): string
    {
        self::validateDynoType($dynoType);

        try {
            $response = $this->client->post(
                'apps/' . $this->app. '/dynos',
                [
                    'json' => ['attach' => false, 'command' => $command, 'size' => $dynoType,],
                    'timeout' => 10,
                ]
            );
        } catch (RequestException $e) {
            $this->log(
                'Heroku API request to run one-off dyno failed.',
                ['exceptionMessage' => $e->getMessage(),]
            );
            throw new HerokuApiException(
                'Heroku API request to run one-off dyno failed (' . $e->getMessage() . ').'
            );
        }

        $body = $response->getBody()->getContents();
        $contents = json_decode($body, true);
        if (!is_array($contents) || !array_key_exists('name', $contents)) {
            $this->log(
                'Heroku API request to run one-off dyno failed.',
                ['responseBody' => $body,]
            );
            throw new HerokuApiException(
                'Heroku API request to run one-off dyno failed (response: ' . $body . ').'
            );
        }

        $this->log(
            'One-off dyno has been triggered.',
            ['dynoName' => $contents['name'], 'dynoType' => $dynoType, 'command' => $command,]
        );

        return $contents['name'];
    }

    /**
     * Returns a list of all currently running dynos.
     * This endpoint often needs more than 3 seconds to answer.
     *
     * Example data structure:
     * array:11 [
     *   "attach_url" => null
     *   "command" => "vendor/bin/heroku-php-apache2 public/"
     *   "created_at" => "2019-05-19T06:03:45Z"
     *   "id" => "bf82006e-0d46-429d-afc5-e2f7bdb3a6be"
     *   "name" => "web.1"
     *   "app" => array:2 [
     *     "id" => "1394f69c-851e-43c9-b5f7-c8617acf7574"
     *     "name" => "foobar"
     *   ]
     *   "release" => array:2 [
     *     "id" => "38c89e55-8779-48d5-abea-b39a35ab080c"
     *     "version" => 458
     *   ]
     *   "size" => "Standard-1X"
     *   "state" => "up"
     *   "type" => "web"
     *   "updated_at" => "2019-05-19T06:03:45Z"
     * ]
     *
     * @throws HerokuApiException
     */
    public function getDynoList(int $attempts = 1, int $sleepAfterFailedAttempt = 0): array
    {
        try {
            $response = $this->client->get('apps/' . $this->app. '/dynos', ['timeout' => 10,]);
        } catch (RequestException $e) {
            $this->log(
                'Heroku API request to get dyno list failed.',
                ['exceptionMessage' => $e->getMessage(),]
            );
            if ($attempts > 1) {
                sleep($sleepAfterFailedAttempt);
                $this->log('Will retry to get dyno list now.');
                return $this->getDynoList(--$attempts);
            } else {
                throw new HerokuApiException(
                    'Heroku API request to get dyno list failed (' . $e->getMessage() . ').'
                );
            }
        }

        $body = $response->getBody()->getContents();
        $contents = json_decode($body, true);
        if (!is_array($contents)) {
            $this->log('Heroku API request to get dyno list failed.', ['responseBody' => $body,]);
            if ($attempts > 1) {
                $this->log('Will retry to get dyno list now.');
                return $this->getDynoList(--$attempts);
            } else {
                throw new HerokuApiException(
                    'Heroku API request to get dyno list failed (response: ' . $body . ').'
                );
            }
        }

        return $contents;
    }

    /**
     * Returns the number of currently running dynos of the given process type according to the dyno formation.
     *
     * @throws HerokuApiException
     */
    public function getFormationQuantity(string $process, int $attempts = 1): int
    {
        try {
            $response = $this->client->get('apps/' . $this->app. '/formation/' . $process, ['timeout' => 10,]);
        } catch (RequestException $e) {
            $this->log(
                'Heroku API request to get formation quantity failed.',
                ['exceptionMessage' => $e->getMessage(),]
            );
            if ($attempts > 1) {
                $this->log('Will retry to get formation quantity now.');
                return $this->getFormationQuantity($process, --$attempts);
            } else {
                throw new HerokuApiException(
                    'Heroku API request to get formation quantity failed (' . $e->getMessage() . ').'
                );
            }
        }

        $body = $response->getBody()->getContents();
        $contents = json_decode($body, true);
        if (!is_array($contents) || !array_key_exists('quantity', $contents)) {
            $this->log('Heroku API request to get formation quantity failed.', ['responseBody' => $body,]);
            if ($attempts > 1) {
                $this->log('Will retry to get formation quantity now.');
                return $this->getFormationQuantity($process, --$attempts);
            } else {
                throw new HerokuApiException(
                    'Heroku API request to get dyno list failed (response: ' . $body . ').'
                );
            }
        }

        $quantity = (int) $contents['quantity'];

        $this->log('Got formation quantity of process type "' . $process . '" is ' . $quantity . '.');

        return $quantity;
    }

    /**
     * Updates the given process type of the dyno formation.
     *
     * TODO: There is also a method for bulk updates.
     *
     * @throws HerokuApiException
     * @throws HerokuCannotUpdateAboveLimitException
     */
    public function updateFormation(string $process, int $quantity, string $dynoType): void
    {
        self::validateDynoType($dynoType);

        try {
            $response = $this->client->patch(
                'apps/' . $this->app. '/formation/' . $process,
                ['json' => ['quantity' => $quantity, 'size' => $dynoType],]
            );
        } catch (RequestException $e) {

            $contents = null;

            // This satisfies Psalm
            if ($e->getResponse() instanceof ResponseInterface) {
                $response = $e->getResponse();
                /** @var ResponseInterface $response */
                $contents = json_decode($response->getBody()->getContents(), true);
            }

            if ($e->getCode() === 422
                && is_array($contents)
                && array_key_exists('id', $contents)
                && $contents['id'] === 'cannot_update_above_limit')
            {
                $this->log(
                    'Heroku API request to update formation above limit failed.',
                    ['exceptionMessage' => $e->getMessage(), 'responseBody' => $contents,]
                );
                throw new HerokuCannotUpdateAboveLimitException(
                    'Heroku API request to update formation failed (cannot update above limit).'
                );
            }

            $this->log(
                'Heroku API request to update formation failed.',
                ['exceptionMessage' => $e->getMessage(), 'responseBody' => $contents,]
            );
            throw new HerokuApiException(
                'Heroku API request to update formation failed (' . $e->getMessage() . ').'
            );
        }

        $body = $response->getBody()->getContents();
        $contents = json_decode(trim($body), true);
        if (!is_array($contents) || count(array_diff(['size', 'quantity', 'type'], array_keys($contents))) > 0) {
            $this->log('Heroku API request to update formation failed.', ['responseBody' => $contents,]);
            throw new HerokuApiException(
                'Heroku API request to update formation failed (unexpected response: ' . $body . ').'
            );
        }

        $this->logger->debug(
            'Updated formation.',
            [
                'processType' => $process,
                'newDynoType' => $dynoType,
                'newQuantity' => $quantity,
            ]
        );
    }

    /**
     * Kills the specified dyno by its name.
     *
     * @throws HerokuApiException
     * @throws HerokuDynoNameNotFoundException
     */
    public function killDyno(string $dynoName, int $attempts = 1): void
    {
        try {
            $this->client->delete('apps/' . $this->app. '/formation/' . $dynoName, ['timeout' => 10,]);
        } catch (RequestException $e) {

            // The specified $dynoName does simply not exist
            if ($e->getCode() === 404) {
                $this->log('Heroku API request to kill dyno failed as "' . $dynoName . '" was not found.');
                throw new HerokuDynoNameNotFoundException(
                    'Heroku API request to kill dyno failed as "' . $dynoName . '" was not found.'
                );
            }

            $this->log('Heroku API request to kill dyno failed.', ['exceptionMessage' => $e->getMessage()]);
            if ($attempts > 1) {
                $this->log('Will retry killing dyno now.');
                $this->killDyno($dynoName, --$attempts);
            } else {
                throw new HerokuApiException(
                    'Heroku API request to kill dyno failed (' . $e->getMessage() . ').'
                );
            }
        }
    }

    /**
     * Either returns all invoices descending sorted by date or the invoice of the requested month or null when no
     * invoice exists for the requested month.
     *
     * @throws HerokuApiException
     */
    public function getAccountInvoices(string $month = null): ?array
    {
        if ($month !== null && (strlen($month) != 7 || strpos($month, '-') != 4)) {
            throw new InvalidArgumentException('If month is provided, its format must be YYYY-MM.');
        }

        try {
            $response = $this->client->get('account/invoices', ['timeout' => 10,]);
        } catch (RequestException $e) {
            $this->log('Heroku API request to get invoices failed.', ['exceptionMessage' => $e->getMessage()]);
            throw new HerokuApiException();
        }

        $body = $response->getBody()->getContents();
        $contents = json_decode(trim($body), true);
        if (!is_array($contents)) {
            $this->log('Heroku API request to get invoices failed.', ['responseBody' => $contents,]);
            throw new HerokuApiException(
                'Heroku API request to get invoices failed (unexpected response: ' . $body . ').'
            );
        }

        foreach ($contents as $invoice) {
            if (!array_key_exists('period_start', $invoice)) {
                $this->log('Heroku API request to get invoices failed (unexpected schema).', $invoice);
                throw new HerokuApiException();
            }
        }

        if ($month !== null) {
            foreach ($contents as $invoice) {
                if (0 === strpos($invoice['period_start'], ($month . '-01'))) {
                    return $invoice;
                }
            }

            return null;
        }

        uasort($contents, function (array $a, array $b) {
            return strtotime($a['period_start']) - strtotime($b['period_start']);
        });

        return $contents;
    }

    /**
     * Returns the number of remaining API tokens (so that an application can avoid hitting Heroku's API rate-limit).
     * @see https://devcenter.heroku.com/articles/platform-api-reference#rate-limit
     *
     * @throws HerokuApiException
     */
    public function getRemainingTokens(int $attempts = 1): int
    {
        try {
            $response = $this->client->get('account/rate-limits', ['timeout' => 10,]);
        } catch (RequestException $e) {
            $this->log(
                'Heroku API request to get remaining API tokens failed.',
                ['exceptionMessage' => $e->getMessage(),]
            );
            if ($attempts > 1) {
                $this->log('Will retry getting remaining API tokens now.');
                return $this->getRemainingTokens(--$attempts);
            } else {
                throw new HerokuApiException();
            }
        }

        $body = $response->getBody()->getContents();
        $contents = json_decode($body, true);
        if (!is_array($contents) || !array_key_exists('remaining', $contents)) {
            $this->log(
                'Heroku API request to get remaining API tokens failed.',
                ['responseBody' => $body,]
            );
            if ($attempts > 1) {
                $this->log('Will retry getting remaining API tokens now.');
                return $this->getRemainingTokens(--$attempts);
            } else {
                throw new HerokuApiException();
            }
        }

        return (int) $contents['remaining'];
    }

    public static function validateDynoType(string $dynoType): void
    {
        if (!in_array($dynoType, self::getAvailableDynoTypes())) {
            throw new InvalidArgumentException('Dyno type "' . $dynoType . '" not supported.');
        }
    }

    private function log(string $message, array $context = []): void
    {
        $this->logger->log($this->logLevel, $message, $context);
    }
}
