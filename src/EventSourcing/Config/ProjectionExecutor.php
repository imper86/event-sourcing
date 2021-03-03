<?php


namespace Ecotone\EventSourcing\Config;


use Ecotone\EventSourcing\LazyProophProjectionManager;
use Ecotone\EventSourcing\ProjectionConfiguration;
use Ecotone\Messaging\Gateway\MessagingEntrypoint;
use Ecotone\Modelling\Attribute\IgnorePayload;
use Prooph\Common\Messaging\Message;

class ProjectionExecutor
{
    private LazyProophProjectionManager $lazyProophProjectionManager;
    private ProjectionConfiguration $projectionConfiguration;

    public function __construct(LazyProophProjectionManager $lazyProophProjectionManager, ProjectionConfiguration $projectionConfiguration)
    {
        $this->lazyProophProjectionManager = $lazyProophProjectionManager;
        $this->projectionConfiguration = $projectionConfiguration;
    }

    #[IgnorePayload]
    public function execute(MessagingEntrypoint $messagingEntrypoint) : void
    {
        if ($this->projectionConfiguration->getProjectionLifeCycleConfiguration()->getInitializationRequestChannel()) {
            $messagingEntrypoint->send([], $this->projectionConfiguration->getProjectionLifeCycleConfiguration()->getInitializationRequestChannel());
        }

        $handlers = [];
        foreach ($this->projectionConfiguration->getProjectionEventHandlerChannels() as $eventName => $targetChannel) {
            $handlers[$eventName] = function ($state, Message $event) use ($messagingEntrypoint, $targetChannel) : mixed {
                $result = $messagingEntrypoint->sendWithHeaders(
                    $event->payload(),
                    array_merge($event->metadata(), ["projection.state" => $state, "projection.name" => $this->projectionConfiguration->getProjectionName()]),
                    $targetChannel
                );

                return $this->projectionConfiguration->isKeepingStateBetweenEvents() ? $result : null;
            };
        }

        $projection = $this->lazyProophProjectionManager->createReadModelProjection($this->projectionConfiguration->getProjectionName(), new ProophReadModel(), $this->projectionConfiguration->getProjectionOptions())
                        ->when($handlers);
        if  ($this->projectionConfiguration->isWithAllStreams()) {
            $projection = $projection->fromAll();
        }else if ($this->projectionConfiguration->getCategories()) {
            $projection = $projection->fromCategories(...$this->projectionConfiguration->getCategories());
        }else if ($this->projectionConfiguration->getStreamNames()) {
            $projection = $projection->fromStreams(...$this->projectionConfiguration->getStreamNames());
        }

        $projection->run(false);
    }
}