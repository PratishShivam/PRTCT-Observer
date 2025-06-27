# PRTCT Provisioning Module for Magento 2

Deze Magento 2-module integreert met de PRTCT API en Mollie Subscriptions. Het doel van de module is om automatisch API-Keys te genereren voor klanten op basis van hun abonnementsstatus. De module activeert of deactiveert deze API-Keys via webhooks en cronjobs.

## Inhoud

- Installatie
- Configuratie
- Technisch overzicht
- Database-aanpassingen
- Belangrijke klassen
- Cronjob planning
- Events & observers
- Gebruikte API endpoints
- Logging
- Vereisten

## Installatie

1. Plaats de module in `app/code/Prtct/Provisioning`.

2. Voer onderstaande commando's uit:
- bin/magento module:enable Prtct_Provisioning
- bin/magento setup:upgrade
- bin/magento cache:flush


## Configuratie

De configuratie van deze module is te vinden in de Magento backend:

Pad: Stores > Configuration > General > PRTCT Provisioning

De volgende velden zijn beschikbaar:

- api_url: De basis-URL van de PRTCT API
- api_key: De master API key die gebruikt wordt voor authenticatie

Deze configuratie wordt opgeslagen in de database in de `core_config_data`-tabel.

## Technisch overzicht

1. Token generatie bij bestelling:
   - Event: `checkout_submit_all_after`
   - Observer: `SubscriptionCreated`
   - Actie: maakt via de PRTCT API een nieuwe client API-key aan en slaat deze op in `sales_order` samen met een `provisioned`-status.

2. Webhook voor verlenging:
   - Event: `prtct_provisioning_subscription_renew`
   - Observer: `ProvisionSubscriptionObserver`
   - Actie: activeert abilities opnieuw via `PUT /api/v1/apikey/change/abilities` en zet de `provisioned`-status naar `1`.

3. Periodieke cronjob:
   - Class: `SubscriptionCheck`
   - Actie: controleert middernacht of een abonnement nog actief is via de Mollie API. Indien niet actief, worden rechten ingetrokken en de `provisioned`-status op `0` gezet.

## Database-aanpassingen

De volgende kolommen worden toegevoegd aan de `sales_order`-tabel:

- client_api_key (varchar(255), nullable): de gegenereerde PRTCT toegangstoken
- provisioned (tinyint, default 0, not null): geeft aan of een order geprovisioneerd/actief is

## Belangrijke klassen

- `Prtct\Provisioning\Model\ApiKeyService`: Verzorgt alle communicatie met de externe PRTCT API.
- `Prtct\Provisioning\Observer\SubscriptionCreated`: Wordt uitgevoerd na succesvolle checkout en vraagt een API-key aan.
- `Prtct\Provisioning\Observer\ProvisionSubscriptionObserver`: Wordt aangeroepen bij verlenging van een abonnement.
- `Prtct\Provisioning\Cron\SubscriptionCheck`: Cronjob die elk dag draait om abonnementstatussen te controleren.
- `Prtct\Provisioning\Model\ResourceModel\Order\Collection`: Een uitbreiding van Magento’s standaard sales_order-collectie die het mogelijk maakt om te filteren op aangepaste velden zoals client_api_key en provisioned, die door deze module aan de sales_order tabel zijn toegevoegd.

## Cronjob planning

De module bevat een cronjob die is gedefinieerd in `etc/crontab.xml`. De job draait elke nacht om 12 uur:
- Jobnaam: prtct_subscription_check
- Schedule: 0 0 * * *
- Method: execute
- Class: Prtct\Provisioning\Cron\SubscriptionCheck



## Events & observers

De module reageert op de volgende Magento events:

- `checkout_submit_all_after`:
  - Observer: `SubscriptionCreated`
  - Actie: Provisioning uitvoeren bij nieuwe bestelling

- `prtct_provisioning_subscription_renew`:
  - Observer: `ProvisionSubscriptionObserver`
  - Actie: Abonnement heractiveren en toegang herstellen

## Gebruikte API endpoints

De module gebruikt de volgende HTTP-endpoints van de externe PRTCT API:

- `GET /api/v1/health/check`: controleert of de API bereikbaar is
- `POST /api/v1/apikey/create`: maakt een nieuwe API-key aan
- `PUT /api/v1/apikey/change/abilities`: past abilities aan van een bestaande client API-key

## Logging

De module gebruikt `Psr\Log\LoggerInterface` voor logging. Meldingen worden geschreven naar `var/log/system.log`.

Voorbeelden van logmeldingen:

- `SubscriptionCreated: token opgeslagen voor order #100000123`
- `DeactivateSubscriptionObserver: order #100000123 gedeprovisioneerd`
- `PRTCT:createClientKey → HTTP 200`

## Vereisten

- Mollie Subscriptions module
- Een geldige PRTCT API endpoint en master API-key
