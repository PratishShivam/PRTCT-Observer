<?php
namespace Prtct\Provisioning\Cron;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Prtct\Provisioning\Model\ApiKeyService;
use Prtct\Provisioning\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Psr\Log\LoggerInterface;

class SubscriptionCheck
{
    public function __construct( // Constructor voor de SubscriptionCheck class
        private ScopeConfigInterface     $scopeConfig, // Magento's configuratie-interface
        private ApiKeyService            $apiKeyService, // Service voor het beheren van API-sleutels en abonnementen
        private OrderCollectionFactory   $orderCollectionFactory, // Factory voor het ophalen van order-collecties
        private LoggerInterface          $logger // Logger voor het loggen van berichten
    ) {}

    public function execute() // Deze methode wordt uitgevoerd door de cron job
    {
        $this->logger->info('Cron: PRTCT subscription check gestart');

        // 1) Health-check: stop de cron bij falen
        if (! $this->apiKeyService->healthCheck()) { // Controleer of de PRTCT API bereikbaar is
            $this->logger->error('Cron: PRTCT API niet bereikbaar, cron afgebroken.');
            return;
        }

        // 2) Haal alle orders met een client_api_key (dus actief lijken)
        $collection = $this->orderCollectionFactory->create() // Maak een nieuwe order-collectie
            ->addFieldToFilter('client_api_key', ['notnull' => true]); // Filter de collectie op orders die een client_api_key hebben (dus actief lijken)

        // 3) Loop door iedere order
        foreach ($collection as $order) { // Loop door alle orders in de collectie
            $subId     = $order->getIncrementId(); // Haal de subscription_id op uit het increment_id van de order
            $clientKey = $order->getData('client_api_key'); // Haal de client_api_key op uit de order data

            // 4) Vraag de status op bij PRTCT
            $status = $this->apiKeyService->checkSubscriptionStatus($subId); // Vraag de status van de subscription op bij PRTCT

            if ($status !== 'active') {
                // 5) Deactiveer alle abilities
                $this->apiKeyService->changeAbilities($clientKey, []); // Verwijder alle abilities van de client key bij PRTCT
                // 6) Verwijder de key en markeer provisioned = false 
                $order->setData('client_api_key', null); // Verwijder de client_api_key uit de order data
                $order->setData('provisioned', 0); // Zet provisioned op false (0) in de order data
                $order->save(); 
                $this->logger->info("Cron: client key voor order #{$subId} gedeactiveerd.");
            }
        }

        $this->logger->info('Cron: PRTCT subscription check voltooid');
    }
}
// This cron job checks all active subscriptions and deactivates them if they are not active anymore.