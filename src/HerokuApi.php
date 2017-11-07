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
     * Sets PHP's memory limit based on the the given dyno type's capabilities.
     * @see https://devcenter.heroku.com/articles/limits#dynos
     *
     * @param string $dynoType
     * @param bool $allowSwapping
     */
    public static function setMemoryLimitBasedOnDynoType(string $dynoType, bool $allowSwapping = false)
    {
        self::validateDynoType($dynoType);

        if (in_array($dynoType, [self::DYNO_TYPE_FREE, self::DYNO_TYPE_HOBBY, self::DYNO_TYPE_STANDARD_1X])) {
            ini_set('memory_limit', ($allowSwapping ? '1024M' : '512M'));
        } elseif ($dynoType === self::DYNO_TYPE_STANDARD_2X) {
            ini_set('memory_limit', ($allowSwapping ? '2048M' : '1024M'));
        } elseif ($dynoType === self::DYNO_TYPE_PERFORMANCE_M) {
            ini_set('memory_limit', ($allowSwapping ? '5000M' : '2500M'));
        } elseif ($dynoType === self::DYNO_TYPE_PERFORMANCE_L) {
            ini_set('memory_limit', ($allowSwapping ? '28000M' : '14000M'));
        }
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
            $this->logger->error('Heroku API-request to run one-off dyno failed (' . $e->getMessage() . ').');
            throw new HerokuApiException();
        }

        $body = $response->getBody()->getContents();
        $contents = json_decode($body, true);
        if (!is_array($contents) || !array_key_exists('name', $contents)) {
            $this->logger->error('Heroku API-request to run one-off dyno failed (response: ' . $body . ').');
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
            $error = 'Heroku API-request to get dyno list failed (' . $e->getMessage() . ')';
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
            $error = 'Heroku API-request to get dyno list failed (response: ' . $body . ').';
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
     * @param string $dynoType
     */
    public static function validateDynoType(string $dynoType)
    {
        if (!in_array($dynoType, self::getAvailableDynoTypes())) {
            throw new \InvalidArgumentException('Dyno type "' . $dynoType . '" not supported.');
        }
    }
}
