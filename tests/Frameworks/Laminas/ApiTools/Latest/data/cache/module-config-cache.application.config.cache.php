<?php
return [
    'service_manager' => [
        'aliases' => [
            'MvcTranslator' => 'Laminas\\Mvc\\I18n\\Translator',
            'Zend\\Mvc\\I18n\\Translator' => 'Laminas\\Mvc\\I18n\\Translator',
            'TranslatorPluginManager' => 'Laminas\\I18n\\Translator\\LoaderPluginManager',
            'Zend\\I18n\\Translator\\TranslatorInterface' => 'Laminas\\I18n\\Translator\\TranslatorInterface',
            'Zend\\I18n\\Translator\\LoaderPluginManager' => 'Laminas\\I18n\\Translator\\LoaderPluginManager',
            'Laminas\\I18n\\Geography\\CountryCodeListInterface' => 'Laminas\\I18n\\Geography\\DefaultCountryCodeList',
            'Laminas\\Db\\Adapter\\Adapter' => 'Laminas\\Db\\Adapter\\AdapterInterface',
            'Zend\\Db\\Adapter\\AdapterInterface' => 'Laminas\\Db\\Adapter\\AdapterInterface',
            'Zend\\Db\\Adapter\\Adapter' => 'Laminas\\Db\\Adapter\\Adapter',
            'FilterManager' => 'Laminas\\Filter\\FilterPluginManager',
            'Zend\\Filter\\FilterPluginManager' => 'Laminas\\Filter\\FilterPluginManager',
            'HydratorManager' => 'Laminas\\Hydrator\\HydratorPluginManager',
            'Zend\\Hydrator\\HydratorPluginManager' => 'Laminas\\Hydrator\\HydratorPluginManager',
            'Zend\\Hydrator\\StandaloneHydratorPluginManager' => 'Laminas\\Hydrator\\StandaloneHydratorPluginManager',
            'InputFilterManager' => 'Laminas\\InputFilter\\InputFilterPluginManager',
            'Zend\\InputFilter\\InputFilterPluginManager' => 'Laminas\\InputFilter\\InputFilterPluginManager',
            'Zend\\Paginator\\AdapterPluginManager' => 'Laminas\\Paginator\\AdapterPluginManager',
            'Zend\\Paginator\\ScrollingStylePluginManager' => 'Laminas\\Paginator\\ScrollingStylePluginManager',
            'HttpRouter' => 'Laminas\\Router\\Http\\TreeRouteStack',
            'router' => 'Laminas\\Router\\RouteStackInterface',
            'Router' => 'Laminas\\Router\\RouteStackInterface',
            'RoutePluginManager' => 'Laminas\\Router\\RoutePluginManager',
            'Zend\\Router\\Http\\TreeRouteStack' => 'Laminas\\Router\\Http\\TreeRouteStack',
            'Zend\\Router\\RoutePluginManager' => 'Laminas\\Router\\RoutePluginManager',
            'Zend\\Router\\RouteStackInterface' => 'Laminas\\Router\\RouteStackInterface',
            'ValidatorManager' => 'Laminas\\Validator\\ValidatorPluginManager',
            'Zend\\Validator\\ValidatorPluginManager' => 'Laminas\\Validator\\ValidatorPluginManager',
            'ZF\\Apigility\\MvcAuth\\UnauthenticatedListener' => 'Laminas\\ApiTools\\MvcAuth\\UnauthenticatedListener',
            'ZF\\Apigility\\MvcAuth\\UnauthorizedListener' => 'Laminas\\ApiTools\\MvcAuth\\UnauthorizedListener',
            'ZF\\Apigility\\Documentation\\ApiFactory' => 'Laminas\\ApiTools\\Documentation\\ApiFactory',
            'Laminas\\ApiTools\\ApiProblem\\ApiProblemListener' => 'Laminas\\ApiTools\\ApiProblem\\Listener\\ApiProblemListener',
            'Laminas\\ApiTools\\ApiProblem\\RenderErrorListener' => 'Laminas\\ApiTools\\ApiProblem\\Listener\\RenderErrorListener',
            'Laminas\\ApiTools\\ApiProblem\\ApiProblemRenderer' => 'Laminas\\ApiTools\\ApiProblem\\View\\ApiProblemRenderer',
            'Laminas\\ApiTools\\ApiProblem\\ApiProblemStrategy' => 'Laminas\\ApiTools\\ApiProblem\\View\\ApiProblemStrategy',
            'ZF\\ApiProblem\\ApiProblemListener' => 'Laminas\\ApiTools\\ApiProblem\\ApiProblemListener',
            'ZF\\ApiProblem\\RenderErrorListener' => 'Laminas\\ApiTools\\ApiProblem\\RenderErrorListener',
            'ZF\\ApiProblem\\ApiProblemRenderer' => 'Laminas\\ApiTools\\ApiProblem\\ApiProblemRenderer',
            'ZF\\ApiProblem\\ApiProblemStrategy' => 'Laminas\\ApiTools\\ApiProblem\\ApiProblemStrategy',
            'ZF\\ApiProblem\\Listener\\ApiProblemListener' => 'Laminas\\ApiTools\\ApiProblem\\Listener\\ApiProblemListener',
            'ZF\\ApiProblem\\Listener\\RenderErrorListener' => 'Laminas\\ApiTools\\ApiProblem\\Listener\\RenderErrorListener',
            'ZF\\ApiProblem\\Listener\\SendApiProblemResponseListener' => 'Laminas\\ApiTools\\ApiProblem\\Listener\\SendApiProblemResponseListener',
            'ZF\\ApiProblem\\View\\ApiProblemRenderer' => 'Laminas\\ApiTools\\ApiProblem\\View\\ApiProblemRenderer',
            'ZF\\ApiProblem\\View\\ApiProblemStrategy' => 'Laminas\\ApiTools\\ApiProblem\\View\\ApiProblemStrategy',
            'ZF\\Configuration\\ConfigResource' => 'Laminas\\ApiTools\\Configuration\\ConfigResource',
            'ZF\\Configuration\\ConfigResourceFactory' => 'Laminas\\ApiTools\\Configuration\\ConfigResourceFactory',
            'ZF\\Configuration\\ConfigWriter' => 'Laminas\\ApiTools\\Configuration\\ConfigWriter',
            'ZF\\Configuration\\ModuleUtils' => 'Laminas\\ApiTools\\Configuration\\ModuleUtils',
            'Laminas\\ApiTools\\OAuth2\\Provider\\UserId' => 'Laminas\\ApiTools\\OAuth2\\Provider\\UserId\\AuthenticationService',
            'ZF\\OAuth2\\Provider\\UserId' => 'Laminas\\ApiTools\\OAuth2\\Provider\\UserId',
            'ZF\\OAuth2\\Adapter\\PdoAdapter' => 'Laminas\\ApiTools\\OAuth2\\Adapter\\PdoAdapter',
            'ZF\\OAuth2\\Adapter\\MongoAdapter' => 'Laminas\\ApiTools\\OAuth2\\Adapter\\MongoAdapter',
            'ZF\\OAuth2\\Provider\\UserId\\AuthenticationService' => 'Laminas\\ApiTools\\OAuth2\\Provider\\UserId\\AuthenticationService',
            'ZF\\OAuth2\\Service\\OAuth2Server' => 'Laminas\\ApiTools\\OAuth2\\Service\\OAuth2Server',
            'authentication' => 'Laminas\\ApiTools\\MvcAuth\\Authentication',
            'authorization' => 'Laminas\\ApiTools\\MvcAuth\\Authorization\\AuthorizationInterface',
            'Laminas\\ApiTools\\MvcAuth\\Authorization\\AuthorizationInterface' => 'Laminas\\ApiTools\\MvcAuth\\Authorization\\AclAuthorization',
            'ZF\\Hal\\Extractor\\LinkExtractor' => 'Laminas\\ApiTools\\Hal\\Extractor\\LinkExtractor',
            'ZF\\Hal\\Extractor\\LinkCollectionExtractor' => 'Laminas\\ApiTools\\Hal\\Extractor\\LinkCollectionExtractor',
            'ZF\\Hal\\HalConfig' => 'Laminas\\ApiTools\\Hal\\HalConfig',
            'ZF\\Hal\\JsonRenderer' => 'Laminas\\ApiTools\\Hal\\JsonRenderer',
            'ZF\\Hal\\JsonStrategy' => 'Laminas\\ApiTools\\Hal\\JsonStrategy',
            'ZF\\Hal\\Link\\LinkUrlBuilder' => 'Laminas\\ApiTools\\Hal\\Link\\LinkUrlBuilder',
            'ZF\\Hal\\MetadataMap' => 'Laminas\\ApiTools\\Hal\\MetadataMap',
            'ZF\\Hal\\RendererOptions' => 'Laminas\\ApiTools\\Hal\\RendererOptions'
        ],
        'delegators' => [
            'HttpRouter' => [
                'Laminas\\Mvc\\I18n\\Router\\HttpRouterDelegatorFactory'
            ],
            'Laminas\\Router\\Http\\TreeRouteStack' => [
                'Laminas\\Mvc\\I18n\\Router\\HttpRouterDelegatorFactory'
            ],
            'Laminas\\ApiTools\\MvcAuth\\Authentication\\DefaultAuthenticationListener' => [
                'Laminas\\ApiTools\\MvcAuth\\Factory\\AuthenticationAdapterDelegatorFactory'
            ]
        ],
        'factories' => [
            'Laminas\\Mvc\\I18n\\Translator' => 'Laminas\\Mvc\\I18n\\TranslatorFactory',
            'Laminas\\I18n\\Translator\\TranslatorInterface' => 'Laminas\\I18n\\Translator\\TranslatorServiceFactory',
            'Laminas\\I18n\\Translator\\LoaderPluginManager' => 'Laminas\\I18n\\Translator\\LoaderPluginManagerFactory',
            'Laminas\\I18n\\Geography\\DefaultCountryCodeList' => [
                'Laminas\\I18n\\Geography\\DefaultCountryCodeList',
                'create'
            ],
            'Laminas\\ComposerAutoloading\\Command\\DisableCommand' => 'Laminas\\ComposerAutoloading\\Command\\DisableCommandFactory',
            'Laminas\\ComposerAutoloading\\Command\\EnableCommand' => 'Laminas\\ComposerAutoloading\\Command\\EnableCommandFactory',
            'Laminas\\Db\\Adapter\\AdapterInterface' => 'Laminas\\Db\\Adapter\\AdapterServiceFactory',
            'Laminas\\Filter\\FilterPluginManager' => 'Laminas\\Filter\\FilterPluginManagerFactory',
            'Laminas\\Hydrator\\HydratorPluginManager' => 'Laminas\\Hydrator\\HydratorPluginManagerFactory',
            'Laminas\\Hydrator\\StandaloneHydratorPluginManager' => 'Laminas\\Hydrator\\StandaloneHydratorPluginManagerFactory',
            'Laminas\\InputFilter\\InputFilterPluginManager' => 'Laminas\\InputFilter\\InputFilterPluginManagerFactory',
            'Laminas\\Paginator\\AdapterPluginManager' => 'Laminas\\Paginator\\AdapterPluginManagerFactory',
            'Laminas\\Paginator\\ScrollingStylePluginManager' => 'Laminas\\Paginator\\ScrollingStylePluginManagerFactory',
            'Laminas\\Router\\Http\\TreeRouteStack' => 'Laminas\\Router\\Http\\HttpRouterFactory',
            'Laminas\\Router\\RoutePluginManager' => 'Laminas\\Router\\RoutePluginManagerFactory',
            'Laminas\\Router\\RouteStackInterface' => 'Laminas\\Router\\RouterFactory',
            'Laminas\\Validator\\ValidatorPluginManager' => 'Laminas\\Validator\\ValidatorPluginManagerFactory',
            'Laminas\\ApiTools\\MvcAuth\\UnauthenticatedListener' => 'Laminas\\ServiceManager\\Factory\\InvokableFactory',
            'Laminas\\ApiTools\\MvcAuth\\UnauthorizedListener' => 'Laminas\\ServiceManager\\Factory\\InvokableFactory',
            'Laminas\\ApiTools\\Documentation\\ApiFactory' => 'Laminas\\ApiTools\\Documentation\\Factory\\ApiFactoryFactory',
            'Laminas\\ApiTools\\ApiProblem\\Listener\\ApiProblemListener' => 'Laminas\\ApiTools\\ApiProblem\\Factory\\ApiProblemListenerFactory',
            'Laminas\\ApiTools\\ApiProblem\\Listener\\RenderErrorListener' => 'Laminas\\ApiTools\\ApiProblem\\Factory\\RenderErrorListenerFactory',
            'Laminas\\ApiTools\\ApiProblem\\Listener\\SendApiProblemResponseListener' => 'Laminas\\ApiTools\\ApiProblem\\Factory\\SendApiProblemResponseListenerFactory',
            'Laminas\\ApiTools\\ApiProblem\\View\\ApiProblemRenderer' => 'Laminas\\ApiTools\\ApiProblem\\Factory\\ApiProblemRendererFactory',
            'Laminas\\ApiTools\\ApiProblem\\View\\ApiProblemStrategy' => 'Laminas\\ApiTools\\ApiProblem\\Factory\\ApiProblemStrategyFactory',
            'Laminas\\ApiTools\\Configuration\\ConfigResource' => 'Laminas\\ApiTools\\Configuration\\Factory\\ConfigResourceFactory',
            'Laminas\\ApiTools\\Configuration\\ConfigResourceFactory' => 'Laminas\\ApiTools\\Configuration\\Factory\\ResourceFactoryFactory',
            'Laminas\\ApiTools\\Configuration\\ConfigWriter' => 'Laminas\\ApiTools\\Configuration\\Factory\\ConfigWriterFactory',
            'Laminas\\ApiTools\\Configuration\\ModuleUtils' => 'Laminas\\ApiTools\\Configuration\\Factory\\ModuleUtilsFactory',
            'Laminas\\ApiTools\\OAuth2\\Adapter\\PdoAdapter' => 'Laminas\\ApiTools\\OAuth2\\Factory\\PdoAdapterFactory',
            'Laminas\\ApiTools\\OAuth2\\Adapter\\MongoAdapter' => 'Laminas\\ApiTools\\OAuth2\\Factory\\MongoAdapterFactory',
            'Laminas\\ApiTools\\OAuth2\\Provider\\UserId\\AuthenticationService' => 'Laminas\\ApiTools\\OAuth2\\Provider\\UserId\\AuthenticationServiceFactory',
            'Laminas\\ApiTools\\OAuth2\\Service\\OAuth2Server' => 'Laminas\\ApiTools\\MvcAuth\\Factory\\NamedOAuth2ServerFactory',
            'Laminas\\ApiTools\\MvcAuth\\Authentication' => 'Laminas\\ApiTools\\MvcAuth\\Factory\\AuthenticationServiceFactory',
            'Laminas\\ApiTools\\MvcAuth\\ApacheResolver' => 'Laminas\\ApiTools\\MvcAuth\\Factory\\ApacheResolverFactory',
            'Laminas\\ApiTools\\MvcAuth\\FileResolver' => 'Laminas\\ApiTools\\MvcAuth\\Factory\\FileResolverFactory',
            'Laminas\\ApiTools\\MvcAuth\\Authentication\\DefaultAuthenticationListener' => 'Laminas\\ApiTools\\MvcAuth\\Factory\\DefaultAuthenticationListenerFactory',
            'Laminas\\ApiTools\\MvcAuth\\Authentication\\AuthHttpAdapter' => 'Laminas\\ApiTools\\MvcAuth\\Factory\\DefaultAuthHttpAdapterFactory',
            'Laminas\\ApiTools\\MvcAuth\\Authorization\\AclAuthorization' => 'Laminas\\ApiTools\\MvcAuth\\Factory\\AclAuthorizationFactory',
            'Laminas\\ApiTools\\MvcAuth\\Authorization\\DefaultAuthorizationListener' => 'Laminas\\ApiTools\\MvcAuth\\Factory\\DefaultAuthorizationListenerFactory',
            'Laminas\\ApiTools\\MvcAuth\\Authorization\\DefaultResourceResolverListener' => 'Laminas\\ApiTools\\MvcAuth\\Factory\\DefaultResourceResolverListenerFactory',
            'Laminas\\Authentication\\Storage\\NonPersistent' => 'Laminas\\ServiceManager\\Factory\\InvokableFactory',
            'Laminas\\ApiTools\\MvcAuth\\Authentication\\DefaultAuthenticationPostListener' => 'Laminas\\ServiceManager\\Factory\\InvokableFactory',
            'Laminas\\ApiTools\\MvcAuth\\Authorization\\DefaultAuthorizationPostListener' => 'Laminas\\ServiceManager\\Factory\\InvokableFactory',
            'Laminas\\ApiTools\\Hal\\Extractor\\LinkExtractor' => 'Laminas\\ApiTools\\Hal\\Factory\\LinkExtractorFactory',
            'Laminas\\ApiTools\\Hal\\Extractor\\LinkCollectionExtractor' => 'Laminas\\ApiTools\\Hal\\Factory\\LinkCollectionExtractorFactory',
            'Laminas\\ApiTools\\Hal\\HalConfig' => 'Laminas\\ApiTools\\Hal\\Factory\\HalConfigFactory',
            'Laminas\\ApiTools\\Hal\\JsonRenderer' => 'Laminas\\ApiTools\\Hal\\Factory\\HalJsonRendererFactory',
            'Laminas\\ApiTools\\Hal\\JsonStrategy' => 'Laminas\\ApiTools\\Hal\\Factory\\HalJsonStrategyFactory',
            'Laminas\\ApiTools\\Hal\\Link\\LinkUrlBuilder' => 'Laminas\\ApiTools\\Hal\\Factory\\LinkUrlBuilderFactory',
            'Laminas\\ApiTools\\Hal\\MetadataMap' => 'Laminas\\ApiTools\\Hal\\Factory\\MetadataMapFactory',
            'Laminas\\ApiTools\\Hal\\RendererOptions' => 'Laminas\\ApiTools\\Hal\\Factory\\RendererOptionsFactory',
            'Laminas\\ApiTools\\ContentNegotiation\\ContentTypeListener' => 'Laminas\\ServiceManager\\Factory\\InvokableFactory',
            'Laminas\\ApiTools\\ContentNegotiation\\AcceptListener' => 'Laminas\\ApiTools\\ContentNegotiation\\Factory\\AcceptListenerFactory',
            'Laminas\\ApiTools\\ContentNegotiation\\AcceptFilterListener' => 'Laminas\\ApiTools\\ContentNegotiation\\Factory\\AcceptFilterListenerFactory',
            'Laminas\\ApiTools\\ContentNegotiation\\ContentTypeFilterListener' => 'Laminas\\ApiTools\\ContentNegotiation\\Factory\\ContentTypeFilterListenerFactory',
            'Laminas\\ApiTools\\ContentNegotiation\\ContentNegotiationOptions' => 'Laminas\\ApiTools\\ContentNegotiation\\Factory\\ContentNegotiationOptionsFactory',
            'Laminas\\ApiTools\\ContentNegotiation\\HttpMethodOverrideListener' => 'Laminas\\ApiTools\\ContentNegotiation\\Factory\\HttpMethodOverrideListenerFactory',
            'Laminas\\ApiTools\\ContentValidation\\ContentValidationListener' => 'Laminas\\ApiTools\\ContentValidation\\ContentValidationListenerFactory',
            'Laminas\\ApiTools\\Rest\\OptionsListener' => 'Laminas\\ApiTools\\Rest\\Factory\\OptionsListenerFactory',
            'Laminas\\ApiTools\\Rpc\\OptionsListener' => 'Laminas\\ApiTools\\Rpc\\Factory\\OptionsListenerFactory',
            'Laminas\\ApiTools\\Versioning\\AcceptListener' => 'Laminas\\ApiTools\\Versioning\\Factory\\AcceptListenerFactory',
            'Laminas\\ApiTools\\Versioning\\ContentTypeListener' => 'Laminas\\ApiTools\\Versioning\\Factory\\ContentTypeListenerFactory',
            'Laminas\\ApiTools\\Versioning\\VersionListener' => 'Laminas\\ServiceManager\\Factory\\InvokableFactory',
            'DatadogApi\\V1\\Rest\\DatadogRestService\\DatadogRestServiceResource' => 'DatadogApi\\V1\\Rest\\DatadogRestService\\DatadogRestServiceResourceFactory'
        ],
        'abstract_factories' => [
            'Laminas\\Db\\Adapter\\AdapterAbstractServiceFactory',
            'Laminas\\Db\\Adapter\\AdapterAbstractServiceFactory',
            'Laminas\\ApiTools\\DbConnectedResourceAbstractFactory',
            'Laminas\\ApiTools\\TableGatewayAbstractFactory'
        ],
        'invokables' => [
            'Laminas\\ApiTools\\Rest\\RestParametersListener' => 'Laminas\\ApiTools\\Rest\\Listener\\RestParametersListener'
        ]
    ],
    'filters' => [
        'aliases' => [
            'alnum' => 'Laminas\\I18n\\Filter\\Alnum',
            'Alnum' => 'Laminas\\I18n\\Filter\\Alnum',
            'alpha' => 'Laminas\\I18n\\Filter\\Alpha',
            'Alpha' => 'Laminas\\I18n\\Filter\\Alpha',
            'numberformat' => 'Laminas\\I18n\\Filter\\NumberFormat',
            'numberFormat' => 'Laminas\\I18n\\Filter\\NumberFormat',
            'NumberFormat' => 'Laminas\\I18n\\Filter\\NumberFormat',
            'numberparse' => 'Laminas\\I18n\\Filter\\NumberParse',
            'numberParse' => 'Laminas\\I18n\\Filter\\NumberParse',
            'NumberParse' => 'Laminas\\I18n\\Filter\\NumberParse',
            'Zend\\I18n\\Filter\\Alnum' => 'Laminas\\I18n\\Filter\\Alnum',
            'Zend\\I18n\\Filter\\Alpha' => 'Laminas\\I18n\\Filter\\Alpha',
            'Zend\\I18n\\Filter\\NumberFormat' => 'Laminas\\I18n\\Filter\\NumberFormat',
            'Zend\\I18n\\Filter\\NumberParse' => 'Laminas\\I18n\\Filter\\NumberParse'
        ],
        'factories' => [
            'Laminas\\I18n\\Filter\\Alnum' => 'Laminas\\ServiceManager\\Factory\\InvokableFactory',
            'Laminas\\I18n\\Filter\\Alpha' => 'Laminas\\ServiceManager\\Factory\\InvokableFactory',
            'Laminas\\I18n\\Filter\\NumberFormat' => 'Laminas\\ServiceManager\\Factory\\InvokableFactory',
            'Laminas\\I18n\\Filter\\NumberParse' => 'Laminas\\ServiceManager\\Factory\\InvokableFactory',
            'Laminas\\Filter\\File\\RenameUpload' => 'Laminas\\ApiTools\\ContentNegotiation\\Factory\\RenameUploadFilterFactory',
            'laminasfilterfilerenameupload' => 'Laminas\\ApiTools\\ContentNegotiation\\Factory\\RenameUploadFilterFactory'
        ]
    ],
    'validators' => [
        'aliases' => [
            'alnum' => 'Laminas\\I18n\\Validator\\Alnum',
            'Alnum' => 'Laminas\\I18n\\Validator\\Alnum',
            'alpha' => 'Laminas\\I18n\\Validator\\Alpha',
            'Alpha' => 'Laminas\\I18n\\Validator\\Alpha',
            'datetime' => 'Laminas\\I18n\\Validator\\DateTime',
            'dateTime' => 'Laminas\\I18n\\Validator\\DateTime',
            'DateTime' => 'Laminas\\I18n\\Validator\\DateTime',
            'float' => 'Laminas\\I18n\\Validator\\IsFloat',
            'Float' => 'Laminas\\I18n\\Validator\\IsFloat',
            'int' => 'Laminas\\I18n\\Validator\\IsInt',
            'Int' => 'Laminas\\I18n\\Validator\\IsInt',
            'isfloat' => 'Laminas\\I18n\\Validator\\IsFloat',
            'isFloat' => 'Laminas\\I18n\\Validator\\IsFloat',
            'IsFloat' => 'Laminas\\I18n\\Validator\\IsFloat',
            'isint' => 'Laminas\\I18n\\Validator\\IsInt',
            'isInt' => 'Laminas\\I18n\\Validator\\IsInt',
            'IsInt' => 'Laminas\\I18n\\Validator\\IsInt',
            'phonenumber' => 'Laminas\\I18n\\Validator\\PhoneNumber',
            'phoneNumber' => 'Laminas\\I18n\\Validator\\PhoneNumber',
            'PhoneNumber' => 'Laminas\\I18n\\Validator\\PhoneNumber',
            'postcode' => 'Laminas\\I18n\\Validator\\PostCode',
            'postCode' => 'Laminas\\I18n\\Validator\\PostCode',
            'PostCode' => 'Laminas\\I18n\\Validator\\PostCode',
            'Zend\\I18n\\Validator\\Alnum' => 'Laminas\\I18n\\Validator\\Alnum',
            'Zend\\I18n\\Validator\\Alpha' => 'Laminas\\I18n\\Validator\\Alpha',
            'Zend\\I18n\\Validator\\DateTime' => 'Laminas\\I18n\\Validator\\DateTime',
            'Zend\\I18n\\Validator\\IsFloat' => 'Laminas\\I18n\\Validator\\IsFloat',
            'Zend\\I18n\\Validator\\IsInt' => 'Laminas\\I18n\\Validator\\IsInt',
            'Zend\\I18n\\Validator\\PhoneNumber' => 'Laminas\\I18n\\Validator\\PhoneNumber',
            'Zend\\I18n\\Validator\\PostCode' => 'Laminas\\I18n\\Validator\\PostCode'
        ],
        'factories' => [
            'Laminas\\I18n\\Validator\\Alnum' => 'Laminas\\ServiceManager\\Factory\\InvokableFactory',
            'Laminas\\I18n\\Validator\\Alpha' => 'Laminas\\ServiceManager\\Factory\\InvokableFactory',
            'Laminas\\I18n\\Validator\\DateTime' => 'Laminas\\ServiceManager\\Factory\\InvokableFactory',
            'Laminas\\I18n\\Validator\\IsFloat' => 'Laminas\\ServiceManager\\Factory\\InvokableFactory',
            'Laminas\\I18n\\Validator\\IsInt' => 'Laminas\\ServiceManager\\Factory\\InvokableFactory',
            'Laminas\\I18n\\Validator\\PhoneNumber' => 'Laminas\\ServiceManager\\Factory\\InvokableFactory',
            'Laminas\\I18n\\Validator\\PostCode' => 'Laminas\\ServiceManager\\Factory\\InvokableFactory',
            'Laminas\\Validator\\File\\UploadFile' => 'Laminas\\ApiTools\\ContentNegotiation\\Factory\\UploadFileValidatorFactory',
            'laminasvalidatorfileuploadfile' => 'Laminas\\ApiTools\\ContentNegotiation\\Factory\\UploadFileValidatorFactory',
            'Laminas\\ApiTools\\ContentValidation\\Validator\\DbRecordExists' => 'Laminas\\ApiTools\\ContentValidation\\Validator\\Db\\RecordExistsFactory',
            'Laminas\\ApiTools\\ContentValidation\\Validator\\DbNoRecordExists' => 'Laminas\\ApiTools\\ContentValidation\\Validator\\Db\\NoRecordExistsFactory'
        ]
    ],
    'view_helpers' => [
        'aliases' => [
            'countryCodeDataList' => 'Laminas\\I18n\\View\\Helper\\CountryCodeDataList',
            'currencyformat' => 'Laminas\\I18n\\View\\Helper\\CurrencyFormat',
            'currencyFormat' => 'Laminas\\I18n\\View\\Helper\\CurrencyFormat',
            'CurrencyFormat' => 'Laminas\\I18n\\View\\Helper\\CurrencyFormat',
            'dateformat' => 'Laminas\\I18n\\View\\Helper\\DateFormat',
            'dateFormat' => 'Laminas\\I18n\\View\\Helper\\DateFormat',
            'DateFormat' => 'Laminas\\I18n\\View\\Helper\\DateFormat',
            'numberformat' => 'Laminas\\I18n\\View\\Helper\\NumberFormat',
            'numberFormat' => 'Laminas\\I18n\\View\\Helper\\NumberFormat',
            'NumberFormat' => 'Laminas\\I18n\\View\\Helper\\NumberFormat',
            'plural' => 'Laminas\\I18n\\View\\Helper\\Plural',
            'Plural' => 'Laminas\\I18n\\View\\Helper\\Plural',
            'translate' => 'Laminas\\I18n\\View\\Helper\\Translate',
            'Translate' => 'Laminas\\I18n\\View\\Helper\\Translate',
            'translateplural' => 'Laminas\\I18n\\View\\Helper\\TranslatePlural',
            'translatePlural' => 'Laminas\\I18n\\View\\Helper\\TranslatePlural',
            'TranslatePlural' => 'Laminas\\I18n\\View\\Helper\\TranslatePlural',
            'Zend\\I18n\\View\\Helper\\CurrencyFormat' => 'Laminas\\I18n\\View\\Helper\\CurrencyFormat',
            'Zend\\I18n\\View\\Helper\\DateFormat' => 'Laminas\\I18n\\View\\Helper\\DateFormat',
            'Zend\\I18n\\View\\Helper\\NumberFormat' => 'Laminas\\I18n\\View\\Helper\\NumberFormat',
            'Zend\\I18n\\View\\Helper\\Plural' => 'Laminas\\I18n\\View\\Helper\\Plural',
            'Zend\\I18n\\View\\Helper\\Translate' => 'Laminas\\I18n\\View\\Helper\\Translate',
            'Zend\\I18n\\View\\Helper\\TranslatePlural' => 'Laminas\\I18n\\View\\Helper\\TranslatePlural',
            'agacceptheaders' => 'Laminas\\ApiTools\\Documentation\\View\\AgAcceptHeaders',
            'agAcceptHeaders' => 'Laminas\\ApiTools\\Documentation\\View\\AgAcceptHeaders',
            'agcontenttypeheaders' => 'Laminas\\ApiTools\\Documentation\\View\\AgContentTypeHeaders',
            'agContentTypeHeaders' => 'Laminas\\ApiTools\\Documentation\\View\\AgContentTypeHeaders',
            'agservicepath' => 'Laminas\\ApiTools\\Documentation\\View\\AgServicePath',
            'agServicePath' => 'Laminas\\ApiTools\\Documentation\\View\\AgServicePath',
            'agstatuscodes' => 'Laminas\\ApiTools\\Documentation\\View\\AgStatusCodes',
            'agStatusCodes' => 'Laminas\\ApiTools\\Documentation\\View\\AgStatusCodes',
            'agtransformdescription' => 'Laminas\\ApiTools\\Documentation\\View\\AgTransformDescription',
            'agTransformDescription' => 'Laminas\\ApiTools\\Documentation\\View\\AgTransformDescription',
            'ZF\\Apigility\\Documentation\\View\\AgAcceptHeaders' => 'Laminas\\ApiTools\\Documentation\\View\\AgAcceptHeaders',
            'ZF\\Apigility\\Documentation\\View\\AgContentTypeHeaders' => 'Laminas\\ApiTools\\Documentation\\View\\AgContentTypeHeaders',
            'ZF\\Apigility\\Documentation\\View\\AgServicePath' => 'Laminas\\ApiTools\\Documentation\\View\\AgServicePath',
            'ZF\\Apigility\\Documentation\\View\\AgStatusCodes' => 'Laminas\\ApiTools\\Documentation\\View\\AgStatusCodes',
            'ZF\\Apigility\\Documentation\\View\\AgTransformDescription' => 'Laminas\\ApiTools\\Documentation\\View\\AgTransformDescription'
        ],
        'factories' => [
            'Laminas\\I18n\\View\\Helper\\CountryCodeDataList' => 'Laminas\\I18n\\View\\Helper\\Container\\CountryCodeDataListFactory',
            'Laminas\\I18n\\View\\Helper\\CurrencyFormat' => 'Laminas\\ServiceManager\\Factory\\InvokableFactory',
            'Laminas\\I18n\\View\\Helper\\DateFormat' => 'Laminas\\ServiceManager\\Factory\\InvokableFactory',
            'Laminas\\I18n\\View\\Helper\\NumberFormat' => 'Laminas\\ServiceManager\\Factory\\InvokableFactory',
            'Laminas\\I18n\\View\\Helper\\Plural' => 'Laminas\\ServiceManager\\Factory\\InvokableFactory',
            'Laminas\\I18n\\View\\Helper\\Translate' => 'Laminas\\ServiceManager\\Factory\\InvokableFactory',
            'Laminas\\I18n\\View\\Helper\\TranslatePlural' => 'Laminas\\ServiceManager\\Factory\\InvokableFactory',
            'Laminas\\ApiTools\\Documentation\\View\\AgAcceptHeaders' => 'Laminas\\ServiceManager\\Factory\\InvokableFactory',
            'Laminas\\ApiTools\\Documentation\\View\\AgContentTypeHeaders' => 'Laminas\\ServiceManager\\Factory\\InvokableFactory',
            'Laminas\\ApiTools\\Documentation\\View\\AgServicePath' => 'Laminas\\ServiceManager\\Factory\\InvokableFactory',
            'Laminas\\ApiTools\\Documentation\\View\\AgStatusCodes' => 'Laminas\\ServiceManager\\Factory\\InvokableFactory',
            'Laminas\\ApiTools\\Documentation\\View\\AgTransformDescription' => 'Laminas\\ServiceManager\\Factory\\InvokableFactory',
            'Hal' => 'Laminas\\ApiTools\\Hal\\Factory\\HalViewHelperFactory'
        ]
    ],
    'laminas-cli' => [
        'commands' => [
            'composer:autoload:disable' => 'Laminas\\ComposerAutoloading\\Command\\DisableCommand',
            'composer:autoload:enable' => 'Laminas\\ComposerAutoloading\\Command\\EnableCommand'
        ]
    ],
    'input_filters' => [
        'abstract_factories' => [
            'Laminas\\InputFilter\\InputFilterAbstractServiceFactory',
            'Laminas\\InputFilter\\InputFilterAbstractServiceFactory'
        ]
    ],
    'route_manager' => [],
    'router' => [
        'routes' => [
            'api-tools' => [
                'type' => 'literal',
                'options' => [
                    'route' => '/api-tools'
                ],
                'may_terminate' => false,
                'child_routes' => [
                    'documentation' => [
                        'type' => 'segment',
                        'options' => [
                            'route' => '/documentation[/:api[-v:version][/:service]]',
                            'constraints' => [
                                'api' => '[a-zA-Z][a-zA-Z0-9_.%]+'
                            ],
                            'defaults' => [
                                'controller' => 'Laminas\\ApiTools\\Documentation\\Controller',
                                'action' => 'show'
                            ]
                        ]
                    ]
                ]
            ],
            'oauth' => [
                'type' => 'literal',
                'options' => [
                    'route' => '/oauth',
                    'defaults' => [
                        'controller' => 'Laminas\\ApiTools\\OAuth2\\Controller\\Auth',
                        'action' => 'token'
                    ]
                ],
                'may_terminate' => true,
                'child_routes' => [
                    'revoke' => [
                        'type' => 'literal',
                        'options' => [
                            'route' => '/revoke',
                            'defaults' => [
                                'action' => 'revoke'
                            ]
                        ]
                    ],
                    'authorize' => [
                        'type' => 'literal',
                        'options' => [
                            'route' => '/authorize',
                            'defaults' => [
                                'action' => 'authorize'
                            ]
                        ]
                    ],
                    'resource' => [
                        'type' => 'literal',
                        'options' => [
                            'route' => '/resource',
                            'defaults' => [
                                'action' => 'resource'
                            ]
                        ]
                    ],
                    'code' => [
                        'type' => 'literal',
                        'options' => [
                            'route' => '/receivecode',
                            'defaults' => [
                                'action' => 'receiveCode'
                            ]
                        ]
                    ]
                ]
            ],
            'home' => [
                'type' => 'Literal',
                'options' => [
                    'route' => '/',
                    'defaults' => [
                        'controller' => 'Application\\Controller\\IndexController',
                        'action' => 'index'
                    ]
                ]
            ],
            'datadog-api.rest.datadog-rest-service' => [
                'type' => 'Segment',
                'options' => [
                    'route' => '[/v:version]/datadog-rest-service[/:datadog_rest_service_id]',
                    'defaults' => [
                        'controller' => 'DatadogApi\\V1\\Rest\\DatadogRestService\\Controller',
                        'version' => 1
                    ],
                    'constraints' => [
                        'version' => '\\d+'
                    ]
                ]
            ]
        ]
    ],
    'asset_manager' => [
        'resolver_configs' => [
            'paths' => [
                '/home/circleci/app/tests/Frameworks/Laminas/ApiTools/Latest/vendor/laminas-api-tools/api-tools/config/../asset'
            ]
        ]
    ],
    'api-tools' => [
        'db-connected' => []
    ],
    'controllers' => [
        'aliases' => [
            'ZF\\Apigility\\Documentation\\Controller' => 'Laminas\\ApiTools\\Documentation\\Controller',
            'ZF\\OAuth2\\Controller\\Auth' => 'Laminas\\ApiTools\\OAuth2\\Controller\\Auth'
        ],
        'factories' => [
            'Laminas\\ApiTools\\Documentation\\Controller' => 'Laminas\\ApiTools\\Documentation\\ControllerFactory',
            'Laminas\\ApiTools\\OAuth2\\Controller\\Auth' => 'Laminas\\ApiTools\\OAuth2\\Factory\\AuthControllerFactory',
            'Application\\Controller\\IndexController' => 'Laminas\\ServiceManager\\Factory\\InvokableFactory'
        ],
        'abstract_factories' => [
            'Laminas\\ApiTools\\Rest\\Factory\\RestControllerFactory',
            'Laminas\\ApiTools\\Rpc\\Factory\\RpcControllerFactory'
        ]
    ],
    'api-tools-content-negotiation' => [
        'controllers' => [
            'Laminas\\ApiTools\\Documentation\\Controller' => 'Documentation',
            'Laminas\\ApiTools\\OAuth2\\Controller\\Auth' => [
                'Laminas\\ApiTools\\ContentNegotiation\\JsonModel' => [
                    'application/json',
                    'application/*+json'
                ],
                'Laminas\\View\\Model\\ViewModel' => [
                    'text/html',
                    'application/xhtml+xml'
                ]
            ],
            'DatadogApi\\V1\\Rest\\DatadogRestService\\Controller' => 'HalJson'
        ],
        'accept_whitelist' => [
            'Laminas\\ApiTools\\Documentation\\Controller' => [
                'application/vnd.swagger+json',
                'application/json'
            ],
            'DatadogApi\\V1\\Rest\\DatadogRestService\\Controller' => [
                'application/vnd.datadog-api.v1+json',
                'application/hal+json',
                'application/json'
            ]
        ],
        'selectors' => [
            'Documentation' => [
                'Laminas\\View\\Model\\ViewModel' => [
                    'text/html',
                    'application/xhtml+xml'
                ],
                'Laminas\\ApiTools\\Documentation\\JsonModel' => [
                    'application/json'
                ]
            ],
            'HalJson' => [
                'Laminas\\ApiTools\\Hal\\View\\HalJsonModel' => [
                    'application/json',
                    'application/*+json'
                ]
            ],
            'Json' => [
                'Laminas\\ApiTools\\ContentNegotiation\\JsonModel' => [
                    'application/json',
                    'application/*+json'
                ]
            ]
        ],
        'content_type_whitelist' => [
            'DatadogApi\\V1\\Rest\\DatadogRestService\\Controller' => [
                'application/vnd.datadog-api.v1+json',
                'application/json'
            ]
        ],
        'x_http_method_override_enabled' => false,
        'http_override_methods' => []
    ],
    'view_manager' => [
        'template_path_stack' => [
            '/home/circleci/app/tests/Frameworks/Laminas/ApiTools/Latest/vendor/laminas-api-tools/api-tools-documentation/config/../view',
            '/home/circleci/app/tests/Frameworks/Laminas/ApiTools/Latest/vendor/laminas-api-tools/api-tools-oauth2/config/../view',
            '/home/circleci/app/tests/Frameworks/Laminas/ApiTools/Latest/module/Application/config/../view'
        ],
        'display_exceptions' => true,
        'template_map' => [
            'oauth/authorize' => '/home/circleci/app/tests/Frameworks/Laminas/ApiTools/Latest/vendor/laminas-api-tools/api-tools-oauth2/config/../view/laminas/auth/authorize.phtml',
            'oauth/receive-code' => '/home/circleci/app/tests/Frameworks/Laminas/ApiTools/Latest/vendor/laminas-api-tools/api-tools-oauth2/config/../view/laminas/auth/receive-code.phtml',
            'layout/layout' => '/home/circleci/app/tests/Frameworks/Laminas/ApiTools/Latest/module/Application/config/../view/layout/layout.phtml',
            'application/index/index' => '/home/circleci/app/tests/Frameworks/Laminas/ApiTools/Latest/module/Application/config/../view/application/index/index.phtml',
            'error/404' => '/home/circleci/app/tests/Frameworks/Laminas/ApiTools/Latest/module/Application/config/../view/error/404.phtml',
            'error/index' => '/home/circleci/app/tests/Frameworks/Laminas/ApiTools/Latest/module/Application/config/../view/error/index.phtml'
        ],
        'display_not_found_reason' => true,
        'doctype' => 'HTML5',
        'not_found_template' => 'error/404',
        'exception_template' => 'error/index',
        'strategies' => [
            'ViewJsonStrategy'
        ]
    ],
    'api-tools-api-problem' => [],
    'api-tools-configuration' => [
        'config_file' => 'config/autoload/development.php'
    ],
    'api-tools-oauth2' => [
        'grant_types' => [
            'client_credentials' => true,
            'authorization_code' => true,
            'password' => true,
            'refresh_token' => true,
            'jwt' => true
        ],
        'api_problem_error_response' => true
    ],
    'controller_plugins' => [
        'aliases' => [
            'getidentity' => 'Laminas\\ApiTools\\MvcAuth\\Identity\\IdentityPlugin',
            'getIdentity' => 'Laminas\\ApiTools\\MvcAuth\\Identity\\IdentityPlugin',
            'ZF\\MvcAuth\\Identity\\IdentityPlugin' => 'Laminas\\ApiTools\\MvcAuth\\Identity\\IdentityPlugin',
            'routeParam' => 'Laminas\\ApiTools\\ContentNegotiation\\ControllerPlugin\\RouteParam',
            'queryParam' => 'Laminas\\ApiTools\\ContentNegotiation\\ControllerPlugin\\QueryParam',
            'bodyParam' => 'Laminas\\ApiTools\\ContentNegotiation\\ControllerPlugin\\BodyParam',
            'routeParams' => 'Laminas\\ApiTools\\ContentNegotiation\\ControllerPlugin\\RouteParams',
            'queryParams' => 'Laminas\\ApiTools\\ContentNegotiation\\ControllerPlugin\\QueryParams',
            'bodyParams' => 'Laminas\\ApiTools\\ContentNegotiation\\ControllerPlugin\\BodyParams',
            'getinputfilter' => 'Laminas\\ApiTools\\ContentValidation\\InputFilter\\InputFilterPlugin',
            'getInputfilter' => 'Laminas\\ApiTools\\ContentValidation\\InputFilter\\InputFilterPlugin',
            'getInputFilter' => 'Laminas\\ApiTools\\ContentValidation\\InputFilter\\InputFilterPlugin',
            'ZF\\ContentValidation\\InputFilter\\InputFilterPlugin' => 'Laminas\\ApiTools\\ContentValidation\\InputFilter\\InputFilterPlugin'
        ],
        'factories' => [
            'Laminas\\ApiTools\\MvcAuth\\Identity\\IdentityPlugin' => 'Laminas\\ServiceManager\\Factory\\InvokableFactory',
            'Hal' => 'Laminas\\ApiTools\\Hal\\Factory\\HalControllerPluginFactory',
            'Laminas\\ApiTools\\ContentNegotiation\\ControllerPlugin\\RouteParam' => 'Laminas\\ServiceManager\\Factory\\InvokableFactory',
            'Laminas\\ApiTools\\ContentNegotiation\\ControllerPlugin\\QueryParam' => 'Laminas\\ServiceManager\\Factory\\InvokableFactory',
            'Laminas\\ApiTools\\ContentNegotiation\\ControllerPlugin\\BodyParam' => 'Laminas\\ServiceManager\\Factory\\InvokableFactory',
            'Laminas\\ApiTools\\ContentNegotiation\\ControllerPlugin\\RouteParams' => 'Laminas\\ServiceManager\\Factory\\InvokableFactory',
            'Laminas\\ApiTools\\ContentNegotiation\\ControllerPlugin\\QueryParams' => 'Laminas\\ServiceManager\\Factory\\InvokableFactory',
            'Laminas\\ApiTools\\ContentNegotiation\\ControllerPlugin\\BodyParams' => 'Laminas\\ServiceManager\\Factory\\InvokableFactory',
            'Laminas\\ApiTools\\ContentValidation\\InputFilter\\InputFilterPlugin' => 'Laminas\\ServiceManager\\Factory\\InvokableFactory'
        ]
    ],
    'api-tools-mvc-auth' => [
        'authentication' => [],
        'authorization' => [
            'deny_by_default' => false
        ]
    ],
    'api-tools-hal' => [
        'renderer' => [],
        'metadata_map' => [
            'DatadogApi\\V1\\Rest\\DatadogRestService\\DatadogRestServiceEntity' => [
                'entity_identifier_name' => 'id',
                'route_name' => 'datadog-api.rest.datadog-rest-service',
                'route_identifier_name' => 'datadog_rest_service_id',
                'hydrator' => 'Laminas\\Hydrator\\ArraySerializableHydrator'
            ],
            'DatadogApi\\V1\\Rest\\DatadogRestService\\DatadogRestServiceCollection' => [
                'entity_identifier_name' => 'id',
                'route_name' => 'datadog-api.rest.datadog-rest-service',
                'route_identifier_name' => 'datadog_rest_service_id',
                'is_collection' => true
            ]
        ],
        'options' => [
            'use_proxy' => false
        ]
    ],
    'input_filter_specs' => [],
    'api-tools-content-validation' => [
        'methods_without_bodies' => []
    ],
    'api-tools-rest' => [
        'DatadogApi\\V1\\Rest\\DatadogRestService\\Controller' => [
            'listener' => 'DatadogApi\\V1\\Rest\\DatadogRestService\\DatadogRestServiceResource',
            'route_name' => 'datadog-api.rest.datadog-rest-service',
            'route_identifier_name' => 'datadog_rest_service_id',
            'collection_name' => 'datadog_rest_service',
            'entity_http_methods' => [
                'GET',
                'PATCH',
                'PUT',
                'DELETE'
            ],
            'collection_http_methods' => [
                'GET',
                'POST'
            ],
            'collection_query_whitelist' => [],
            'page_size' => 25,
            'page_size_param' => null,
            'entity_class' => 'DatadogApi\\V1\\Rest\\DatadogRestService\\DatadogRestServiceEntity',
            'collection_class' => 'DatadogApi\\V1\\Rest\\DatadogRestService\\DatadogRestServiceCollection',
            'service_name' => 'DatadogRestService'
        ]
    ],
    'api-tools-rpc' => [],
    'api-tools-versioning' => [
        'content-type' => [],
        'default_version' => 1,
        'uri' => [
            'datadog-api.rest.datadog-rest-service'
        ]
    ],
    'db' => [
        'adapters' => [
            'dummy' => []
        ]
    ]
];
