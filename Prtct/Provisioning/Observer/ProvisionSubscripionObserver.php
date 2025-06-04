<?php
namespace Prtct\Provisioning\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Prtct\Provisioning\Model\ApiKeyService;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;
use Prtct\Provisioning\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;

class ProvisionSubscriptionObserver implements ObserverInterface
{
    public function __construct(
        private ApiKeyService            $apiKeyService,
        private OrderRepositoryInterface $orderRepo,
        private OrderCollectionFactory   $orderCollectionFactory,
        private LoggerInterface          $logger
    ) {}

    public function execute(Observer $observer)
    {
        // 1) Lees webhook payload
        $payload = $observer->getEvent()->getData('webhook_payload'); // Haal de payload op uit het event
        $subId   = $payload['subscription_id'] ?? null; // Haal de subscription_id uit de payload, of null als deze niet bestaat
        if (! $subId) {
            $this->logger->error("ProvisionSubscriptionObserver: geen subscription_id in payload.");
            return;
        }

        // 2) Haal order op via increment_id
        $collection = $this->orderCollectionFactory->create() // Maak een nieuwe order-collectie
            ->addFieldToFilter('increment_id', $subId) // Filter de collectie op het increment_id dat overeenkomt met de subscription_id
            ->setPageSize(1); // Beperk de collectie tot 1 item
        $order = $collection->getFirstItem(); 
        if (! $order->getId()) {
            $this->logger->error("ProvisionSubscriptionObserver: order #{$subId} niet gevonden.");
            return;
        }

        // 3) Haal bestaande client_api_key op
        $extension = $order->getExtensionAttributes(); // Haal de extensie-attributen van de order op // Dit is waar we extra informatie aan de order kunnen toevoegen
        $clientKey = $extension ? $extension->getClientApiKey() : null; // Haal de client API key op uit de extensie-attributen
        if (! $clientKey) { // Controleer of de client API key bestaat
            $this->logger->error("ProvisionSubscriptionObserver: geen client key op order #{$subId} voor reactivering.");
            return;
        }

        // 4) Re-activate alle abilities bij PRTCT
        $abilities = [
            'health:check', // Controleer of de PRTCT API bereikbaar is // Dit is een basiscontrole om te zien of de API werkt
            'pass:check', // Controleer of  de gebruiker toegang heeft tot de service
            'userpass:check', 
            'statistics:get' 
        ];
        $success = $this->apiKeyService->changeAbilities($clientKey, $abilities); // Probeer de abilities opnieuw te activeren bij PRTCT
        if (! $success) { 
            $this->logger->error("ProvisionSubscriptionObserver: kon abilities niet re-activeren voor key {$clientKey}.");
            return;
        }

        // 5) Markeer order als provisioned = true
        $extension->setProvisioned(true); // Zet de provisioned status op true in de extensie-attributen
        $order->setExtensionAttributes($extension); // Zet de aangepaste extensie-attributen terug op de order
        $order->setData('provisioned', 1); // Zet de 'provisioned' kolom op 1 in de order data
        $this->orderRepo->save($order); // Sla de order op met de nieuwe extensie-attributen en data

        $this->logger->info("ProvisionSubscriptionObserver: client key {$clientKey} opnieuw geactiveerd voor order #{$subId}.");
    }
}
// This observer listens for subscription provisioning events and reactivates the client API key