<?php

namespace Test\Ecotone\EventSourcing\Behat\Bootstrap;

use Behat\Gherkin\Node\TableNode;
use Behat\Behat\Tester\Exception\PendingException;
use Behat\Behat\Context\Context;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Ecotone\EventSourcing\Config\EventSourcingModule;
use Ecotone\Lite\EcotoneLiteConfiguration;
use Ecotone\Lite\InMemoryPSRContainer;
use Ecotone\Messaging\Config\ConfiguredMessagingSystem;
use Ecotone\Messaging\Config\ServiceConfiguration;
use Ecotone\Modelling\CommandBus;
use Ecotone\Modelling\QueryBus;
use Enqueue\Dbal\DbalConnectionFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Test\Ecotone\EventSourcing\Fixture\Basket\Basket;
use Test\Ecotone\EventSourcing\Fixture\Basket\BasketEventConverter;
use Test\Ecotone\EventSourcing\Fixture\Basket\Command\AddProduct;
use Test\Ecotone\EventSourcing\Fixture\Basket\Command\CreateBasket;
use Test\Ecotone\EventSourcing\Fixture\Basket\Projection\BasketList;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\CloseTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\Command\RegisterTicket;
use Test\Ecotone\EventSourcing\Fixture\Ticket\TicketEventConverter;
use Test\Ecotone\EventSourcing\Fixture\TicketWithPollingProjection\InProgressTicketList;

class DomainContext extends TestCase implements Context
{
    private static ConfiguredMessagingSystem $messagingSystem;
    private static ?Connection $connection = null;

    /**
     * @Given I active messaging for namespace :namespace
     */
    public function iActiveMessagingForNamespace(string $namespace)
    {
        $this->prepareMessaging([$namespace]);
    }

    /**
     * @Given I active messaging for namespaces
     */
    public function iActiveMessagingForNamespaces(TableNode $table)
    {
        $this->prepareMessaging($table->getColumn(0));
    }

    /**
     * @AfterScenario
     */
    public function rollBack(): void
    {
        if (self::$connection && self::$connection->isTransactionActive()) {
            self::$connection->rollBack();
        }
    }

    private function getCommandBus(): CommandBus
    {
        return self::$messagingSystem->getGatewayByName(CommandBus::class);
    }

    private function getQueryBus(): QueryBus
    {
        return self::$messagingSystem->getGatewayByName(QueryBus::class);
    }

    /**
     * @When I register :ticketType ticket :id with assignation to :assignedPerson
     */
    public function iRegisterTicketWithAssignationTo(string $ticketType, int $id, string $assignedPerson)
    {
        $this->getCommandBus()->send(new RegisterTicket(
            $id,
            $assignedPerson,
            $ticketType
        ));
    }

    /**
     * @Then I should see tickets in progress:
     */
    public function iShouldSeeTicketsInProgress(TableNode $table)
    {
        $this->assertEquals(
            $table->getHash(),
            $this->getQueryBus()->sendWithRouting("getInProgressTickets", [])
        );
    }

    /**
     * @When I close ticket with id :arg1
     */
    public function iCloseTicketWithId(string $ticketId)
    {
        $this->getCommandBus()->send(new CloseTicket($ticketId));
    }

    /**
     * @When I delete projection for all in progress tickets
     */
    public function iDeleteProjectionForAllInProgressTickets()
    {
        self::$messagingSystem->runConsoleCommand(EventSourcingModule::ECOTONE_ES_DELETE_PROJECTION, ["name" => InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION]);
    }

    /**
     * @Then there should be no in progress ticket list
     */
    public function thereShouldBeNoInProgressTicketList()
    {
        $wasProjectionDeleted = false;
        try {
            $result = $this->getQueryBus()->sendWithRouting("getInProgressTickets", []);
        }catch (TableNotFoundException $exception) {
            $result = [];
        }

        if (!$result) {
            $wasProjectionDeleted = true;
        }

        $this->assertTrue($wasProjectionDeleted, "Projection was not deleted");
    }

    /**
     * @When I reset the projection for in progress tickets
     */
    public function iResetTheProjectionForInProgressTickets()
    {
        self::$messagingSystem->runConsoleCommand(EventSourcingModule::ECOTONE_ES_RESET_PROJECTION, ["name" => InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION]);
    }

