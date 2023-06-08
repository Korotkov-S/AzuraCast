<?php

declare(strict_types=1);

namespace App\Radio;

use App\Container\EntityManagerAwareTrait;
use App\Container\EnvironmentAwareTrait;
use App\Container\LoggerAwareTrait;
use App\Entity\Station;
use App\Exception\Supervisor\AlreadyRunningException;
use App\Exception\Supervisor\NotRunningException;
use App\Exception\SupervisorException;
use App\Http\Router;
use Psr\EventDispatcher\EventDispatcherInterface;
use Supervisor\Exception\Fault;
use Supervisor\Exception\SupervisorException as SupervisorLibException;
use Supervisor\SupervisorInterface;

abstract class AbstractLocalAdapter
{
    use LoggerAwareTrait;
    use EntityManagerAwareTrait;
    use EnvironmentAwareTrait;

    public function __construct(
        protected SupervisorInterface $supervisor,
        protected EventDispatcherInterface $dispatcher,
        protected Router $router,
    ) {
    }

    /**
     * Write configuration from Station object to the external service.
     *
     * @param Station $station
     *
     * @return bool Whether the newly written configuration differs from what was already on disk.
     */
    public function write(Station $station): bool
    {
        $configPath = $this->getConfigurationPath($station);
        if (null === $configPath) {
            return false;
        }

        $currentConfig = (is_file($configPath))
            ? file_get_contents($configPath)
            : null;

        $newConfig = $this->getCurrentConfiguration($station);

        file_put_contents($configPath, $newConfig);

        return 0 !== strcmp($currentConfig ?: '', $newConfig ?: '');
    }

    /**
     * Generate the configuration for this adapter as it would exist with current database settings.
     *
     * @param Station $station
     *
     */
    public function getCurrentConfiguration(Station $station): ?string
    {
        return null;
    }

    /**
     * Returns the main path where configuration data is stored for this adapter.
     *
     */
    public function getConfigurationPath(Station $station): ?string
    {
        return null;
    }

    /**
     * Indicate if the adapter in question is installed on the server.
     */
    public function isInstalled(): bool
    {
        return (null !== $this->getBinary());
    }

    /**
     * Return the binary executable location for this item.
     *
     * @return string|null Returns either the path to the binary if it exists or null for no binary.
     */
    public function getBinary(): ?string
    {
        return null;
    }

    /**
     * Check if the service is running.
     *
     * @param Station $station
     */
    public function isRunning(Station $station): bool
    {
        if (!$this->hasCommand($station)) {
            return true;
        }

        $program_name = $this->getSupervisorFullName($station);

        try {
            return $this->supervisor->getProcess($program_name)->isRunning();
        } catch (Fault\BadNameException) {
            return false;
        }
    }

    /**
     * Return a boolean indicating whether the adapter has an executable command associated with it.
     *
     * @param Station $station
     */
    public function hasCommand(Station $station): bool
    {
        if ($this->environment->isTesting() || !$station->getIsEnabled()) {
            return false;
        }

        return ($this->getCommand($station) !== null);
    }

    /**
     * Return the shell command required to run the program.
     *
     * @param Station $station
     */
    public function getCommand(Station $station): ?string
    {
        return null;
    }

    /**
     * Return the program's fully qualified supervisord name.
     *
     * @param Station $station
     */
    abstract public function getSupervisorProgramName(Station $station): string;

    public function getSupervisorFullName(Station $station): string
    {
        return sprintf(
            '%s:%s',
            Configuration::getSupervisorGroupName($station),
            $this->getSupervisorProgramName($station)
        );
    }

    /**
     * Restart the executable service.
     *
     * @param Station $station
     */
    public function restart(Station $station): void
    {
        $this->stop($station);
        $this->start($station);
    }

    /**
     * Execute a non-destructive reload if the adapter supports it.
     *
     * @param Station $station
     */
    public function reload(Station $station): void
    {
        $this->restart($station);
    }

    /**
     * Stop the executable service.
     *
     * @param Station $station
     *
     * @throws SupervisorException
     * @throws NotRunningException
     */
    public function stop(Station $station): void
    {
        if ($this->hasCommand($station)) {
            $program_name = $this->getSupervisorFullName($station);

            try {
                $this->supervisor->stopProcess($program_name);
                $this->logger->info(
                    'Adapter "' . static::class . '" stopped.',
                    ['station_id' => $station->getId(), 'station_name' => $station->getName()]
                );
            } catch (SupervisorLibException $e) {
                $this->handleSupervisorException($e, $program_name, $station);
            }
        }
    }

    /**
     * Start the executable service.
     *
     * @param Station $station
     *
     * @throws SupervisorException
     * @throws AlreadyRunningException
     */
    public function start(Station $station): void
    {
        if ($this->hasCommand($station)) {
            $program_name = $this->getSupervisorFullName($station);

            try {
                $this->supervisor->startProcess($program_name);
                $this->logger->info(
                    'Adapter "' . static::class . '" started.',
                    ['station_id' => $station->getId(), 'station_name' => $station->getName()]
                );
            } catch (SupervisorLibException $e) {
                $this->handleSupervisorException($e, $program_name, $station);
            }
        }
    }

    /**
     * Internal handling of any Supervisor-related exception, to add richer data to it.
     *
     * @param SupervisorLibException $e
     * @param string $program_name
     * @param Station $station
     *
     * @throws SupervisorException
     */
    protected function handleSupervisorException(
        SupervisorLibException $e,
        string $program_name,
        Station $station
    ): void {
        $eNew = SupervisorException::fromSupervisorLibException($e, $program_name);
        $eNew->addLoggingContext('station_id', $station->getId());
        $eNew->addLoggingContext('station_name', $station->getName());

        throw $eNew;
    }

    /**
     * Return the path where logs are written to.
     *
     * @param Station $station
     */
    public function getLogPath(Station $station): string
    {
        $config_dir = $station->getRadioConfigDir();

        $class_parts = explode('\\', static::class);
        $class_name = array_pop($class_parts);

        return $config_dir . '/' . strtolower($class_name) . '.log';
    }
}
