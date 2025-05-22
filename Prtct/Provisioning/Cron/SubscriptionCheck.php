<?php
// app/code/Prtct/Provisioning/Cron/SubscriptionCheck.php
namespace Prtct\Provisioning\Cron;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Prtct\Provisioning\Model\ApiKeyService;
use Prtct\Provisioning\Model\ResourceModel\ApiKey\CollectionFactory;
use Psr\Log\LoggerInterface;

class SubscriptionCheck
{
    public function __construct(
        private ScopeConfigInterface $scopeConfig,
        private Curl                 $curl,
        private ApiKeyService        $apiKeyService,
        private CollectionFactory    $collectionFactory,
        private LoggerInterface      $logger
    ) {}

    public function execute()
    {
        $this->logger->info('Cron: PRTCT subscription check gestart');

        $apiUrl    = rtrim($this->scopeConfig->getValue('prtct_provisioning/general/api_url'), '/');
        $masterKey = $this->scopeConfig->getValue('prtct_provisioning/general/api_key');

        // Een health-check voordat we starten
        if (! $this->apiKeyService->healthCheck()) {
            $this->logger->error('PRTCT API niet bereikbaar, cron afgebroken.');
            return;
        }

        // Loop alle nog actieve subscriptions
        $collection = $this->collectionFactory->create()
            ->addFieldToFilter('status', 'active');

        foreach ($collection as $record) {
            $subId = $record->getSubscriptionId();

            // Check abonnement-status bij PRTCT
            $this->curl->addHeader('Authorization', "Bearer {$masterKey}");
            $this->curl->get("{$apiUrl}/api/v1/subscriptions/{$subId}");

            if ($this->curl->getStatus() === 200) {
                $data   = json_decode($this->curl->getBody(), true);
                $status = $data['data']['status'] ?? 'inactive';

                if ($status !== 'active') {
                    // 1) Revoke alle abilities
                    $this->apiKeyService->changeAbilities($record->getClientApiKey(), []);
                    // 2) Update eigen tabel
                    $record->setStatus('inactive')->save();
                    $this->logger->info("Cron: key voor {$subId} gedeactiveerd");
                }
            } else {
                $this->logger->error("Cron: fout bij ophalen status voor {$subId}");
            }
        }
        $this->logger->info('Cron: PRTCT subscription check voltooid');
    }
}
