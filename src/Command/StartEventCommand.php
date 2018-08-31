<?php

namespace TheAentMachine\AentGeneric\Command;

use Symfony\Component\Console\Output\OutputInterface;
use TheAentMachine\Aenthill\CommonEvents;
use TheAentMachine\Aenthill\CommonMetadata;
use TheAentMachine\Command\AbstractEventCommand;
use TheAentMachine\Docker\ImageService;
use TheAentMachine\Service\Service;

class StartEventCommand extends AbstractEventCommand
{
    protected function getEventName(): string
    {
        return CommonEvents::START_EVENT;
    }

    /**
     * @param null|string $payload
     * @return null|string
     * @throws \TheAentMachine\Exception\CommonAentsException
     */
    protected function executeEvent(?string $payload): ?string
    {
        $imageService = new ImageService($this->log);

        $aentHelper = $this->getAentHelper();
        $aentHelper->title('Adding a new container');
        $service = new Service();

        $image = $this->getAentHelper()->question('Image name')
            ->setHelpText('The name of the Docker image you want to install. For instance: "redis", or "mongo:4.1"')
            ->setValidator(function (string $value) use ($imageService) {
                // If we can't pull, an exception is raised and the validator fails.
                $oldVerbosity = $this->output->getVerbosity();
                $this->output->setVerbosity(OutputInterface::VERBOSITY_DEBUG);

                $imageService->pullIfNotAvailable($value);

                $this->output->setVerbosity($oldVerbosity);

                return $value;
            })
            ->ask();

        $service->setImage($image);

        $environments = $aentHelper->getCommonQuestions()->askForEnvironments();
        if (null !== $environments) {
            $envTypes = array_map(function ($env) {
                return $env[CommonMetadata::ENV_TYPE_KEY];
            }, $environments);
            foreach ($envTypes as $envType) {
                $service->addDestEnvType($envType);
            }
        }

        // serviceName
        $imageNameParts = \explode('/', $image);
        $defaultServiceName = $imageNameParts[count($imageNameParts)-1];
        $defaultServiceName = \explode(':', $defaultServiceName)[0];

        $serviceName = $aentHelper->getCommonQuestions()->askForServiceName($defaultServiceName, $image);
        $service->setServiceName($serviceName);

        $ports = $imageService->getInternalPorts($image);
        foreach ($ports as $port) {
            $service->addInternalPort($port);
            // Heuristic: anything with a port 80, 1080 or 8080 must be HTTP port...
            if (\in_array($port, [80, 1080, 8080], true)) {
                $service->addVirtualHost(null, $port);
            }
        }

        // Volumes
        $volumes = $imageService->getVolumes($image);
        foreach ($volumes as $volume) {
            $volumeName = $serviceName.'_'.\str_replace('/', '_', $volume);
            $service->addNamedVolume($volumeName, $volume);
        }

        CommonEvents::dispatchService($service);
        return null;
    }
}