    /**
     * @When I stop the projection for in progress tickets
     */
    public function iStopTheProjectionForInProgressTickets()
    {
        self::$messagingSystem->runConsoleCommand(EventSourcingModule::ECOTONE_ES_STOP_PROJECTION, ["name" => InProgressTicketList::IN_PROGRESS_TICKET_PROJECTION]);
    }

    /**
     * @When I create basket with id :id
     */
    public function iCreateBasketWithId(string $id)
    {
        $this->getCommandBus()->send(new CreateBasket($id));
        self::$messagingSystem->run(BasketList::PROJECTION_NAME);
    }

    /**
     * @Then I should see baskets:
     */
    public function iShouldSeeBaskets(TableNode $table)
    {
        $resultsSet = [];
        foreach ($table->getHash() as $row) {
            $resultsSet[$row["id"]] = \json_decode($row["products"], true, 512, JSON_THROW_ON_ERROR);
        }

        $this->assertEquals(
            $resultsSet,
            $this->getQueryBus()->sendWithRouting("getALlBaskets", [])
        );
    }

    /**
     * @When I add product :name to basket with id :basketId
     */
    public function iAddProductToBasketWithId(string $name, string $basketId)
    {
        $this->getCommandBus()->send(new AddProduct($basketId, $name));
        self::$messagingSystem->run(BasketList::PROJECTION_NAME);
    }

    private function prepareMessaging(array $namespaces): void
    {
        $managerRegistryConnectionFactory = new DbalConnectionFactory(["dsn" => 'pgsql://ecotone:secret@database:5432/ecotone']);
        self::$connection                 = $managerRegistryConnectionFactory->createContext()->getDbalConnection();

        $objects = [];
        foreach ($namespaces as $namespace) {
            switch ($namespace) {
                case "Test\Ecotone\EventSourcing\Fixture\Ticket":
                {
                    $objects = array_merge($objects, [new TicketEventConverter()]);
                    break;
                }
                case "Test\Ecotone\EventSourcing\Fixture\Basket":
                {
                    $objects = array_merge($objects, [new BasketEventConverter(), new BasketList()]);
                    break;
                }
                case "Test\Ecotone\EventSourcing\Fixture\TicketWithPollingProjection": {
                    $objects = array_merge($objects, [new InProgressTicketList(self::$connection)]);
                    break;
                }
                case "Test\Ecotone\EventSourcing\Fixture\TicketWithAsynchronousEventDrivenProjection": {
                    $objects = array_merge($objects, [new \Test\Ecotone\EventSourcing\Fixture\TicketWithAsynchronousEventDrivenProjection\InProgressTicketList(self::$connection)]);
                    break;
                }
                case "Test\Ecotone\EventSourcing\Fixture\TicketWithSynchronousEventDrivenProjection": {
                    $objects = array_merge($objects, [new \Test\Ecotone\EventSourcing\Fixture\TicketWithSynchronousEventDrivenProjection\InProgressTicketList(self::$connection)]);
                    break;
                }
                case "Test\Ecotone\EventSourcing\Fixture\TicketWithLimitedLoad": {break;}
                default:
                {
                    throw new InvalidArgumentException("Namespace {$namespace} not yet implemented");
                }
            }
        }

        self::$messagingSystem = EcotoneLiteConfiguration::createWithConfiguration(
            __DIR__ . "/../../../../",
            InMemoryPSRContainer::createFromObjects(
                array_merge(
                    $objects,
                    [
                        "managerRegistry" => $managerRegistryConnectionFactory,
                        DbalConnectionFactory::class => $managerRegistryConnectionFactory
                    ]
                )
            ),
            ServiceConfiguration::createWithDefaults()
                ->withEnvironment("prod")
                ->withNamespaces($namespaces)
                ->withCacheDirectoryPath(sys_get_temp_dir() . DIRECTORY_SEPARATOR . Uuid::uuid4()->toString()),
            [],
            false
        );

        self::$connection->beginTransaction();
    }

    /**
     * @When I run endpoint with name :name
     */
    public function iRunEndpointWithName($name)
    {
        self::$messagingSystem->run($name);
    }
}
