<?php
namespace Prtct\Provisioning\Cron;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Prtct\Provisioning\Model\ApiKeyService;
use Prtct\Provisioning\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Psr\Log\LoggerInterface;

class SubscriptionCheck
{
    public function __construct(
        private ScopeConfigInterface     $scopeConfig,
        private ApiKeyService            $apiKeyService,
        private OrderCollectionFactory   $orderCollectionFactory,
        private LoggerInterface          $logger
    ) {}

    public function execute()
    {
        $this->logger->info('Cron: PRTCT subscription check gestart');

        // 1) Health-check: stop de cron bij falen
        if (! $this->apiKeyService->healthCheck()) {
            $this->logger->error('Cron: PRTCT API niet bereikbaar, cron afgebroken.');
            return;
        }

        // 2) Haal alle orders met een client_api_key (dus actief lijken)
        $collection = $this->orderCollectionFactory->create()
            ->addFieldToFilter('client_api_key', ['notnull' => true]);

        // 3) Loop door iedere order
        foreach ($collection as $order) {
            $subId     = $order->getIncrementId();                  // increment_id = subscription_id
            $clientKey = $order->getData('client_api_key');

            // 4) Vraag de status op bij PRTCT
            $status = $this->apiKeyService->checkSubscriptionStatus($subId);

            if ($status !== 'active') {
                // 5) Deactiveer alle abilities
                $this->apiKeyService->changeAbilities($clientKey, []);
                // 6) Verwijder de key en markeer provisioned = false
                $order->setData('client_api_key', null);
                $order->setData('provisioned', 0);
                $order->save();
                $this->logger->info("Cron: client key voor order #{$subId} gedeactiveerd.");
            }
        }

        $this->logger->info('Cron: PRTCT subscription check voltooid');
    }
}
// This cron job checks all active subscriptions and deactivates them if they are not active anymore.