<?php

namespace TheAentMachine\AentGeneric\Event;

use Safe\Exceptions\ArrayException;
use Safe\Exceptions\FilesystemException;
use Safe\Exceptions\StringsException;
use Symfony\Component\Console\Logger\ConsoleLogger;
use TheAentMachine\Aent\Event\Service\AbstractServiceAddEvent;
use TheAentMachine\Aent\Event\Service\Model\Environments;
use TheAentMachine\Aent\Event\Service\Model\ServiceState;
use TheAentMachine\Docker\ImageService;
use TheAentMachine\Prompt\Helper\ValidatorHelper;
use TheAentMachine\Service\Service;
use TheAentMachine\Service\Volume\BindVolume;

final class AddEvent extends AbstractServiceAddEvent
{
    /**
     * @param Environments $environments
     * @return ServiceState[]
     * @throws ArrayException
     * @throws FilesystemException
     * @throws StringsException
     */
    protected function createServices(Environments $environments): array
    {
        $image = $this->prompt->getPromptHelper()->getDockerHubImage();
        $this->output->writeln("\nAlright, I'm going to use $image!");
        $imageService = new ImageService(new ConsoleLogger($this->output));
        $imageService->pullIfNotAvailable($image);
        $service = new Service();
        $service->setImage($image);
        $serviceName = $this->prompt->getPromptHelper()->getServiceName();
        $service->setServiceName($serviceName);
        if ($environments->hasTestEnvironments() || $environments->hasProductionEnvironments()) {
            $service->setNeedBuild($this->getNeedBuild());
            if ($service->getNeedBuild()) {
                $service->addDockerfileCommand("FROM $image");
            }
        }
        $bindVolume = $this->getBindVolume();
        if (!empty($bindVolume)) {
            $service->addBindVolume($bindVolume->getSource(), $bindVolume->getTarget());
        }
        $ports = $imageService->getInternalPorts($image);
        if (!empty($ports)) {
            $this->prompt->printAltBlock("Generic: adding internal ports...");
            foreach ($ports as $port) {
                $service->addInternalPort($port);
                // Heuristic: anything with a port 80, 1080 or 8080 must be HTTP port...
                if (\in_array($port, [80, 1080, 8080], true)) {
                    $service->addVirtualHost($port);
                }
            }
        }
        $volumes = $imageService->getVolumes($image);
        if (!empty($volumes)) {
            $this->prompt->printAltBlock("Generic: adding named volumes...");
            foreach ($volumes as $volume) {
                $volumeName = $serviceName.'_'.\str_replace('/', '_', $volume);
                $service->addNamedVolume($volumeName, $volume);
            }
        }
        return [ new ServiceState($service, $service, $service) ];
    }

    /**
     * @return bool
     */
    private function getNeedBuild(): bool
    {
        $text = "\nIs the image buildable?";
        $helpText = "Not recommended for database image.";
        return $this->prompt->confirm($text, $helpText, null, true);
    }

    /**
     * @return null|BindVolume
     */
    private function getBindVolume(): ?BindVolume
    {
        $text = "\nIs this image used to run a source code?";
        $needBindVolume = $this->prompt->confirm($text, null, null, true);
        if ($needBindVolume) {
            $text = "\nImage <info>workspace</info>";
            $helpText = "Workspace is the default directory of the image. For instance, <info>/var/www/html</info> or <info>/usr/src/app</info>.";
            $target = $this->prompt->input($text, $helpText, null, true, ValidatorHelper::getAbsolutePathValidator()) ?? '';
            $text = "\n<info>Application directory</info> (relative to the project root directory)";
            return $this->prompt->getPromptHelper()->getBindVolume($text, $target);
        }
        return null;
    }
}
