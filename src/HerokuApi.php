<?php
declare(strict_types=1);

namespace HerokuApiClient;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use HerokuApiClient\Exceptions\HerokuApiException;
use Psr\Log\LoggerInterface;

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
     *
     * @return array
     */
    public static function getAvailableDynoTypes() : array
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

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var string
     */
    private $app;

    /**
     * @var Client
     */
    private $client;

    /**
     * @param LoggerInterface $logger
     * @param string $app
     * @param string $apiKey
     */
    public function __construct(LoggerInterface $logger, string $app, string $apiKey)
    {
        $this->logger = $logger;
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
     * @param string $dynoType
     * @param float $allowSwap The percentage of available swap that should be allowed to use.
     */
    public static function setMemoryLimitBasedOnDynoType(string $dynoType, float $allowSwap = 0)
    {
        self::validateDynoType($dynoType);

        if ($allowSwap < 0 || $allowSwap > 1) {
            throw new \InvalidArgumentException('$allowSwap must be a value between 0 and 1');
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
     * @param string $command
     * @param string $dynoType
     * @return string
     * @throws HerokuApiException
     */
    public function runOneOffDyno(string $command, string $dynoType = self::DYNO_TYPE_STANDARD_1X) : string
    {
        self::validateDynoType($dynoType);

        try {
            $response = $this->client->post(
                'apps/' . $this->app. '/dynos',
                ['json' => ['attach' => false, 'command' => $command, 'size' => $dynoType,],]
            );
        } catch (RequestException $e) {
            $this->logger->error('Heroku API request to run one-off dyno failed (' . $e->getMessage() . ').');
            throw new HerokuApiException();
        }

        $body = $response->getBody()->getContents();
        $contents = json_decode($body, true);
        if (!is_array($contents) || !array_key_exists('name', $contents)) {
            $this->logger->error('Heroku API request to run one-off dyno failed (response: ' . $body . ').');
            throw new HerokuApiException();
        }

        $this->logger->debug(
            'One-off dyno "' . $contents['name'] . '" (' . $dynoType . ') has been triggered to execute "'
                . $command . '".'
        );

        return $contents['name'];
    }

    /**
     * Returns a list of all currently running dynos.
     *
     * This endpoint often needs more than 3 seconds to answer.
     *
     * @param int $attempts
     * @return array
     * @throws HerokuApiException
     */
    public function getDynoList(int $attempts = 1) : array
    {
        try {
            $response = $this->client->get('apps/' . $this->app. '/dynos', ['timeout' => 10,]);
        } catch (RequestException $e) {
            $error = 'Heroku API request to get dyno list failed (' . $e->getMessage() . ')';
            if ($attempts > 1) {
                $this->logger->error($error . '; will retry now.');
                return $this->getDynoList(--$attempts);
            } else {
                $this->logger->error($error);
                throw new HerokuApiException();
            }
        }

        $body = $response->getBody()->getContents();
        $contents = json_decode($body, true);
        if (!is_array($contents)) {
            $error = 'Heroku API request to get dyno list failed (response: ' . $body . ').';
            if ($attempts > 1) {
                $this->logger->error($error . '; will retry now.');
                return $this->getDynoList(--$attempts);
            } else {
                $this->logger->error($error);
                throw new HerokuApiException();
            }
        }

        return $contents;
    }

    /**
     * Updates the given process type of the dyno formation.
     *
     * TODO: There is also a method for bulk updates.
     *
     * @param string $process
     * @param int $quantity
     * @param string $dynoType
     * @throws HerokuApiException
     */
    public function updateFormation(string $process, int $quantity, string $dynoType)
    {
        self::validateDynoType($dynoType);

        try {
            $response = $this->client->patch(
                'apps/' . $this->app. '/formation/' . $process,
                ['json' => ['quantity' => $quantity, 'size' => $dynoType],]
            );
        } catch (RequestException $e) {
            $this->logger->error('Heroku API request to update formation failed (' . $e->getMessage() . ').');
            throw new HerokuApiException();
        }

        $body = $response->getBody()->getContents();
        $contents = json_decode($body, true);
        if (!is_array($contents) || array_diff(['size', 'quantity', 'type'], array_keys($contents))) {
            $this->logger->error('Heroku API request to update formation failed (unexpected response: ' . $body . ').');
            throw new HerokuApiException();
        }

        $this->logger->debug(
            'Updated formation ' . '(changed process type "' . $process . '" to dyno type "' . $dynoType
                . '" and quantity ' . $quantity . ').'
        );
    }

    /**
     * @param string $dynoType
     */
    public static function validateDynoType(string $dynoType)
    {
        if (!in_array($dynoType, self::getAvailableDynoTypes())) {
            throw new \InvalidArgumentException('Dyno type "' . $dynoType . '" not supported.');
        }
    }
}
