# Magento_Integration module

This module enables third-party services to call the Web API by using access tokens.
It provides an admin UI that enables manual creation of integrations. Extensions can also provide a configuration
file so that an integration can be automatically pre-configured. The module also contains the data
model for request and access token management.

## Installation

The Magento_Integration module is one of the base Magento 2 modules. You cannot disable or uninstall this module.

This module is dependent on the following modules:
- `Magento_Store`
- `Magento_User`
- `Magento_Security`

The Magento_Integration module creates the following tables in the database:
- `oauth_consumer`
- `oauth_token`
- `oauth_nonce`
- `integration`
- `oauth_token_request_log`

For information about a module installation in Magento 2, see [Enable or disable modules](https://devdocs.magento.com/guides/v2.4/install-gde/install/cli/install-cli-subcommands-enable.html).

## Extensibility

Extension developers can interact with the Magento_Integration module. For more information about the Magento extension mechanism, see [Magento plugins](https://devdocs.magento.com/guides/v2.4/extension-dev-guide/plugins.html).

[The Magento dependency injection mechanism](https://devdocs.magento.com/guides/v2.4/extension-dev-guide/depend-inj.html) enables you to override the functionality of the Magento_Integration module.

### Events

The module dispatches the following events:

#### Model
- `customer_login` event in the `\Magento\Integration\Model\CustomerTokenService::createCustomerAccessToken` method. Parameters:
    - `customer` is an object (`\Magento\Customer\Api\Data\CustomerInterface` class)

For information about an event in Magento 2, see [Events and observers](http://devdocs.magento.com/guides/v2.4/extension-dev-guide/events-and-observers.html#events).

### Layouts

This module introduces the following layout handles in the `view/adminhtml/layout` directory:
- `adminhtml_integration_edit`
- `adminhtml_integration_grid`
- `adminhtml_integration_grid_block`
- `adminhtml_integration_index`
- `adminhtml_integration_new`
- `adminhtml_integration_permissionsdialog`
- `adminhtml_integration_tokensdialog`
- `adminhtml_integration_tokensexchange`

For more information about a layout in Magento 2, see the [Layout documentation](https://devdocs.magento.com/guides/v2.4/frontend-dev-guide/layouts/layout-overview.html).

### Public APIs

- `\Magento\Integration\Api\AdminTokenServiceInterface`:
    - create access token for admin given the admin credentials
    - revoke token by admin ID

- `\Magento\Integration\Api\AuthorizationServiceInterface`:
    - grant permissions to user to access the specified resources
    - grant permissions to the user to access all resources available in the system
    - remove role and associated permissions for the specified integration

- `\Magento\Integration\Api\CustomerTokenServiceInterface`:
    - create access token for admin given the customer credentials
    - revoke token by customer ID

- `\Magento\Integration\Api\IntegrationServiceInterface`:
    - create a new Integration
    - get the details of a specific Integration by integration ID
    - find Integration by name
    - get the details of an Integration by consumer_id
    - get the details of an active Integration by consumer_id
    - update an Integration
    - delete an Integration by integration ID
    - get an array of selected resources  for an integration
  
- `\Magento\Integration\Api\OauthServiceInterface`:
    - create a new consumer account
    - create access token for provided consumer
    - retrieve access token assigned to the consumer
    - load consumer by its ID 
    - load consumer by its key
    - execute post to integration (consumer) HTTP Post URL. Generate and return oauth_verifier
    - delete the consumer data associated with the integration including its token and nonce
    - remove token associated with provided consumer

For information about a public API in Magento 2, see [Public interfaces & APIs](http://devdocs.magento.com/guides/v2.4/extension-dev-guide/api-concepts.html).

## Additional information

### Cron options

Cron group configuration can be set at `etc/crontab.xml`:
- `outdated_authentication_failures_cleanup` - clearing log of outdated token request authentication failures
- `expired_tokens_cleanups` - delete expired customer and admin tokens

[Learn how to configure and run cron in Magento.](http://devdocs.magento.com/guides/v2.4/config-guide/cli/config-cli-subcommands-cron.html).

More information can get at articles:
- [Learn more about an Integration](https://docs.magento.com/user-guide/system/integrations.html)
- [Lear how to create an Integration](https://devdocs.magento.com/guides/v2.4/get-started/create-integration.html)
