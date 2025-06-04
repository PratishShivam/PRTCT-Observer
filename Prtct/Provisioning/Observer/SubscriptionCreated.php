<?php
namespace Prtct\Provisioning\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Prtct\Provisioning\Model\ApiKeyService;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\Data\OrderExtensionFactory;
use Psr\Log\LoggerInterface;

class SubscriptionCreated implements ObserverInterface
{
    public function __construct(
        private ApiKeyService            $apiKeyService, // Service voor API-key aanmaken
        private OrderRepositoryInterface $orderRepo, // Hiermee kun je bestellingen ophalen, bewerken en opslaan.
        private OrderExtensionFactory    $extFactory, // Hier mee kan er een extension-object aangemaakt worden dat later aan de order gekoppeld kan worden.
        private LoggerInterface          $logger // Logger voor foutopsporing
    ) {}

    public function execute(Observer $observer) // Deze methode wordt aangeroepen wanneer het event 'checkout_submit_all_after' plaatsvindt
    {
        // 1) Haal de order op uit het event (checkout_submit_all_after)
        $order = $observer->getEvent()->getOrder() // Haal de order op uit het event
               ?: current($observer->getEvent()->getOrders()); // Of (?:) haal de eerste order uit de lijst als er meerdere orders zijn
        if (! $order) {
            $this->logger->error('SubscriptionCreated: geen order-gegevens in event.');
            return;
        }

        $incrementId = $order->getIncrementId(); // Haalt het unieke bestelnummer op van de order
        $this->logger->info("SubscriptionCreated: start provisioning voor order #{$incrementId}");

        // 2) SKU-logica uit ProvisionOrderObserver om het plan te bepalen
        $skuPlanMap = [ // Mapping van SKU's naar abonnement-plannen
            'tier-1' => 'tier1',
            'tier-2' => 'tier2', 
            'tier-3' => 'tier3',
        ];
        $plan = 'tier1'; // Standaard plan, kan overschreven worden door SKU
        foreach ($order->getAllVisibleItems() as $item) { // Loop door alle zichtbare items (getoond op frontend) in de order
            $sku = $item->getSku(); // Haal de SKU van het item op
            if (isset($skuPlanMap[$sku])) { // Controleer of de SKU in de mapping (koppeling) zit
                $plan = $skuPlanMap[$sku]; // Zet het plan op basis van de SKU
                break; // Stop de loop als we een geldige SKU hebben gevonden
            }
        }

        // 3) Vraag client-API-key aan bij PRTCT met dynamische 'plan'
        $clientKey = $this->apiKeyService->createClientKey([ // Vraag een API-key aan bij PRTCT
            'customer_email'  => $order->getCustomerEmail(), 
            'subscription_id' => $incrementId, // Gebruik het bestelnummer als subscription_id
            'plan'            => $plan, // Gebruik het dynamische plan dat we hebben bepaald
            'status'          => 'active' // Zet de status op 'active' voor actieve abonnementen
        ]);
        if (! $clientKey) { 
            $this->logger->error("SubscriptionCreated: geen client API-key ontvangen voor order #{$incrementId}");
            return;
        }

        // 4) Sla de key op als extension attribute en in de sales_order-kolom
        // Dit is een manier om extra velden toe te voegen aan de standaard Magento-order zonder ook de Magento-core tabele aan te passen.
        $extension = $this->extFactory->create(); // Maak een nieuw extension object aan
        $extension->setClientApiKey($clientKey); // Zet de client API-key in het extension object
        $extension->setProvisioned(true); // Zet provisioned op true in het extension object

        $order->setExtensionAttributes($extension); // Koppel het extension object aan de order. Vanaf nu kan Magento in het order-object ook deze nieuwe velden herkennen.
        $order->setData('client_api_key', $clientKey); // Sla de client API-key op in de order data
        $order->setData('provisioned', 1); // Sla provisioned op als 1 (true) in de order data

        // 5) Sla de order op, hierdoor worden client_api_key en provisioned weggeschreven
        $this->orderRepo->save($order);
        $this->logger->info("SubscriptionCreated: order #{$incrementId} provisioned; client key opgeslagen.");
    }
}