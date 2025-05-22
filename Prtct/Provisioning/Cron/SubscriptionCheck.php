<?php
/**
 * - Draait elk uur als backup voor de webhooks.
 * - Controleert per actieve order of het abonnement nog actief is bij PRTCT.
 * - Deactiveert alle abilities en verwijdert client_api_key bij verlopen abonnement.
 */
namespace Prtct\Provisioning\Cron;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Prtct\Provisioning\Model\ApiKeyService;
use Prtct\Provisioning\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Psr\Log\LoggerInterface;

class SubscriptionCheck
{
    public function __construct(
        private ScopeConfigInterface     $scopeConfig,         // Voor lezen van admin-config
        private ApiKeyService            $apiKeyService,       // Voor API-calls
        private OrderCollectionFactory   $orderCollectionFactory, // Voor ophalen van orders
        private LoggerInterface          $logger               // Voor logs
    ) {}

    public function execute()
    {
        $this->logger->info('Cron: PRTCT subscription check gestart');

        // 1) Health-check: stop de cron bij falen
        if (! $this->apiKeyService->healthCheck()) {
            $this->logger->error('PRTCT API niet bereikbaar, cron afgebroken.');
            return;
        }

        // 2) Haal alle orders die nog een client-api-key hebben (dus actief lijken)
        $collection = $this->orderCollectionFactory->create()
            ->addFieldToFilter('client_api_key', ['notnull' => true]);

        // 3) Loop door iedere order
        foreach ($collection as $order) {
            $subId    = $order->getIncrementId();                  // order_id = subscription_id
            $clientKey= $order->getData('client_api_key');         // lees de sleutel

            // 4) Vraag de status op bij PRTCT
            $status = $this->apiKeyService->checkSubscriptionStatus($subId);

            if ($status !== 'active') {
                // 5) Deactiveer alle abilities
                $this->apiKeyService->changeAbilities($clientKey, []);
                // 6) Verwijder de key uit de order
                $order->setData('client_api_key', null);
                $order->save(); 
                $this->logger->info("Cron: client key voor order #{$subId} gedeactiveerd.");
            }
        }

        $this->logger->info('Cron: PRTCT subscription check voltooid');
    }
}
